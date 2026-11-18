<?php

namespace App\Cms;

use App\Models\Banner;
use App\Models\CmsSection;
use App\Models\Faq;
use App\Models\Post;
use App\Models\Testimonial;
use App\Models\User;
use Stevebauman\Purify\Facades\Purify;

/** Read-side helpers shared by the public site endpoints and the admin editors. */
class CmsContent
{
    /** @return list<array{key: string, content: array<string, mixed>, is_visible: bool, sort: int, customised: bool}> */
    public function sections(bool $visibleOnly = false): array
    {
        $stored = CmsSection::all()->keyBy('key');
        $defaults = CmsDefaults::sections();

        return collect(CmsDefaults::SECTIONS)->map(function (string $key, int $i) use ($stored, $defaults) {
            $row = $stored->get($key);

            return [
                'key' => $key,
                'content' => $row ? $row->content + $defaults[$key] : $defaults[$key],
                'is_visible' => $row->is_visible ?? true,
                'sort' => $row->sort ?? $i,
                'customised' => $row !== null && $row->content !== [],
            ];
        })->when($visibleOnly, fn ($c) => $c->where('is_visible', true))->sortBy('sort')->values()->all();
    }

    /** @return list<array<string, mixed>> */
    public function faqs(): array
    {
        return Faq::where('is_published', true)->orderBy('sort')->orderBy('id')->get()
            ->map(fn (Faq $f) => ['id' => $f->uuid, 'question' => $f->question, 'answer' => $f->answer, 'category' => $f->category])->all();
    }

    /** @return list<array<string, mixed>> */
    public function testimonials(): array
    {
        return Testimonial::where('is_published', true)->orderBy('sort')->orderBy('id')->get()
            ->map(fn (Testimonial $t) => ['id' => $t->uuid, 'name' => $t->name, 'role' => $t->role, 'quote' => $t->quote, 'rating' => $t->rating, 'avatar_url' => $t->avatar_url])->all();
    }

    /**
     * Newest live banner matching the visitor.
     *
     * @return array<string, mixed>|null
     */
    public function banner(?User $user): ?array
    {
        $audiences = ['all', $user ? ($user->currentTier() ? 'members' : 'free') : 'guests'];
        $b = Banner::live()->whereIn('audience', $audiences)->latest('id')->first();

        return $b ? ['id' => $b->uuid, 'message' => $b->message, 'link_text' => $b->link_text, 'link_url' => $b->link_url, 'variant' => $b->variant] : null;
    }

    public static function cleanHtml(string $html): string
    {
        return (string) Purify::config('cms')->clean($html);
    }

    /** @return array<string, mixed> */
    public static function presentPost(Post $p, bool $withBody): array
    {
        return [
            'id' => $p->uuid,
            'slug' => $p->slug,
            'title' => $p->title,
            'excerpt' => $p->excerpt,
            'cover_url' => $p->cover_url,
            'status' => $p->status,
            'published_at' => $p->published_at?->toIso8601String(),
            'author' => $p->author ? ['name' => $p->author->name] : null,
            'seo_title' => $p->seo_title,
            'seo_description' => $p->seo_description,
            'reading_minutes' => max(1, (int) ceil(str_word_count(strip_tags($p->body)) / 220)),
            'updated_at' => $p->updated_at->toIso8601String(),
            ...($withBody ? ['body' => $p->body] : []),
        ];
    }
}
