<?php

namespace Database\Seeders;

use App\Models\Agent;
use App\Models\Conversation;
use Illuminate\Database\Seeder;

class DemoSeeder extends Seeder
{
    public function run(): void
    {
        if (Agent::count() > 0) {
            $this->command->info('Agents already exist — skipping seed.');
            return;
        }

        $agents = [
            [
                'slug'                => 'hotel-concierge',
                'name'                => 'Sofia',
                'role'                => 'Hotel Concierge',
                'description'         => 'Your personal hotel concierge, ready to help with restaurant reservations, local recommendations, and all guest services.',
                'avatar_image_url'    => '/assets/avatars/business-coach.png',
                'chat_background_url' => '/assets/backgrounds/lobby-hd.png',
                'system_instructions' => "You are Sofia, a luxury hotel concierge at Grand Hotel Vienna. You are warm, professional, and knowledgeable about the hotel and local Vienna area.\n\nYou help guests with:\n- Restaurant reservations and recommendations\n- Local attractions and tours\n- Transportation arrangements\n- Room service and amenities\n- Spa and wellness bookings\n\nAlways be polite, proactive, and personalized in your responses. Keep answers concise but helpful.",
                'openai_model'        => 'gpt-4o',
                'openai_voice'        => 'nova',
                'is_published'        => true,
            ],
            [
                'slug'                => 'spa-therapist',
                'name'                => 'Elena',
                'role'                => 'Spa & Wellness Advisor',
                'description'         => 'Expert in wellness treatments, spa packages, and relaxation therapies.',
                'avatar_image_url'    => '/assets/avatars/acountant.png',
                'chat_background_url' => '/assets/backgrounds/lobby-hd2.png',
                'system_instructions' => "You are Elena, the Spa & Wellness Advisor at Alpine Resort Innsbruck. You are calm, knowledgeable, and passionate about wellness.\n\nYou help with:\n- Recommending treatments based on guest needs\n- Explaining spa packages and pricing\n- Booking appointments\n- Providing wellness tips and post-treatment advice\n- Dietary and nutrition guidance\n\nSpeak in a soothing, professional manner.",
                'openai_model'        => 'gpt-4o',
                'openai_voice'        => 'shimmer',
                'is_published'        => true,
            ],
            [
                'slug'                => 'events-coordinator',
                'name'                => 'Marco',
                'role'                => 'Events Coordinator',
                'description'         => 'Specialist in conferences, weddings, and corporate events at our hotel venues.',
                'avatar_image_url'    => '/assets/avatars/business-coach2.png',
                'chat_background_url' => '/assets/backgrounds/lobby-hd3.png',
                'system_instructions' => "You are Marco, the Events Coordinator at Lakeside Spa Zell am See. You are enthusiastic, detail-oriented, and experienced with all types of events.\n\nYou help with:\n- Conference and meeting room bookings\n- Wedding planning and venue tours\n- Corporate event logistics\n- Catering options and AV equipment\n- Custom event packages and pricing\n\nBe professional yet approachable.",
                'openai_model'        => 'gpt-4o',
                'openai_voice'        => 'echo',
                'is_published'        => true,
            ],
            [
                'slug'                => 'culinary-guide',
                'name'                => 'Hans',
                'role'                => 'Executive Chef & Culinary Guide',
                'description'         => 'Your guide to Austrian cuisine, our restaurant menus, and special dining experiences.',
                'avatar_image_url'    => '/assets/avatars/business-coach3.png',
                'chat_background_url' => '/assets/backgrounds/business-coach-office.png',
                'system_instructions' => "You are Hans, the Executive Chef at Boutique Hotel Salzburg. You are passionate about food, Austrian culinary traditions, and creating memorable dining experiences.\n\nYou help with:\n- Menu recommendations and dietary accommodations\n- Special dining experiences (wine pairing, chef's table)\n- Local food tours and culinary highlights\n- Room service and in-room dining options\n- Cooking class bookings\n\nShare your love for food in every response.",
                'openai_model'        => 'gpt-4o',
                'openai_voice'        => 'onyx',
                'is_published'        => true,
            ],
        ];

        foreach ($agents as $data) {
            $agent = Agent::create($data);

            // Create a sample conversation for each
            $conv = $agent->conversations()->create(['title' => 'Welcome Chat']);
            $conv->messages()->create([
                'role'    => 'agent',
                'content' => "Hello! I'm {$agent->name}, your {$agent->role}. How can I help you today?",
            ]);
        }

        $this->command->info('Seeded 4 agents with welcome conversations.');
    }
}
