<?php

use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

it('serves the anonymous catalogue from cache and refreshes it when a course changes', function () {
    $course = publishedCourse(1, 1, ['title' => 'Cached course']);

    $this->getJson(api('courses'))->assertOk()->assertJsonPath('data.0.title', 'Cached course')
        ->assertHeader('Cache-Control', 'max-age=60, public, s-maxage=300');

    DB::enableQueryLog();
    $this->getJson(api('courses'))->assertOk();
    $this->getJson(api("courses/{$course->slug}"))->assertOk();
    $this->getJson(api("courses/{$course->slug}"))->assertOk();
    $queries = collect(DB::getQueryLog())->pluck('query')->filter(fn ($q) => str_contains($q, 'from "courses"'));
    expect($queries)->toHaveCount(1); // only the first detail view hit the database

    $course->update(['title' => 'Renamed course']);
    $this->getJson(api('courses'))->assertJsonPath('data.0.title', 'Renamed course');
});

it('never serves signed-in visitors from the anonymous cache', function () {
    publishedCourse(2, 1);
    $this->getJson(api('courses'))->assertOk();
    $u = User::factory()->create();
    $this->actingAs($u)->getJson(api('courses'))->assertOk()->assertJsonPath('data.0.has_access', false)
        ->assertHeaderMissing('Age');
});

it('stores responsive WebP variants of course thumbnails and exposes a srcset', function () {
    Storage::fake((string) config('video.thumbnails_disk'));
    $course = publishedCourse(1, 1);
    $this->actingAs($course->instructor)->post(api("instructor/courses/{$course->uuid}/thumbnail"), [
        'thumbnail' => UploadedFile::fake()->image('cover.jpg', 1280, 720),
    ], ['Accept' => 'application/json'])->assertOk();

    $variants = $course->fresh()->thumbnail_variants;
    expect(array_keys($variants))->toBe([480, 960, 1280]); // capped at the original width
    Storage::disk((string) config('video.thumbnails_disk'))->assertExists($variants[480]);
    $this->getJson(api('courses'))->assertJsonPath('data.0.thumbnail_srcset', fn ($s) => str_contains($s, ' 480w') && str_contains($s, ' 1280w'));
});
