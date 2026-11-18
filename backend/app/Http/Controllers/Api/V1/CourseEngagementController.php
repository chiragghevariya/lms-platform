<?php

namespace App\Http\Controllers\Api\V1;

use App\Courses\CourseAccessService;
use App\Courses\CoursePurchaseService;
use App\Http\Controllers\Controller;
use App\Http\Resources\CourseReviewResource;
use App\Models\Course;
use App\Models\CourseReview;
use App\Models\Enrollment;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class CourseEngagementController extends Controller
{
    public function purchase(Request $request, string $slug, CoursePurchaseService $purchases): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $course = Course::published()->where('slug', $slug)->firstOrFail();

        return response()->json(['data' => $purchases->checkout($user, $course)->toArray()], 201);
    }

    /** One review per learner; only people who can access and have started the course. */
    public function review(Request $request, string $slug, CourseAccessService $access): CourseReviewResource
    {
        $data = $request->validate([
            'rating' => ['required', 'integer', 'between:1,5'],
            'comment' => ['nullable', 'string', 'max:2000'],
        ]);
        /** @var User $user */
        $user = $request->user();
        $course = Course::published()->where('slug', $slug)->firstOrFail();

        abort_unless(
            $access->canAccessCourse($user, $course) && ! $access->canManage($user, $course)
                && Enrollment::where('user_id', $user->id)->where('course_id', $course->id)->exists(),
            403,
            'Start the course before reviewing it.',
        );

        $review = DB::transaction(function () use ($course, $user, $data) {
            $review = CourseReview::updateOrCreate(['course_id' => $course->id, 'user_id' => $user->id], $data);
            $course->refreshRating();

            return $review;
        });

        return new CourseReviewResource($review->load('user'));
    }
}
