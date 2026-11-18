<?php

namespace Database\Factories;

use App\Enums\LiveProviderType;
use App\Enums\LiveSessionStatus;
use App\Enums\LiveSessionType;
use App\Models\LiveSession;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<LiveSession> */
class LiveSessionFactory extends Factory
{
    protected $model = LiveSession::class;

    public function definition(): array
    {
        return [
            'instructor_id' => User::factory()->instructor(),
            'title' => fake()->sentence(4),
            'description' => fake()->paragraph(),
            'type' => LiveSessionType::Class_,
            'required_tier' => null,
            'starts_at' => now()->addDays(2)->startOfHour(),
            'duration' => 60,
            'timezone' => 'Asia/Kolkata',
            'capacity' => null,
            'provider' => LiveProviderType::ExternalLink,
            'join_url' => 'https://meet.google.com/abc-defg-hij',
            'status' => LiveSessionStatus::Scheduled,
        ];
    }

    public function zoom(string $meetingId = '8811223344'): static
    {
        return $this->state([
            'provider' => LiveProviderType::Zoom, 'meeting_id' => $meetingId,
            'join_url' => "https://zoom.us/j/{$meetingId}?pwd=x", 'host_url' => "https://zoom.us/s/{$meetingId}", 'meeting_password' => 'p4ss',
        ]);
    }

    public function tier(int $tier): static
    {
        return $this->state(['required_tier' => $tier]);
    }
}
