<?php

namespace App\Http\Controllers\Api\V1\Instructor;

use App\Enums\Permission;
use App\Enums\VideoStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\VideoResource;
use App\Models\User;
use App\Models\Video;
use App\Video\DemoVideoProvider;
use App\Video\VideoService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class VideoController extends Controller
{
    /** Creates the video at the provider and returns a direct (browser → provider) upload ticket. */
    public function store(Request $request, VideoService $videos): JsonResponse
    {
        $data = $request->validate(['title' => ['required', 'string', 'max:160']]);
        /** @var User $user */
        $user = $request->user();

        ['video' => $video, 'upload' => $ticket] = $videos->startUpload($user, $data['title']);

        return response()->json(['data' => ['video' => new VideoResource($video), 'upload' => $ticket->toArray()]], 201);
    }

    /** Status poll (also re-syncs from the provider while processing, in case a webhook was missed). */
    public function show(Request $request, Video $video, VideoService $videos): VideoResource
    {
        $this->authorizeVideo($request, $video);

        if ($video->status === VideoStatus::Processing && $video->updated_at?->lt(now()->subMinute())) {
            $videos->sync($video);
        }

        return new VideoResource($video->refresh());
    }

    /** Browser finished the TUS upload: provider now encodes; the webhook will mark it ready. */
    public function uploaded(Request $request, Video $video): VideoResource
    {
        $this->authorizeVideo($request, $video);
        if ($video->status === VideoStatus::Uploading) {
            $video->forceFill(['status' => VideoStatus::Processing])->save();
        }

        return new VideoResource($video);
    }

    /** Demo driver only: accepts (and discards) the file, marks the video ready. */
    public function demoUpload(Request $request, Video $video, VideoService $videos): VideoResource
    {
        abort_unless($videos->provider() instanceof DemoVideoProvider, 404);
        $this->authorizeVideo($request, $video);
        $request->validate(['file' => ['required', 'file', 'mimetypes:video/mp4,video/quicktime,video/webm,video/x-matroska', 'max:2097152']]);

        return new VideoResource($videos->sync($video));
    }

    private function authorizeVideo(Request $request, Video $video): void
    {
        /** @var User $user */
        $user = $request->user();
        abort_unless($video->uploaded_by === $user->id || $user->can(Permission::CoursesManageAny->value), 404);
    }
}
