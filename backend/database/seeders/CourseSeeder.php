<?php

namespace Database\Seeders;

use App\Enums\CourseLevel;
use App\Enums\CourseStatus;
use App\Enums\LessonType;
use App\Enums\VideoStatus;
use App\Models\Category;
use App\Models\Course;
use App\Models\Lesson;
use App\Models\Tag;
use App\Models\User;
use App\Models\Video;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * Demo catalogue: categories + published courses by the seeded instructors.
 * Videos use the `demo` provider (public test stream) so the player works without Bunny.
 */
class CourseSeeder extends Seeder
{
    private const CATEGORIES = ['Development', 'Marketing', 'Design', 'Creator Economy', 'Business'];

    /** [instructor email, title, category, tier, level, featured, price (INR minor) | null] */
    private const COURSES = [
        ['instructor@example.com', 'Freelance Web Development Bootcamp', 'Development', 1, 'beginner', true, 199900],
        ['instructor@example.com', 'Laravel APIs for Paying Clients', 'Development', 2, 'intermediate', false, null],
        ['meera@example.com', 'Performance Marketing with Meta Ads', 'Marketing', 2, 'intermediate', true, 299900],
        ['meera@example.com', 'Email Marketing that Converts', 'Marketing', 1, 'beginner', false, null],
        ['arjun@example.com', 'YouTube Growth & Video Editing', 'Creator Economy', 2, 'all_levels', true, null],
        ['arjun@example.com', 'Build a Personal Brand that Pays', 'Business', 3, 'advanced', true, 499900],
        ['meera@example.com', 'Canva Design for Social Media', 'Design', null, 'beginner', false, null],
    ];

    public function run(): void
    {
        foreach (self::CATEGORIES as $i => $name) {
            Category::firstOrCreate(['slug' => Str::slug($name)], ['name' => $name, 'sort' => $i]);
        }

        foreach (self::COURSES as [$email, $title, $category, $tier, $level, $featured, $price]) {
            $instructor = User::where('email', $email)->first();
            if (! $instructor || Course::where('slug', Str::slug($title))->exists()) {
                continue;
            }

            $course = new Course([
                'instructor_id' => $instructor->id,
                'category_id' => Category::where('name', $category)->value('id'),
                'title' => $title,
                'subtitle' => 'A practical, project-based course to help you earn with new skills.',
                'description' => '<p>In this course you will build real projects step by step.</p><ul><li>Hands-on lessons</li><li>Downloadable resources</li><li>Certificate on completion</li></ul>',
                'level' => CourseLevel::from($level),
                'language' => 'en',
                'required_tier' => $tier,
                'is_featured' => $featured,
                'price_amount' => $price,
                'currency' => $price ? 'INR' : null,
            ]);
            $course->slug = Str::slug($title);
            $course->forceFill(['status' => CourseStatus::Published, 'published_at' => now()->subDays(random_int(1, 60)), 'rating_avg' => random_int(40, 50) / 10, 'rating_count' => random_int(3, 120)])->save();
            $course->tags()->sync(collect(explode(' ', strtolower($title)))->take(2)->map(fn ($t) => Tag::firstOrCreate(['slug' => Str::slug($t)], ['name' => $t])->id));

            foreach (['Getting started', 'Core skills', 'Your first paid project'] as $s => $sectionTitle) {
                $section = $course->sections()->create(['title' => $sectionTitle, 'sort' => $s]);
                foreach (range(1, 3) as $n) {
                    $isVideo = $n !== 3;
                    $video = $isVideo ? Video::create([
                        'uploaded_by' => $instructor->id, 'provider' => 'demo', 'provider_video_id' => 'demo-'.Str::lower(Str::random(10)),
                        'title' => "{$sectionTitle} {$n}", 'status' => VideoStatus::Ready, 'duration_seconds' => 634,
                    ]) : null;

                    Lesson::create([
                        'course_id' => $course->id,
                        'section_id' => $section->id,
                        'title' => $isVideo ? "{$sectionTitle}: part {$n}" : "{$sectionTitle}: notes & checklist",
                        'type' => $isVideo ? LessonType::Video : LessonType::Text,
                        'video_id' => $video?->id,
                        'content' => '<p>Key takeaways from this lesson, with a checklist you can apply today.</p>',
                        'duration_seconds' => $isVideo ? 634 : 300,
                        'is_preview' => $s === 0 && $n === 1,
                        'sort' => $n,
                        'drip_days' => $s === 2 ? 7 : 0, // last section drips a week after starting
                    ]);
                }
            }
            $course->refreshStats();
        }
    }
}
