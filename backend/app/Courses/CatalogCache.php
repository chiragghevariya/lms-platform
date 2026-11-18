<?php

namespace App\Courses;

use Closure;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;

/**
 * Short-lived cache of public catalogue JSON for anonymous visitors (most catalogue traffic).
 * Keys carry a version number that course/category/review/curriculum changes bump, so edits
 * show immediately; signed-in requests are never served from it (they carry access flags).
 */
class CatalogCache
{
    public function store(): Repository
    {
        return Cache::store(config('catalog.cache_store') ?: null);
    }

    public function bump(): void
    {
        $this->store()->forever('catalog:version', (int) $this->store()->get('catalog:version', 0) + 1);
    }

    /** @param Closure(): array<string, mixed> $build */
    public function guestJson(string $key, Closure $build, int $ttl = 300): JsonResponse
    {
        $version = (int) $this->store()->get('catalog:version', 0);
        /** @var array<string, mixed> $data */
        $data = $this->store()->remember("catalog:{$version}:{$key}", $ttl, $build);

        // Let the CDN/browser hold anonymous catalogue pages briefly too.
        return response()->json($data)->setPublic()->setMaxAge(60)->setSharedMaxAge($ttl)->header('Vary', 'Accept-Encoding, Cookie, Authorization');
    }
}
