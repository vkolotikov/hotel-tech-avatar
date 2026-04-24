# RevenueCat

**Status:** `backend wired — mobile SDK pending`
**Last verified:** 2026-04-24
**Official docs:** https://www.revenuecat.com/docs

## What we use it for

Mobile in-app purchase + subscription lifecycle for the WellnessAI mobile app. We do **not** build StoreKit / Play Billing handling from scratch — RevenueCat normalises Apple & Google receipts, webhooks, and entitlements. Maps directly onto our vendor-neutral billing columns (`billing_provider='revenuecat'`, `billing_customer_id`, `billing_metadata`).

## Endpoints this project calls

- **Mobile SDK** _(pending — commit 2 of Track C)_: `Purchases.configure({ apiKey, appUserID })`, `Purchases.getCustomerInfo()`, `Purchases.purchase(product)` — client-side only. `appUserID` MUST be set to our `User.id` (stringified) so our webhook controller can look the user up.
- **Server webhook receiver — live**: `POST /api/v1/webhooks/revenuecat` (see `app/Http/Controllers/Api/V1/RevenueCatWebhookController.php`). Handles:
  - `INITIAL_PURCHASE`, `RENEWAL`, `PRODUCT_CHANGE`, `UNCANCELLATION` → status=`active`
  - `CANCELLATION` → status=`cancelled` (user keeps entitlement until `renews_at`)
  - `EXPIRATION` → status=`expired` + downgrade to `free` plan
  - `BILLING_ISSUE` → status=`in_grace_period`
  - Any other event → logged, no state change
- **Server REST** _(future, for reconciliation)_: `GET /v1/subscribers/{app_user_id}` — not used by v1; webhooks are the source of truth.

## Authentication

- **Mobile SDK**: public SDK key (safe to ship in the app bundle)
- **REST API**: secret API key in `REVENUECAT_SECRET_API_KEY` env var
- **Webhook verification**: `REVENUECAT_WEBHOOK_AUTH_HEADER` — shared Authorization header value RevenueCat sends, verified on every webhook request

## Error handling expectations

- **Auth**: missing / mismatched `Authorization` header → 401. RevenueCat retries with backoff; fix the secret and the retries recover.
- **Unknown `app_user_id`**: logged + 200 OK (don't 4xx — otherwise RC retries forever for a user that doesn't exist our side).
- **Unknown plan slug** (event referenced a product not in our `subscription_plans`): logged at ERROR + 422. Operator adds the plan and RC retries.
- **Idempotency**: we upsert the entitlement by `user_id` (unique). Repeated deliveries of the same event are safe; `billing_metadata.last_event_id` lets us audit whether we've seen it before.
- **Out-of-order**: entitlement row holds the latest known state. If an old event arrives after a newer one, it'll overwrite state — acceptable for v1 since RC delivers events in order within a product subscription. Revisit if we see observed out-of-order cases in Langfuse/Sentry.
- **Chargebacks / fraud**: Apple/Google send `EXPIRATION` or `CANCELLATION`, which we handle. Manual refunds in RC dashboard → `CANCELLATION` event.

## Cost and quota notes

- Pricing: https://www.revenuecat.com/pricing — free tier covers up to $10k MTR (monthly tracked revenue), then 1% over.
- Costs are revenue-driven, not per-call — no quota enforcement needed.

## Project-specific notes

- **Never trust the mobile client** as the source of entitlement truth. Always reconcile via webhook or REST before granting premium access. The mobile SDK is a UX convenience, not the security boundary.
- Entitlement state lives in our `subscription_entitlements` table — RevenueCat is the upstream event source, we are the system of record.
- `subscription_plans.slug` ↔ RevenueCat `product_id` mapping is configuration, stored in `billing_metadata`.
- RevenueCat Experiments / A/B testing for paywalls is a future option; not in scope for v1.0.

## Operator checklist

Before the first mobile paywall can be tested end-to-end, the following must be configured in the RevenueCat dashboard + our env:

1. **RevenueCat project** created with iOS + Android apps registered.
2. **Entitlement** named `premium` (matches `config/services.php :: revenuecat.entitlement_plan_map`).
3. **Products** named `premium_monthly` + `premium_annual` attached to the `premium` entitlement.
4. **Webhook URL** set to `https://avatars.hotel-tech.ai/api/v1/webhooks/revenuecat`.
5. **Webhook Authorization header**: generate a random secret (e.g. `openssl rand -hex 32`), paste into RC's webhook config, AND into Laravel Cloud env as `REVENUECAT_WEBHOOK_AUTH_HEADER`.
6. **Mobile SDK key** (public, per-platform) → copied into mobile app as `EXPO_PUBLIC_REVENUECAT_API_KEY_IOS` / `EXPO_PUBLIC_REVENUECAT_API_KEY_ANDROID` (commit 2).
7. **REST secret API key** → `REVENUECAT_SECRET_API_KEY` (not used by v1 but set now for future reconciliation calls).

## Change log

- 2026-04-24 — backend wired: webhook receiver, entitlement upsert, free-tier daily-message gate on wellness conversations. `/api/v1/me` now returns subscription info. Mobile SDK integration is the next slice.
- 2026-04-20 — stub created as part of Phase 0 integrations scaffold
