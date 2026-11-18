<?php

namespace Database\Factories;

use App\Enums\CourseLevel;
use App\Enums\CourseStatus;
use App\Enums\LessonType;
use App\Models\Category;
use App\Models\Course;
use App\Models\Lesson;
use App\Models\User;
use App\Models\Video;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<Course> */
class CourseFactory extends Factory
{
    /** published_at is guarded; accept it as a factory attribute. */
    public function newModel(array $attributes = [])
    {
        $model = parent::newModel($attributes);
        if (isset($attributes['published_at'])) {
            $model->forceFill(['published_at' => $attributes['published_at']]);
        }

        return $model;
    }

    public function definition(): array
    {
        $title = rtrim(fake()->unique()->sentence(4), '.');

        return [
            'instructor_id' => User::factory()->instructor(),
            'category_id' => Category::factory(),
            'title' => $title,
            'slug' => Str::slug($title).'-'.Str::lower(Str::random(4)),
            'subtitle' => fake()->sentence(),
            'description' => '<p>'.fake()->paragraph().'</p>',
            'level' => fake()->randomElement(CourseLevel::cases()),
            'language' => 'en',
            'thumbnail_path' => 'courses/demo/thumb.jpg',
            'required_tier' => 2,
        ];
    }

    public function published(): static
    {
        // status / published_at aren't mass-assignable, so set them after making — without
        // overriding an explicit published_at passed to create().
        return $this->afterMaking(fn (Course $c) => $c->forceFill([
            'status' => CourseStatus::Published,
            'published_at' => $c->published_at ?? now()->subDay(),
        ]));
    }

    public function tier(?int $level): static
    {
        return $this->state(fn () => ['required_tier' => $level]);
    }

    /**
     * One section with N video lessons (first one a free preview).
     *
     * @param  array<string, mixed>  $lessonAttrs
     */
    public function withLessons(int $count = 3, array $lessonAttrs = []): static
    {
        return $this->afterCreating(function (Course $course) use ($count, $lessonAttrs) {
            $section = $course->sections()->create(['title' => 'Getting started', 'sort' => 0]);
            for ($i = 0; $i < $count; $i++) {
                Lesson::create([
                    'course_id' => $course->id,
                    'section_id' => $section->id,
                    'title' => 'Lesson '.($i + 1),
                    'type' => LessonType::Video,
                    'video_id' => Video::factory()->create()->id,
                    'duration_seconds' => 600,
                    'is_preview' => $i === 0,
                    'sort' => $i,
                    ...$lessonAttrs,
                ]);
            }
            $course->refreshStats();
        });
    }
}
