<?php

namespace App\Courses;

use App\Models\Course;
use App\Models\CourseSection;
use App\Models\Lesson;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CurriculumService
{
    /**
     * Applies a drag-and-drop result: sections in order, each with its lessons in order.
     * Lessons may move between sections. Every section/lesson of the course must be listed once.
     *
     * @param  list<array{id: string, lessons: list<string>}>  $layout
     */
    public function reorder(Course $course, array $layout): void
    {
        $sections = $course->sections()->get()->keyBy('uuid');
        $lessons = $course->lessons()->get()->keyBy('uuid');

        $sectionIds = array_column($layout, 'id');
        $lessonIds = array_merge(...array_map(fn ($s) => $s['lessons'], $layout ?: [['lessons' => []]]));

        if (count($sectionIds) !== count(array_unique($sectionIds)) || array_diff($sectionIds, $sections->keys()->all()) || count($sectionIds) !== $sections->count()) {
            throw ValidationException::withMessages(['sections' => 'The layout must list every section of this course exactly once.']);
        }
        if (count($lessonIds) !== count(array_unique($lessonIds)) || array_diff($lessonIds, $lessons->keys()->all()) || count($lessonIds) !== $lessons->count()) {
            throw ValidationException::withMessages(['sections' => 'The layout must list every lesson of this course exactly once.']);
        }

        DB::transaction(function () use ($layout, $sections, $lessons) {
            foreach ($layout as $i => $item) {
                /** @var CourseSection $section */
                $section = $sections[$item['id']];
                $section->forceFill(['sort' => $i])->save();

                foreach ($item['lessons'] as $j => $lessonId) {
                    /** @var Lesson $lesson */
                    $lesson = $lessons[$lessonId];
                    $lesson->forceFill(['section_id' => $section->id, 'sort' => $j])->save();
                }
            }
        });
    }

    public function nextSort(CourseSection $section): int
    {
        return (int) $section->lessons()->max('sort') + 1;
    }
}
