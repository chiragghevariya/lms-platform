<?php

namespace App\Http\Controllers\Api\V1\Instructor;

use App\Courses\CourseWorkflow;
use App\Courses\CurriculumService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Courses\CourseRequest;
use App\Http\Resources\CourseCardResource;
use App\Http\Resources\InstructorCourseResource;
use App\Models\Category;
use App\Models\Course;
use App\Models\Tag;
use App\Models\User;
use App\Models\Video;
use App\Rules\CleanUpload;
use App\Support\ImageVariants;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class CourseController extends Controller
{
    /** Courses I own or co-teach (admins: ?all=1 for everything). */
    public function index(Request $request): AnonymousResourceCollection
    {
        /** @var User $user */
        $user = $request->user();

        $courses = Course::query()->with('instructor', 'category')
            ->when(! ($request->boolean('all') && $user->isStaff()), fn ($q) => $q->where(fn ($w) => $w
                ->where('instructor_id', $user->id)
                ->orWhereHas('coInstructors', fn ($c) => $c->whereKey($user->id))))
            ->latest('updated_at')->paginate(30);

        return CourseCardResource::collection($courses);
    }

    public function store(CourseRequest $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $course = new Course(['instructor_id' => $user->id]);
        $this->fill($course, $request->validated());
        $course->slug = $this->uniqueSlug($course->title);
        $course->save();
        $this->syncTags($course, $request->validated('tags'));

        return (new InstructorCourseResource($this->loaded($course)))->response()->setStatusCode(201);
    }

    public function show(Course $course): InstructorCourseResource
    {
        $this->authorize('view', $course);

        return new InstructorCourseResource($this->loaded($course));
    }

    public function update(CourseRequest $request, Course $course): InstructorCourseResource
    {
        $this->fill($course, $request->validated());
        $course->save();
        if ($request->has('tags')) {
            $this->syncTags($course, $request->validated('tags'));
        }
        if ($course->isPublished()) {
            $course->searchable();
        }

        return new InstructorCourseResource($this->loaded($course));
    }

    public function destroy(Course $course): Response
    {
        $this->authorize('delete', $course);
        $course->unsearchable();
        $course->delete();

        return response()->noContent();
    }

    public function thumbnail(Request $request, Course $course): InstructorCourseResource
    {
        $this->authorize('update', $course);
        $request->validate(['thumbnail' => ['required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:4096', 'dimensions:min_width=640,min_height=360', new CleanUpload]]);

        $disk = Storage::disk((string) config('video.thumbnails_disk'));
        $path = $disk->putFileAs("courses/{$course->uuid}", $request->file('thumbnail'), Str::random(20).'.'.$request->file('thumbnail')->guessExtension(), ['visibility' => 'public']);
        $variants = [];
        try {
            $variants = app(ImageVariants::class)->make($request->file('thumbnail')->getRealPath(), $disk, "courses/{$course->uuid}/".Str::random(12));
        } catch (\Throwable $e) {
            report($e); // the original still works; only the responsive variants are missing
        }
        if ($course->thumbnail_path) {
            $disk->delete([$course->thumbnail_path, ...array_values($course->thumbnail_variants ?? [])]);
        }
        $course->forceFill(['thumbnail_path' => $path ?: null, 'thumbnail_variants' => $variants ?: null])->save();

        return new InstructorCourseResource($this->loaded($course));
    }

    public function submit(Course $course, CourseWorkflow $workflow): InstructorCourseResource
    {
        $this->authorize('submit', $course);

        return new InstructorCourseResource($this->loaded($workflow->submit($course)));
    }

    /** Drag-and-drop result: [{id: section uuid, lessons: [lesson uuid, ...]}, ...] */
    public function reorder(Request $request, Course $course, CurriculumService $curriculum): InstructorCourseResource
    {
        $this->authorize('update', $course);
        $data = $request->validate([
            'sections' => ['present', 'array'],
            'sections.*.id' => ['required', 'uuid'],
            'sections.*.lessons' => ['present', 'array'],
            'sections.*.lessons.*' => ['uuid'],
        ]);
        /** @var list<array{id: string, lessons: list<string>}> $layout */
        $layout = $data['sections'];
        $curriculum->reorder($course, $layout);

        return new InstructorCourseResource($this->loaded($course));
    }

    /** @param array<string, mixed> $data */
    private function fill(Course $course, array $data): void
    {
        $course->fill(Arr::except($data, ['category_id', 'promo_video_id', 'tags']));
        if (array_key_exists('category_id', $data)) {
            $course->category_id = $data['category_id'] ? Category::where('uuid', $data['category_id'])->value('id') : null;
        }
        if (array_key_exists('promo_video_id', $data)) {
            $course->promo_video_id = $data['promo_video_id'] ? Video::where('uuid', $data['promo_video_id'])->value('id') : null;
        }
    }

    /** @param list<string>|null $names */
    private function syncTags(Course $course, ?array $names): void
    {
        if ($names === null) {
            return;
        }
        $ids = collect($names)->map(fn ($n) => trim($n))->filter()->unique()
            ->map(fn ($n) => Tag::firstOrCreate(['slug' => Str::slug($n)], ['name' => $n])->id);
        $course->tags()->sync($ids);
    }

    private function uniqueSlug(string $title): string
    {
        $base = Str::slug($title) ?: 'course';
        $slug = $base;
        for ($i = 2; Course::withTrashed()->where('slug', $slug)->exists(); $i++) {
            $slug = "{$base}-{$i}";
        }

        return $slug;
    }

    private function loaded(Course $course): Course
    {
        return $course->load(['instructor', 'category', 'tags', 'promoVideo', 'sections.lessons.video', 'sections.lessons.resources']);
    }
}
