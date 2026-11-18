<?php

namespace Database\Factories;

use App\Enums\VideoStatus;
use App\Models\Video;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<Video> */
class VideoFactory extends Factory
{
    public function definition(): array
    {
        return [
            'provider' => 'bunny',
            'provider_video_id' => (string) Str::uuid(),
            'title' => fake()->sentence(3),
            'status' => VideoStatus::Ready,
            'duration_seconds' => fake()->numberBetween(120, 1800),
        ];
    }

    public function processing(): static
    {
        return $this->state(fn () => ['status' => VideoStatus::Processing, 'duration_seconds' => null]);
    }
}
