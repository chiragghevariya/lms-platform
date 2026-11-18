<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Courses\CourseWorkflow;
use App\Enums\CourseStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\CourseCardResource;
use App\Http\Resources\InstructorCourseResource;
use App\Models\Course;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

class CourseController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('review', Course::class);
        $data = $request->validate(['status' => ['nullable', Rule::enum(CourseStatus::class)], 'q' => ['nullable', 'string', 'max:100']]);

        return CourseCardResource::collection(
            Course::with('instructor', 'category')
                ->when($data['status'] ?? null, fn ($q, $s) => $q->where('status', $s))
                ->when($data['q'] ?? null, fn ($q, $s) => $q->where('title', 'like', "%{$s}%"))
                ->orderByRaw("CASE status WHEN 'review' THEN 0 ELSE 1 END")->latest('submitted_at')->latest('id')
                ->paginate(30)
        );
    }

    public function approve(Request $request, Course $course, CourseWorkflow $workflow): InstructorCourseResource
    {
        $this->authorize('review', Course::class);
        /** @var User $admin */
        $admin = $request->user();

        activity('admin')->causedBy($admin)->performedOn($course)->event('course.approved')->log('Approved course');

        return new InstructorCourseResource($workflow->approve($course, $admin)->load('sections.lessons.video', 'sections.lessons.resources', 'instructor', 'category', 'tags'));
    }

    public function reject(Request $request, Course $course, CourseWorkflow $workflow): InstructorCourseResource
    {
        $this->authorize('review', Course::class);
        $data = $request->validate(['notes' => ['required', 'string', 'min:10', 'max:5000']]);

        activity('admin')->causedBy($request->user())->performedOn($course)->event('course.rejected')
            ->withProperties(['notes' => $data['notes']])->log('Sent course back for changes');

        return new InstructorCourseResource($workflow->reject($course, $data['notes'])->load('sections.lessons.video', 'sections.lessons.resources', 'instructor', 'category', 'tags'));
    }

    public function update(Request $request, Course $course, CourseWorkflow $workflow): CourseCardResource
    {
        $this->authorize('review', Course::class);
        $data = $request->validate(['is_featured' => ['sometimes', 'boolean'], 'unpublish' => ['sometimes', 'accepted']]);

        if (isset($data['is_featured'])) {
            $course->forceFill(['is_featured' => $data['is_featured']])->save();
            activity('admin')->causedBy($request->user())->performedOn($course)->event('course.featured')
                ->withProperties(['featured' => (bool) $data['is_featured']])->log($data['is_featured'] ? 'Featured course' : 'Unfeatured course');
        }
        if (isset($data['unpublish'])) {
            $workflow->unpublish($course);
            activity('admin')->causedBy($request->user())->performedOn($course)->event('course.unpublished')->log('Unpublished course');
        }

        return new CourseCardResource($course->load('instructor', 'category'));
    }
}
