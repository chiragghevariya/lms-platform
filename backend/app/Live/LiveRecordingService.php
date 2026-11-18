<?php

namespace App\Live;

use App\Enums\LessonType;
use App\Enums\VideoStatus;
use App\Models\CourseSection;
use App\Models\Lesson;
use App\Models\LiveSession;
use App\Models\Video;
use App\Video\VideoService;
use Illuminate\Support\Facades\DB;

/**
 * Recordings: re-hosted on our video provider (signed, non-downloadable playback like every lesson),
 * then attached as a replay. Course sessions get a lesson in the course's "Live replays" section
 * (gated like the course); standalone sessions appear in the Replays library gated by required_tier.
 */
class LiveRecordingService
{
    public function __construct(private readonly VideoService $videos) {}

    /**
     * Starts (or continues) the import. Returns true once the provider knows the video.
     *
     * @param  array<string, string>  $headers
     */
    public function import(LiveSession $session, string $url, array $headers): bool
    {
        $video = $session->recordingVideo;
        if (! $video) {
            $video = Video::create([
                'uploaded_by' => $session->instructor_id,
                'provider' => $this->videos->provider()->name(),
                'title' => 'Replay: '.$session->title,
                'status' => VideoStatus::Processing,
            ]);
            $session->forceFill(['recording_video_id' => $video->id])->save();
            $session->setRelation('recordingVideo', $video);
            $this->videos->provider()->importFromUrl($video, $url, $headers);
        }

        if (! $video->provider_video_id) {
            $id = $this->videos->provider()->findImported($video);
            if (! $id) {
                return false;
            }
            $video->forceFill(['provider_video_id' => $id])->save();
        }

        $this->videos->sync($video);
        $this->attachReplay($session, $video);

        return true;
    }

    /** Idempotent: creates the replay lesson once for course sessions. */
    public function attachReplay(LiveSession $session, Video $video): void
    {
        $session->forceFill(['recording_video_id' => $video->id])->save();
        if (! $session->course_id || $session->replay_lesson_id) {
            return;
        }

        DB::transaction(function () use ($session, $video) {
            $section = CourseSection::firstOrCreate(
                ['course_id' => $session->course_id, 'title' => 'Live replays'],
                ['sort' => (int) CourseSection::where('course_id', $session->course_id)->max('sort') + 1],
            );
            $lesson = Lesson::create([
                'course_id' => $session->course_id,
                'section_id' => $section->id,
                'title' => 'Replay: '.$session->title.' ('.$session->starts_at->copy()->setTimezone($session->timezone)->format('j M Y').')',
                'type' => LessonType::Video,
                'video_id' => $video->id,
                'content' => $session->description,
                'duration_seconds' => (int) ($video->duration_seconds ?? $session->duration * 60),
                'is_optional' => true,
                'sort' => (int) Lesson::where('section_id', $section->id)->max('sort') + 1,
            ]);
            $session->forceFill(['replay_lesson_id' => $lesson->id])->save();
            $lesson->course->refreshStats();
        });
    }
}
