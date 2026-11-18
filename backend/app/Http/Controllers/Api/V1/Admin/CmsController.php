<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Cms\CmsContent;
use App\Cms\CmsDefaults;
use App\Http\Controllers\Controller;
use App\Models\Banner;
use App\Models\CmsSection;
use App\Models\Faq;
use App\Models\Page;
use App\Models\Post;
use App\Models\Testimonial;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/** Admin editors for homepage sections, FAQs, testimonials, banners, legal pages and the blog. */
class CmsController extends Controller
{
    /** @var array<string, class-string<Faq|Testimonial|Banner>> */
    private const TYPES = ['faqs' => Faq::class, 'testimonials' => Testimonial::class, 'banners' => Banner::class];

    public function __construct(private readonly CmsContent $cms) {}

    // ---------------------------------------------------------------- homepage sections

    public function sections(): JsonResponse
    {
        return response()->json(['data' => $this->cms->sections()]);
    }

    public function updateSection(Request $request, string $key): JsonResponse
    {
        abort_unless(in_array($key, CmsDefaults::SECTIONS, true), 404);
        $rules = CmsDefaults::rules();
        $rules['content'] = ['sometimes', 'array']; // visibility/order-only updates leave the copy alone
        $data = $request->validate($rules);

        $row = CmsSection::firstOrNew(['key' => $key]);
        if (isset($data['content'])) {
            $row->content = Arr::only($data['content'], ['badge', 'eyebrow', 'title', 'highlight', 'subtitle', 'intro', 'primary_cta', 'secondary_cta', 'items']);
        }
        $row->content ??= []; // stored overrides only; defaults fill the rest
        foreach (['is_visible', 'sort'] as $field) {
            if (array_key_exists($field, $data)) {
                $row->{$field} = $data[$field];
            }
        }
        $row->save();

        return response()->json(['data' => collect($this->cms->sections())->firstWhere('key', $key)]);
    }

    // ---------------------------------------------------------------- faqs / testimonials / banners

    public function index(Request $request, string $type): JsonResponse
    {
        $model = self::TYPES[$type];
        $rows = $model::query()->when($type !== 'banners', fn ($q) => $q->orderBy('sort'))->latest('id')->get();

        return response()->json(['data' => $rows->map(fn (Model $m) => $this->present($m))->values()]);
    }

    public function store(Request $request, string $type): JsonResponse
    {
        $model = self::TYPES[$type];
        $data = $request->validate($this->rules($type, true));
        if ($type !== 'banners' && ! isset($data['sort'])) {
            $data['sort'] = (int) $model::max('sort') + 1;
        }
        $row = $model::create($data);

        return response()->json(['data' => $this->present($row)], 201);
    }

    public function update(Request $request, string $type, string $id): JsonResponse
    {
        $row = self::TYPES[$type]::where('uuid', $id)->firstOrFail();
        $row->update($request->validate($this->rules($type, false)));

        return response()->json(['data' => $this->present($row)]);
    }

    public function destroy(string $type, string $id): JsonResponse
    {
        self::TYPES[$type]::where('uuid', $id)->firstOrFail()->delete();

        return response()->json(null, 204);
    }

    /** Drag-and-drop order: ids in their new order. */
    public function reorder(Request $request, string $type): JsonResponse
    {
        $data = $request->validate(['ids' => ['required', 'array', 'max:200'], 'ids.*' => ['string']]);
        $model = self::TYPES[$type];
        DB::transaction(function () use ($model, $data) {
            foreach ($data['ids'] as $i => $uuid) {
                $model::where('uuid', $uuid)->update(['sort' => $i]);
            }
        });

        return $this->index($request, $type);
    }

    /** @return array<string, mixed> */
    private function rules(string $type, bool $creating): array
    {
        $req = $creating ? 'required' : 'sometimes';

        return match ($type) {
            'faqs' => [
                'question' => [$req, 'string', 'max:300'],
                'answer' => [$req, 'string', 'max:3000'],
                'category' => ['sometimes', 'in:general,billing,courses,live,affiliates'],
                'is_published' => ['sometimes', 'boolean'],
                'sort' => ['sometimes', 'integer', 'min:0'],
            ],
            'testimonials' => [
                'name' => [$req, 'string', 'max:120'],
                'role' => ['nullable', 'string', 'max:160'],
                'quote' => [$req, 'string', 'max:1000'],
                'rating' => ['nullable', 'integer', 'between:1,5'],
                'avatar_url' => ['nullable', 'url:https', 'max:500'],
                'is_published' => ['sometimes', 'boolean'],
                'sort' => ['sometimes', 'integer', 'min:0'],
            ],
            default => [
                'message' => [$req, 'string', 'max:300'],
                'link_text' => ['nullable', 'required_with:link_url', 'string', 'max:60'],
                'link_url' => ['nullable', 'string', 'max:500', 'regex:#^(/[^\s]*|https://[^\s]+)$#'],
                'variant' => ['sometimes', 'in:info,promo,warning'],
                'audience' => ['sometimes', 'in:all,guests,members,free'],
                'starts_at' => ['nullable', 'date'],
                'ends_at' => ['nullable', 'date', 'after:starts_at'],
                'is_active' => ['sometimes', 'boolean'],
            ],
        };
    }

