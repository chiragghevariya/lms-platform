<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\Course;
use App\Models\CourseReview;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Course review moderation: hidden reviews leave the public list and the course rating. */
class ReviewController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $f = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'rating' => ['nullable', 'integer', 'between:1,5'],
            'max_rating' => ['nullable', 'integer', 'between:1,5'],
            'state' => ['nullable', 'in:visible,hidden'],
            'course' => ['nullable', 'string'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $page = CourseReview::query()->with('user:id,uuid,name,email', 'course:id,uuid,title,slug')
            ->when($f['search'] ?? null, function ($q, $s) {
                $like = '%'.addcslashes((string) $s, '%_\\').'%';
                $q->where(fn ($q) => $q->where('comment', 'like', $like)->orWhereHas('user', fn ($q) => $q->where('name', 'like', $like)->orWhere('email', 'like', $like)));
            })
            ->when($f['rating'] ?? null, fn ($q, $v) => $q->where('rating', $v))
            ->when($f['max_rating'] ?? null, fn ($q, $v) => $q->where('rating', '<=', $v))
            ->when(($f['state'] ?? null) === 'hidden', fn ($q) => $q->whereNotNull('hidden_at'))
            ->when(($f['state'] ?? null) === 'visible', fn ($q) => $q->whereNull('hidden_at'))
            ->when($f['course'] ?? null, fn ($q, $uuid) => $q->whereHas('course', fn ($q) => $q->where('uuid', $uuid)))
            ->latest('id')->paginate($f['per_page'] ?? 25)->withQueryString();

        return response()->json([
            'data' => $page->getCollection()->map(fn (CourseReview $r) => $this->present($r))->values(),
            'meta' => ['total' => $page->total(), 'current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'per_page' => $page->perPage()],
        ]);
    }

    public function hide(Request $request, CourseReview $review): JsonResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:500']]);
        /** @var User $admin */
        $admin = $request->user();
        $review->forceFill(['hidden_at' => now(), 'hidden_by' => $admin->id, 'hidden_reason' => $data['reason']])->save();
        $this->refresh($review);
        activity('admin')->causedBy($admin)->performedOn($review)->event('review.hidden')->withProperties(['reason' => $data['reason']])->log('Hid review');

        return response()->json(['data' => $this->present($review->load('user', 'course'))]);
    }

    public function unhide(Request $request, CourseReview $review): JsonResponse
    {
        $review->forceFill(['hidden_at' => null, 'hidden_by' => null, 'hidden_reason' => null])->save();
        $this->refresh($review);
        activity('admin')->causedBy($request->user())->performedOn($review)->event('review.restored')->log('Restored review');

        return response()->json(['data' => $this->present($review->load('user', 'course'))]);
    }

    public function destroy(Request $request, CourseReview $review): JsonResponse
    {
        activity('admin')->causedBy($request->user())->event('review.deleted')
            ->withProperties(['course_id' => $review->course_id, 'user_id' => $review->user_id, 'rating' => $review->rating, 'comment' => $review->comment])
            ->log('Deleted review');
        $review->delete();
        $this->refresh($review);

        return response()->json(null, 204);
    }

    private function refresh(CourseReview $review): void
    {
        Course::find($review->course_id)?->refreshRating();
    }

    /** @return array<string, mixed> */
    private function present(CourseReview $r): array
    {
        return [
            'id' => $r->uuid,
            'rating' => $r->rating,
            'comment' => $r->comment,
            'hidden' => $r->hidden_at !== null,
            'hidden_reason' => $r->hidden_reason,
            'hidden_at' => $r->hidden_at?->toIso8601String(),
            'user' => ['id' => $r->user->uuid, 'name' => $r->user->name, 'email' => $r->user->email],
            'course' => ['id' => $r->course->uuid, 'title' => $r->course->title, 'slug' => $r->course->slug],
            'created_at' => $r->created_at->toIso8601String(),
        ];
    }
}
