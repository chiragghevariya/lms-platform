<?php

namespace App\Http\Controllers\Api\V1;

use App\Cms\CmsContent;
use App\Courses\CatalogCache;
use App\Http\Controllers\Controller;
use App\Models\Page;
use App\Models\Post;
use App\Models\User;
use App\Settings\SiteSettings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Public, cache-friendly site content: settings for the SPA shell, homepage CMS, pages, blog. */
class SiteController extends Controller
{
    public function __construct(private readonly CmsContent $cms) {}

    public function site(Request $request, SiteSettings $settings, CatalogCache $cache): JsonResponse
    {
        /** @var User|null $user */
        $user = $request->user('sanctum');
        if (! $user) {
            // Guests share one cached copy (busted by settings/CMS changes); 60 s cap for scheduled banners.
            return $cache->guestJson('site:'.$settings->version(), fn () => ['data' => [...$settings->public(), 'banner' => $this->cms->banner(null)]], 60);
        }

        return response()->json(['data' => [...$settings->public(), 'banner' => $this->cms->banner($user)]]);
    }

    public function home(CatalogCache $cache): JsonResponse
    {
        return $cache->guestJson('cms-home', fn () => ['data' => [
            'sections' => $this->cms->sections(visibleOnly: true),
            'faqs' => $this->cms->faqs(),
            'testimonials' => $this->cms->testimonials(),
        ]]);
    }

    public function page(string $slug): JsonResponse
    {
        $page = Page::where('slug', $slug)->where('is_published', true)->firstOrFail();

        return response()->json(['data' => [
            'slug' => $page->slug, 'title' => $page->title, 'body' => $page->body, 'updated_at' => $page->updated_at->toIso8601String(),
        ]]);
    }

    public function posts(Request $request): JsonResponse
    {
        $page = Post::published()->with('author:id,name')->latest('published_at')->paginate(12);

        return response()->json([
            'data' => $page->getCollection()->map(fn (Post $p) => CmsContent::presentPost($p, false))->values(),
            'meta' => ['total' => $page->total(), 'current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'per_page' => $page->perPage()],
        ]);
    }

    public function post(string $slug): JsonResponse
    {
        $post = Post::published()->with('author:id,name')->where('slug', $slug)->firstOrFail();

        return response()->json(['data' => CmsContent::presentPost($post, true)]);
    }
}