    /** @return array<string, mixed> */
    private function present(Model $m): array
    {
        $out = ['id' => $m->getAttribute('uuid'), ...Arr::except($m->attributesToArray(), ['id', 'uuid', 'created_at'])];
        foreach (['starts_at', 'ends_at', 'updated_at'] as $date) {
            if (array_key_exists($date, $out)) {
                $out[$date] = $m->getAttribute($date)?->toIso8601String();
            }
        }
        if ($m instanceof Banner) {
            $out['is_live'] = Banner::live()->whereKey($m->getKey())->exists();
        }

        return $out;
    }

    // ---------------------------------------------------------------- legal & static pages

    public function pages(): JsonResponse
    {
        $stored = Page::all()->keyBy('slug');
        $slugs = collect(array_keys(CmsDefaults::LEGAL_PAGES))->merge($stored->keys())->unique()->values();

        return response()->json(['data' => $slugs->map(function (string $slug) use ($stored) {
            $p = $stored->get($slug);

            return [
                'slug' => $slug,
                'title' => $p->title ?? CmsDefaults::LEGAL_PAGES[$slug] ?? Str::headline($slug),
                'body' => $p?->body,
                'is_published' => $p->is_published ?? false,
                'customised' => $p !== null,
                'updated_at' => $p?->updated_at->toIso8601String(),
            ];
        })]);
    }

    public function savePage(Request $request, string $slug): JsonResponse
    {
        abort_unless((bool) preg_match('/^[a-z0-9-]{2,60}$/', $slug), 404);
        $data = $request->validate([
            'title' => ['required', 'string', 'max:200'],
            'body' => ['required', 'string', 'max:200000'],
            'is_published' => ['sometimes', 'boolean'],
        ]);
        /** @var User $admin */
        $admin = $request->user();
        $page = Page::updateOrCreate(['slug' => $slug], [
            'title' => $data['title'],
            'body' => CmsContent::cleanHtml($data['body']),
            'is_published' => $data['is_published'] ?? true,
            'updated_by' => $admin->id,
        ]);

        return response()->json(['data' => ['slug' => $page->slug, 'title' => $page->title, 'body' => $page->body, 'is_published' => $page->is_published, 'customised' => true, 'updated_at' => $page->updated_at->toIso8601String()]]);
    }

    // ---------------------------------------------------------------- blog

    public function posts(Request $request): JsonResponse
    {
        $f = $request->validate(['status' => ['nullable', 'in:draft,published'], 'search' => ['nullable', 'string', 'max:100']]);
        $page = Post::with('author:id,name')
            ->when($f['status'] ?? null, fn ($q, $s) => $q->where('status', $s))
            ->when($f['search'] ?? null, fn ($q, $s) => $q->where('title', 'like', '%'.addcslashes((string) $s, '%_\\').'%'))
            ->latest('id')->paginate(20);

        return response()->json([
            'data' => $page->getCollection()->map(fn (Post $p) => CmsContent::presentPost($p, false))->values(),
            'meta' => ['total' => $page->total(), 'current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'per_page' => $page->perPage()],
        ]);
    }

    public function showPost(Post $post): JsonResponse
    {
        return response()->json(['data' => CmsContent::presentPost($post->load('author:id,name'), true)]);
    }

    public function storePost(Request $request): JsonResponse
    {
        /** @var User $admin */
        $admin = $request->user();
        $data = $this->postData($request, null);
        $post = Post::create([...$data, 'author_id' => $admin->id]);

        return response()->json(['data' => CmsContent::presentPost($post->load('author:id,name'), true)], 201);
    }

    public function updatePost(Request $request, Post $post): JsonResponse
    {
        $post->update($this->postData($request, $post));

        return response()->json(['data' => CmsContent::presentPost($post->load('author:id,name'), true)]);
    }

    public function destroyPost(Post $post): JsonResponse
    {
        $post->delete();

        return response()->json(null, 204);
    }

    /** @return array<string, mixed> */
    private function postData(Request $request, ?Post $post): array
    {
        $req = $post ? 'sometimes' : 'required';
        $data = $request->validate([
            'title' => [$req, 'string', 'max:200'],
            'slug' => ['sometimes', 'nullable', 'string', 'max:160', 'regex:/^[a-z0-9-]+$/', Rule::unique('posts', 'slug')->ignore($post?->id)],
            'excerpt' => ['nullable', 'string', 'max:500'],
            'body' => [$req, 'string', 'max:300000'],
            'cover_url' => ['nullable', 'url:https', 'max:500'],
            'status' => ['sometimes', 'in:draft,published'],
            'published_at' => ['nullable', 'date'],
            'seo_title' => ['nullable', 'string', 'max:200'],
            'seo_description' => ['nullable', 'string', 'max:300'],
        ]);

        if (isset($data['body'])) {
            $data['body'] = CmsContent::cleanHtml($data['body']);
        }
        if (! $post || array_key_exists('slug', $data)) {
            $base = Str::slug($data['slug'] ?? $data['title'] ?? $post->title ?? 'post') ?: 'post';
            $slug = $base;
            for ($i = 2; Post::withTrashed()->where('slug', $slug)->when($post, fn ($q) => $q->whereKeyNot($post->id))->exists(); $i++) {
                $slug = "{$base}-{$i}";
            }
            $data['slug'] = $slug;
        }
        // Publishing without a date publishes now (or keeps the original date); a future date schedules it.
        if (($data['status'] ?? $post?->status) === 'published' && empty($data['published_at'])) {
            if ($post?->published_at) {
                unset($data['published_at']);
            } else {
                $data['published_at'] = now();
            }
        }

        return $data;
    }
}
