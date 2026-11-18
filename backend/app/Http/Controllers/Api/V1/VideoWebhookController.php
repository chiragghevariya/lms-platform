<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Jobs\SyncVideo;
use App\Models\Video;
use App\Video\VideoService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

/** Video provider "encoding finished / failed" callbacks. Signature-verified, deduped by event id, processed on a queue. */
class VideoWebhookController extends Controller
{
    public function __invoke(Request $request, VideoService $videos): JsonResponse
    {
        $parsed = $videos->provider()->parseWebhook($request);
        if ($parsed === null) {
            return response()->json(['message' => 'Invalid signature.'], 401);
        }

        [$providerId, $status, $eventId] = $parsed;

        if (! Cache::add('video-webhook:'.$eventId, true, now()->addDay())) {
            return response()->json(['status' => 'duplicate']);
        }

        $video = Video::where('provider', $videos->provider()->name())->where('provider_video_id', $providerId)->first();
        if ($video) {
            SyncVideo::dispatch($video->id, $status->value);
        }

        return response()->json(['status' => $video ? 'queued' : 'ignored']);
    }
}
