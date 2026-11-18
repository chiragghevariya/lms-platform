<?php

namespace Database\Factories;

use App\Enums\LessonType;
use App\Models\Lesson;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Lesson> */
class LessonFactory extends Factory
{
    public function definition(): array
    {
        return ['title' => fake()->sentence(3), 'type' => LessonType::Text, 'content' => '<p>'.fake()->paragraph().'</p>'];
    }
}
