<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\UserProfile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UserProfileTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_have_profile_with_wellness_goals(): void
    {
        $user = User::factory()->create([
            'birthdate' => '1990-05-01',
            'jurisdiction' => 'DE',
            'consent_json' => ['gdpr' => true, 'marketing' => false],
            'locale' => 'en',
        ]);

        UserProfile::create([
            'user_id' => $user->id,
            'goals' => ['sleep', 'energy'],
            'conditions' => [],
            'medications' => [],
            'dietary_flags' => ['vegetarian'],
            'wearables_connected' => ['oura' => true],
        ]);

        $user->refresh();

        $this->assertSame('DE', $user->jurisdiction);
        $this->assertTrue($user->consent_json['gdpr']);
        $this->assertContains('sleep', $user->profile->goals);
    }
}
