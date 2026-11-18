<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Affiliate;
use Illuminate\Http\Response;

/** Ready-made SVG banners (brand colours, tagline, CTA) for affiliates to embed with their link. */
class AffiliateBannerController extends Controller
{
    private const SIZES = ['728x90' => [728, 90], '300x250' => [300, 250], '1080x1080' => [1080, 1080]];

    public function __invoke(string $size, string $code): Response
    {
        abort_unless(isset(self::SIZES[$size]) && Affiliate::where('code', strtoupper($code))->where('status', 'approved')->exists(), 404);
        [$w, $h] = self::SIZES[$size];
        $brand = e((string) config('lms.brand.name'));
        $tag = e((string) config('lms.brand.tagline', 'Learn Skills. Build Income.'));
        $wide = $w / $h > 3;
        $svg = $wide
            ? "<svg xmlns='http://www.w3.org/2000/svg' width='{$w}' height='{$h}' viewBox='0 0 {$w} {$h}'><rect width='100%' height='100%' rx='8' fill='#4f46e5'/>"
                ."<text x='24' y='38' font-family='Inter,Arial,sans-serif' font-size='24' font-weight='700' fill='#fff'>{$brand}</text>"
                ."<text x='24' y='66' font-family='Inter,Arial,sans-serif' font-size='16' fill='#e0e7ff'>{$tag}</text>"
                ."<rect x='".($w - 184)."' y='24' width='160' height='42' rx='21' fill='#10b981'/><text x='".($w - 104)."' y='51' text-anchor='middle' font-family='Inter,Arial,sans-serif' font-size='16' font-weight='700' fill='#fff'>Start learning</text></svg>"
            : "<svg xmlns='http://www.w3.org/2000/svg' width='{$w}' height='{$h}' viewBox='0 0 300 250'><rect width='300' height='250' rx='12' fill='#4f46e5'/>"
                ."<text x='150' y='88' text-anchor='middle' font-family='Inter,Arial,sans-serif' font-size='30' font-weight='700' fill='#fff'>{$brand}</text>"
                ."<text x='150' y='122' text-anchor='middle' font-family='Inter,Arial,sans-serif' font-size='16' fill='#e0e7ff'>{$tag}</text>"
                ."<rect x='70' y='160' width='160' height='44' rx='22' fill='#10b981'/><text x='150' y='188' text-anchor='middle' font-family='Inter,Arial,sans-serif' font-size='16' font-weight='700' fill='#fff'>Start learning</text></svg>";

        return response($svg, 200, ['Content-Type' => 'image/svg+xml', 'Cache-Control' => 'public, max-age=86400', 'X-Content-Type-Options' => 'nosniff']);
    }
}
