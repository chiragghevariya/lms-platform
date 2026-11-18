<?php

namespace App\Http\Controllers\Api\V1\Instructor;

use App\Courses\CurriculumService;
use App\Enums\LessonType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Courses\LessonRequest;
use App\Http\Resources\InstructorCourseResource;
use App\Models\Course;
use App\Models\CourseSection;
use App\Models\Lesson;
use App\Models\LessonResource;
use App\Models\Video;
use App\Rules\CleanUpload;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/** Sections, lessons, PDF attachments and downloadable resources. Every write returns the updated course. */
class CurriculumController extends Controller
{
    public function storeSection(Request $request, Course $course): InstructorCourseResource
    {
        $this->authorize('update', $course);
        $data = $request->validate(['title' => ['required', 'string', 'max:160']]);
        $course->sections()->create(['title' => $data['title'], 'sort' => (int) $course->sections()->max('sort') + 1]);

        return $this->respond($course);
    }

    public function updateSection(Request $request, CourseSection $section): InstructorCourseResource
    {
        $this->authorize('update', $section->course);
        $section->update($request->validate(['title' => ['required', 'string', 'max:160']]));

        return $this->respond($section->course);
    }

    public function destroySection(CourseSection $section): InstructorCourseResource
    {
        $course = $section->course;
        $this->authorize('update', $course);
        $section->delete();
        $course->refreshStats();

        return $this->respond($course);
    }

    public function storeLesson(LessonRequest $request, CourseSection $section, CurriculumService $curriculum): InstructorCourseResource
    {
        $course = $section->course;
        $this->authorize('update', $course);

        $lesson = new Lesson(['course_id' => $course->id, 'section_id' => $section->id, 'sort' => $curriculum->nextSort($section)]);
        $this->fill($lesson, $request->validated());
        $lesson->save();
        $course->refreshStats();

        return $this->respond($course);
    }

    public function updateLesson(LessonRequest $request, Lesson $lesson): InstructorCourseResource
    {
        $this->authorize('update', $lesson->course);
        $this->fill($lesson, $request->validated());
        $lesson->save();
        $lesson->course->refreshStats();

        return $this->respond($lesson->course);
    }

    public function destroyLesson(Lesson $lesson): InstructorCourseResource
    {
        $course = $lesson->course;
        $this->authorize('update', $course);
        $lesson->delete();
        $course->refreshStats();

        return $this->respond($course);
    }

    public function attachment(Request $request, Lesson $lesson): InstructorCourseResource
    {
        $this->authorize('update', $lesson->course);
        $request->validate(['file' => ['required', 'file', 'mimes:pdf', 'max:51200', new CleanUpload]]);

        $disk = Storage::disk((string) config('video.resources_disk'));
        if ($lesson->attachment_path) {
            $disk->delete($lesson->attachment_path);
        }
        $path = $disk->putFileAs("courses/{$lesson->course->uuid}/lessons", $request->file('file'), Str::random(24).'.pdf');
        $lesson->forceFill(['attachment_path' => $path ?: null])->save();

        return $this->respond($lesson->course);
    }

    public function storeResource(Request $request, Lesson $lesson): InstructorCourseResource
    {
        $this->authorize('update', $lesson->course);
        $data = $request->validate([
            'file' => ['required', 'file', 'max:102400', 'mimes:pdf,zip,docx,xlsx,pptx,csv,txt,png,jpg,jpeg,psd,fig,mp3', new CleanUpload],
            'title' => ['nullable', 'string', 'max:160'],
        ]);
        $file = $request->file('file');
        $diskName = (string) config('video.resources_disk');
        $path = Storage::disk($diskName)->putFileAs("courses/{$lesson->course->uuid}/resources", $file, Str::random(24).'.'.($file->guessExtension() ?? $file->getClientOriginalExtension()));

        $lesson->resources()->create([
            'title' => $data['title'] ?? pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME),
            'disk' => $diskName,
            'path' => (string) $path,
            'mime' => $file->getMimeType(),
            'size' => $file->getSize() ?: 0,
        ]);

        return $this->respond($lesson->course);
    }

    public function destroyResource(LessonResource $resource): InstructorCourseResource
    {
        $course = $resource->lesson->course;
        $this->authorize('update', $course);
        Storage::disk($resource->disk)->delete($resource->path);
        $resource->delete();

        return $this->respond($course);
    }

    /** @param array<string, mixed> $data */
    private function fill(Lesson $lesson, array $data): void
    {
        $videoUuid = $data['video_id'] ?? null;
        $lesson->fill(collect($data)->except('video_id')->all());

        if (array_key_exists('video_id', $data)) {
            $video = $videoUuid ? Video::where('uuid', $videoUuid)->first() : null;
            $lesson->video_id = $video?->id;
            if ($video?->duration_seconds) {
                $lesson->duration_seconds = $video->duration_seconds;
            }
        }
        if ($lesson->type !== LessonType::Video) {
            $lesson->video_id = null;
        }
    }

    private function respond(Course $course): InstructorCourseResource
    {
        return new InstructorCourseResource($course->fresh(['instructor', 'category', 'tags', 'promoVideo', 'sections.lessons.video', 'sections.lessons.resources']) ?? $course);
    }
}
