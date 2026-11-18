<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use Illuminate\Http\Response;

/** XML sitemap of public SPA pages. Course/instructor URLs are appended in later phases. */
class SitemapController extends Controller
{
    /** @var array<string, array{0: string, 1: string}> path => [changefreq, priority] */
    private const PAGES = [
        '/' => ['weekly', '1.0'],
        '/courses' => ['daily', '0.9'],
        '/pricing' => ['monthly', '0.8'],
        '/about' => ['monthly', '0.6'],
        '/contact' => ['yearly', '0.5'],
        '/terms' => ['yearly', '0.2'],
        '/privacy' => ['yearly', '0.2'],
    ];

    public function __invoke(): Response
    {
        $base = (string) config('lms.frontend_url');
        $lastmod = now()->toDateString();

        $urls = collect(self::PAGES)->map(fn (array $meta, string $path) => sprintf(
            '<url><loc>%s</loc><lastmod>%s</lastmod><changefreq>%s</changefreq><priority>%s</priority></url>',
            htmlspecialchars($base.($path === '/' ? '/' : $path), ENT_XML1), $lastmod, $meta[0], $meta[1],
        ))->implode('');

        $xml = '<?xml version="1.0" encoding="UTF-8"?>'
            .'<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">'.$urls.'</urlset>';

        return response($xml, 200, ['Content-Type' => 'application/xml; charset=UTF-8'])
            ->setPublic()->setMaxAge(3600);
    }
}
