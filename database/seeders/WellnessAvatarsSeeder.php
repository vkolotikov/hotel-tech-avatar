<?php

namespace Database\Seeders;

use App\Models\Agent;
use App\Models\Vertical;
use App\Support\Avatars\WellnessAvatarConfigs;
use Illuminate\Database\Seeder;

/**
 * Seeds the six wellness-vertical avatars described in docs/PROJECT_SPEC.md.
 *
 * Idempotent: if an avatar with a matching slug or name already exists,
 * it is left alone. The seeder will only fill in the missing ones, so it
 * is safe to re-run in production without overwriting super-admin edits.
 *
 * Full persona / scope / red-flag / handoff / knowledge-source config
 * lives in App\Support\Avatars\WellnessAvatarConfigs and is shared with
 * the matching data-migration so the two never drift.
 */
class WellnessAvatarsSeeder extends Seeder
{
    public function run(): void
    {
        $wellness = Vertical::where('slug', 'wellness')->first();
        if (!$wellness) {
            $this->command?->warn('Wellness vertical not found — run VerticalsSeeder first.');
            return;
        }

        $configs = WellnessAvatarConfigs::all();

        foreach ($this->identities() as $data) {
            $exists = Agent::where('slug', $data['slug'])
                ->orWhere('name', $data['name'])
                ->exists();

            if ($exists) {
                $this->command?->line("Skipping existing avatar: {$data['name']} ({$data['slug']})");
                continue;
            }

            $payload = array_merge(
                [
                    'vertical_id'  => $wellness->id,
                    'is_published' => true,
                    // gpt-4o is the known-good default on our OpenAI account.
                    // Flip to gpt-5.4 here (or override per-avatar via admin)
                    // once that family is available on your tier.
                    'openai_model' => 'gpt-4o',
                ],
                $data,
                $configs[$data['slug']] ?? [],
            );

            Agent::create($payload);

            $this->command?->info("Seeded wellness avatar: {$data['name']}");
        }
    }

    /**
     * Identity + visual fields for each avatar. Rule sets + persona +
     * knowledge sources come from WellnessAvatarConfigs.
     *
     * @return array<int,array<string,mixed>>
     */
    private function identities(): array
    {
        return [
            [
                'slug'                => 'integra',
                'name'                => 'Dr. Integra',
                'role'                => 'Functional Medicine Guide',
                'domain'              => 'functional_medicine',
                'description'         => 'Your guide to chronic-condition education, lab literacy, and supplement/drug-interaction awareness. Educational — not a doctor.',
                'avatar_image_url'    => '/assets/avatars/business-coach1.png',
                'chat_background_url' => '/assets/backgrounds/business-coach-office.png',
                'intro_video_url'     => '/assets/avatars/videos/Integra.mp4',
                'openai_voice'        => 'onyx',
            ],
            [
                'slug'                => 'nora',
                'name'                => 'Nora',
                'role'                => 'Nutrition & Gut Health',
                'domain'              => 'nutrition',
                'description'         => 'Plain-English nutrition and gut-health education — food labels, meal composition, ingredient awareness. Not a dietitian.',
                'avatar_image_url'    => '/assets/avatars/marketing-expert1.png',
                'chat_background_url' => '/assets/backgrounds/business-coach-office.png',
                'intro_video_url'     => '/assets/avatars/videos/Nora.mp4',
                'openai_voice'        => 'nova',
            ],
            [
                'slug'                => 'luna',
                'name'                => 'Luna',
                'role'                => 'Sleep Guide',
                'domain'              => 'sleep',
                'description'         => 'Sleep-hygiene and CBT-I-style education — wind-down routines, circadian rhythm, caffeine and light.',
                'avatar_image_url'    => '/assets/avatars/copywriter.png',
                'chat_background_url' => '/assets/backgrounds/business-coach-office.png',
                'intro_video_url'     => '/assets/avatars/videos/Luna.mp4',
                'openai_voice'        => 'shimmer',
            ],
            [
                'slug'                => 'zen',
                'name'                => 'Zen',
                'role'                => 'Mindfulness & Emotional Balance',
                'domain'              => 'mindfulness',
                'description'         => 'Mindfulness, breathwork, and emotion-regulation education. A calm companion — not a therapist.',
                'avatar_image_url'    => '/assets/avatars/acountant.png',
                'chat_background_url' => '/assets/backgrounds/business-coach-office.png',
                'intro_video_url'     => '/assets/avatars/videos/Zen.mp4',
                'openai_voice'        => 'sage',
            ],
            [
                'slug'                => 'axel',
                'name'                => 'Axel',
                'role'                => 'Fitness & Longevity',
                'domain'              => 'fitness',
                'description'         => 'Strength, cardio, and movement education with a longevity lens. Not a substitute for a physical therapist or doctor.',
                'avatar_image_url'    => '/assets/avatars/business-coach2.png',
                'chat_background_url' => '/assets/backgrounds/business-coach-office.png',
                'intro_video_url'     => '/assets/avatars/videos/Axel.mp4',
                'openai_voice'        => 'echo',
            ],
            [
                'slug'                => 'aura',
                'name'                => 'Aura',
                'role'                => 'Skin & Beauty',
                'domain'              => 'beauty',
                'description'         => 'Skincare ingredients, routine design, and know-when-to-see-a-dermatologist education. Not a dermatologist.',
                'avatar_image_url'    => '/assets/avatars/e-mail-manager.png',
                'chat_background_url' => '/assets/backgrounds/business-coach-office.png',
                'intro_video_url'     => '/assets/avatars/videos/Aura.mp4',
                'openai_voice'        => 'coral',
            ],
        ];
    }
}
