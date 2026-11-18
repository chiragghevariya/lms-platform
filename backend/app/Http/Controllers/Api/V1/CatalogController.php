<?php

namespace App\Http\Controllers\Api\V1;

use App\Courses\CatalogCache;
use App\Courses\CatalogQuery;
use App\Courses\CourseAccessService;
use App\Enums\CourseLevel;
use App\Http\Controllers\Controller;
use App\Http\Resources\CategoryResource;
use App\Http\Resources\CourseCardResource;
use App\Http\Resources\CourseDetailResource;
use App\Http\Resources\CourseReviewResource;
use App\Models\Category;
use App\Models\Course;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

class CatalogController extends Controller
{
    public function categories(): AnonymousResourceCollection
    {
        return CategoryResource::collection(
            Category::withCount(['courses' => fn ($q) => $q->published()])->orderBy('sort')->orderBy('name')->get()
        );
    }

    public function index(Request $request, CatalogQuery $catalog, CourseAccessService $access, CatalogCache $cache): AnonymousResourceCollection|JsonResponse
    {
        $f = $request->validate([
            'q' => ['nullable', 'string', 'max:120'],
            'category' => ['nullable', 'string', 'max:140'],
            'level' => ['nullable', 'array'],
            'level.*' => [Rule::enum(CourseLevel::class)],
            'tier' => ['nullable', 'array'],
            'tier.*' => [Rule::in(['free', '1', '2', '3'])],
            'duration' => ['nullable', 'array'],
            'duration.*' => [Rule::in(['short', 'medium', 'long'])],
            'rating' => ['nullable', 'numeric', 'between:1,5'],
            'featured' => ['nullable', 'boolean'],
            'sort' => ['nullable', Rule::in(['popular', 'newest', 'rating', 'duration_asc', 'duration_desc', 'relevance'])],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:48'],
        ]);

        /** @var User|null $user */
        $user = $request->user() ?? $request->user('sanctum');
        if (! $user) {
            ksort($f);

            return $cache->guestJson('index:'.md5((string) json_encode([$f, (int) $request->query('page', '1')])), fn () => CourseCardResource::collection($catalog->paginate($f))->response($request)->getData(true));
        }

        $page = $catalog->paginate($f);
        $page->getCollection()->each(fn (Course $c) => $c->setAttribute('has_access', $access->canAccessCourse($user, $c)));

        return CourseCardResource::collection($page);
    }

    public function show(Request $request, string $slug, CourseAccessService $access, CatalogCache $cache): CourseDetailResource|JsonResponse
    {
        if (! ($request->user() ?? $request->user('sanctum')) && preg_match('/^[a-z0-9-]{1,180}$/', $slug)) {
            return $cache->guestJson('show:'.$slug, function () use ($slug, $request) {
                $course = Course::published()->where('slug', $slug)
                    ->with(['instructor.profile', 'coInstructors.profile', 'category', 'tags', 'promoVideo', 'sections.lessons', 'reviews'])
                    ->firstOrFail();

                return (new CourseDetailResource($course))->response($request)->getData(true);
            });
        }

        $course = Course::where('slug', $slug)
            ->with(['instructor.profile', 'coInstructors.profile', 'category', 'tags', 'promoVideo', 'sections.lessons', 'reviews'])
            ->firstOrFail();

        /** @var User|null $user */
        $user = $request->user() ?? $request->user('sanctum');
        abort_unless($course->isPublished() || $access->canManage($user, $course), 404);

        return new CourseDetailResource($course);
    }

    public function reviews(string $slug): AnonymousResourceCollection
    {
        $course = Course::published()->where('slug', $slug)->firstOrFail();

        return CourseReviewResource::collection($course->reviews()->visible()->with('user')->whereNotNull('comment')->latest()->paginate(10));
    }
}
