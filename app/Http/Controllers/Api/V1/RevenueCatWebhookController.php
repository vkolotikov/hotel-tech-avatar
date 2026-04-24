<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\SubscriptionEntitlement;
use App\Models\SubscriptionPlan;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Receives RevenueCat subscription lifecycle events and reconciles our
 * `subscription_entitlements` rows against them.
 *
 * RevenueCat is the source of truth for WHAT the user has bought;
 * we are the source of truth for WHAT THAT MEANS in our product
 * (which features they can use, how many messages they get per day).
 * Webhooks are the sync channel between the two.
 *
 * Events handled:
 *   INITIAL_PURCHASE, RENEWAL, PRODUCT_CHANGE, UNCANCELLATION → active
 *   CANCELLATION                                              → cancelled (still entitled until renews_at)
 *   EXPIRATION                                                → expired (downgrade to free)
 *   BILLING_ISSUE                                             → in_grace_period (still entitled)
 *   NON_RENEWING_PURCHASE, SUBSCRIPTION_PAUSED, TRANSFER      → logged, no state change
 *
 * The mobile SDK is a UX convenience only — never the security boundary.
 * The client cannot mark itself premium; only a verified webhook can.
 */
final class RevenueCatWebhookController extends Controller
{
    public function handle(Request $request): JsonResponse
    {
        // Verify the request actually came from RevenueCat. They send a
        // shared-secret Authorization header we configure in their
        // dashboard; constant-time comparison avoids timing leaks.
        $expected = (string) config('services.revenuecat.webhook_auth_header', '');
        $got      = (string) $request->header('Authorization', '');
        if ($expected === '' || !hash_equals($expected, $got)) {
            Log::warning('RevenueCat webhook: auth header mismatch', [
                'ip' => $request->ip(),
            ]);
            return response()->json(['message' => 'Unauthorised'], 401);
        }

        $event = $request->input('event', []);
        $type  = (string) ($event['type'] ?? '');
        $appUserId = (string) ($event['app_user_id'] ?? '');
        if ($type === '' || $appUserId === '') {
            Log::warning('RevenueCat webhook: malformed event', [
                'payload_keys' => array_keys($request->all()),
            ]);
            return response()->json(['message' => 'Malformed event'], 422);
        }

        // We tell the mobile SDK to set app_user_id = our User id. Anything
        // else means the SDK wasn't configured correctly on that device, or
        // RC sent us someone else's event — ignore rather than guess.
        $user = User::find((int) $appUserId);
        if (!$user) {
            Log::warning('RevenueCat webhook: unknown app_user_id', [
                'app_user_id' => $appUserId,
                'event_type'  => $type,
            ]);
            // Still ack with 200 so RC doesn't retry forever — the user
            // genuinely doesn't exist on our side.
            return response()->json(['message' => 'User not found, event ignored'], 200);
        }

        $planSlug = $this->resolvePlanSlug($event);
        $plan     = SubscriptionPlan::where('slug', $planSlug)->first();
        if (!$plan) {
            Log::error('RevenueCat webhook: plan slug not found', [
                'plan_slug' => $planSlug,
                'event_type' => $type,
                'user_id'   => $user->id,
            ]);
            return response()->json(['message' => 'Plan not found'], 422);
        }

        $status = $this->statusForEventType($type);
        if ($status === null) {
            // Event is one we don't currently act on (TRANSFER, PAUSED, etc).
            Log::info('RevenueCat webhook: event type not actionable', [
                'event_type' => $type,
                'user_id'    => $user->id,
            ]);
            return response()->json(['message' => 'Event logged, no state change'], 200);
        }

        // On expiration we downgrade to the configured fallback plan
        // (free by default) rather than leaving them pointed at a
        // premium plan with status=expired — gating reads plan_id first.
        $targetPlan = $status === 'expired'
            ? SubscriptionPlan::where('slug', (string) config('services.revenuecat.default_plan_slug', 'free'))->first() ?? $plan
            : $plan;

        $renewsAtMs   = $event['expiration_at_ms'] ?? null;
        $renewsAt     = is_numeric($renewsAtMs) ? now()->setTimestampMs((int) $renewsAtMs) : null;
        $rcCustomerId = (string) ($event['original_app_user_id'] ?? $appUserId);

        $entitlement = SubscriptionEntitlement::firstOrNew(['user_id' => $user->id]);
        $entitlement->plan_id             = $targetPlan->id;
        $entitlement->status              = $status;
        $entitlement->renews_at           = $renewsAt;
        $entitlement->billing_provider    = 'revenuecat';
        $entitlement->billing_customer_id = $rcCustomerId;
        $entitlement->billing_metadata    = array_merge(
            is_array($entitlement->billing_metadata) ? $entitlement->billing_metadata : [],
            [
                'last_event_type'       => $type,
                'last_event_id'         => $event['id'] ?? null,
                'last_event_ts_ms'      => $event['event_timestamp_ms'] ?? null,
                'product_id'            => $event['product_id'] ?? null,
                'period_type'           => $event['period_type'] ?? null,
                'store'                 => $event['store'] ?? null,
                'environment'           => $event['environment'] ?? null,
            ],
        );
        $entitlement->save();

        Log::info('RevenueCat webhook: entitlement updated', [
            'user_id'     => $user->id,
            'event_type'  => $type,
            'plan_slug'   => $targetPlan->slug,
            'status'      => $status,
            'renews_at'   => optional($renewsAt)->toIso8601String(),
        ]);

        return response()->json(['message' => 'Entitlement updated'], 200);
    }

    /**
     * Resolve the subscription plan slug this event applies to. Prefers
     * the first entitlement_id in the event payload, mapped via config;
     * falls back to the product_id matching a plan slug directly.
     */
    private function resolvePlanSlug(array $event): string
    {
        $map = (array) config('services.revenuecat.entitlement_plan_map', []);

        $entitlementIds = (array) ($event['entitlement_ids'] ?? []);
        foreach ($entitlementIds as $entId) {
            $key = (string) $entId;
            if (isset($map[$key])) {
                return (string) $map[$key];
            }
        }

        $productId = (string) ($event['product_id'] ?? '');
        if ($productId !== '' && isset($map[$productId])) {
            return (string) $map[$productId];
        }

        // Last-resort fallback: assume product_id equals a plan slug —
        // the simple case where ops named the RC product the same as
        // the wellness plan.
        return $productId !== '' ? $productId : 'premium';
    }

    /**
     * Map a RevenueCat event type to the status we store on our
     * entitlement row, or null if the event isn't actionable for
     * entitlement state (and we should just log it).
     */
    private function statusForEventType(string $type): ?string
    {
        return match ($type) {
            'INITIAL_PURCHASE',
            'RENEWAL',
            'PRODUCT_CHANGE',
            'UNCANCELLATION' => 'active',
            'CANCELLATION'   => 'cancelled',
            'EXPIRATION'     => 'expired',
            'BILLING_ISSUE'  => 'in_grace_period',
            default          => null,
        };
    }
}
