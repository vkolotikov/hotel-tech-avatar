<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    protected $fillable = [
        'name',
        'email',
        'password',
        'birthdate',
        'jurisdiction',
        'locale',
        'consent_json',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'birthdate' => 'date',
            'consent_json' => 'array',
        ];
    }

    public function profile(): HasOne
    {
        return $this->hasOne(UserProfile::class);
    }

    public function entitlement(): HasOne
    {
        return $this->hasOne(SubscriptionEntitlement::class);
    }

    public function conversations(): HasMany
    {
        return $this->hasMany(Conversation::class);
    }

    /**
     * The plan the user is currently entitled to. Falls back to the
     * 'free' plan when an entitlement row exists but has expired, or
     * when no entitlement exists at all — callers can treat a non-null
     * return as the authoritative source for gating checks.
     */
    public function activePlan(): ?SubscriptionPlan
    {
        $entitlement = $this->entitlement;
        if ($entitlement && in_array($entitlement->status, ['active', 'in_grace_period', 'trialing'], true)) {
            return $entitlement->plan;
        }
        // Expired / cancelled / missing entitlement → free tier.
        return SubscriptionPlan::where('slug', 'free')->first();
    }

    public function hasPremium(): bool
    {
        $plan = $this->activePlan();
        return $plan?->slug === 'premium';
    }

    /**
     * Count of user-role messages this user has sent across all of
     * their conversations today (local server date). Used by the
     * free-tier daily-limit check; premium users never hit this path.
     */
    public function messagesUsedToday(): int
    {
        return Message::query()
            ->whereIn('conversation_id', $this->conversations()->select('id'))
            ->where('role', 'user')
            ->whereDate('created_at', now()->toDateString())
            ->count();
    }

    /**
     * Sum of generation tokens (prompt + completion) consumed by this
     * user over the current rolling 30-day window. Counts only
     * `purpose = 'generation'` rows on llm_calls — verification,
     * claim extraction, structured review, revision, TTS, STT all
     * stay our overhead and are NOT deducted from the user's budget.
     *
     * Rolling 30 days (rather than calendar month or billing-cycle-
     * anchored) gives consistent UX across free + paid tiers and
     * avoids edge-case "you have 0 tokens but reset is tomorrow"
     * dead-zones at month boundaries.
     */
    public function tokensUsedThisPeriod(): int
    {
        $since = $this->tokenPeriodStart();

        return (int) \DB::table('llm_calls')
            ->join('messages', 'llm_calls.message_id', '=', 'messages.id')
            ->join('conversations', 'messages.conversation_id', '=', 'conversations.id')
            ->where('conversations.user_id', $this->id)
            ->where('llm_calls.purpose', 'generation')
            ->where('llm_calls.created_at', '>=', $since)
            ->sum(\DB::raw('COALESCE(llm_calls.prompt_tokens, 0) + COALESCE(llm_calls.completion_tokens, 0)'));
    }

    /**
     * Start of the current 30-day token-budget window. Exposed so
     * callers (notably AuthController::me()) can render a reset-date
     * hint to the user.
     */
    public function tokenPeriodStart(): \Carbon\Carbon
    {
        return now()->subDays(30);
    }
}
