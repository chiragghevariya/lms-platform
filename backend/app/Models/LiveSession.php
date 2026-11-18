<?php

namespace App\Models;

use App\Contracts\GatedContent;
use App\Enums\LiveProviderType;
use App\Enums\LiveSessionStatus;
use App\Enums\LiveSessionType;
use App\Models\Concerns\HasPublicUuid;
use App\Models\Concerns\HasRequiredTier;
use Database\Factories\LiveSessionFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $uuid
 * @property int|null $series_id
 * @property int $instructor_id
 * @property int|null $course_id
 * @property string $title
 * @property string|null $description
 * @property LiveSessionType $type
 * @property Carbon $starts_at
 * @property int $duration
 * @property string $timezone
 * @property int|null $capacity
 * @property LiveProviderType $provider
 * @property string|null $meeting_id
 * @property string|null $meeting_password
 * @property string|null $join_url
 * @property string|null $host_url
 * @property string|null $recording_url
 * @property int|null $recording_video_id
 * @property int|null $replay_lesson_id
 * @property LiveSessionStatus $status
 * @property Carbon|null $started_at
 * @property Carbon|null $ended_at
 * @property Carbon|null $attendance_synced_at
 * @property string|null $cancel_reason
 * @property int|null $registrations_count
 * @property User $instructor
 * @property Course|null $course
 * @property LiveSeries|null $series
 * @property Video|null $recordingVideo
 * @property Lesson|null $replayLesson
 */
class LiveSession extends Model implements GatedContent
{
    /** @use HasFactory<LiveSessionFactory> */
    use HasFactory, HasPublicUuid, HasRequiredTier, SoftDeletes;

    protected $fillable = [
        'series_id', 'instructor_id', 'course_id', 'title', 'description', 'type', 'required_tier', 'starts_at', 'duration',
        'timezone', 'capacity', 'provider', 'meeting_id', 'meeting_password', 'join_url', 'host_url', 'recording_url', 'status',
    ];

    protected $attributes = ['status' => 'scheduled'];

    /** Secrets never leave the API except through the join/start endpoints. */
    protected $hidden = ['meeting_password', 'join_url', 'host_url'];

    protected function casts(): array
    {
        return [
            'type' => LiveSessionType::class,
            'provider' => LiveProviderType::class,
            'status' => LiveSessionStatus::class,
            'starts_at' => 'datetime',
            'started_at' => 'datetime',
            'ended_at' => 'datetime',
            'attendance_synced_at' => 'datetime',
            'meeting_password' => 'encrypted',
            'join_url' => 'encrypted',
            'host_url' => 'encrypted',
        ];
    }

    public function endsAt(): Carbon
    {
        return $this->starts_at->copy()->addMinutes($this->duration);
    }

    /** "Join now" opens this many minutes before the start and stays open until the scheduled end (or while live). */
    public function isJoinable(?Carbon $now = null): bool
    {
        $now ??= now();
        if (in_array($this->status, [LiveSessionStatus::Cancelled, LiveSessionStatus::Ended], true)) {
            return false;
        }

        return $now->gte($this->starts_at->copy()->subMinutes((int) config('live.join_early_minutes')))
            && ($this->status === LiveSessionStatus::Live || $now->lt($this->endsAt()));
    }

    /** @param Builder<LiveSession> $q */
    public function scopeUpcoming(Builder $q): void
    {
        $q->whereIn('status', [LiveSessionStatus::Scheduled, LiveSessionStatus::Live])
            ->whereRaw($this->endExpression().' > ?', [now()->toDateTimeString()]);
    }

    /** SQL for starts_at + duration minutes (MySQL in production, SQLite locally/in tests). */
    public function endExpression(): string
    {
        return $this->getConnection()->getDriverName() === 'sqlite'
            ? "datetime(starts_at, '+' || duration || ' minutes')"
            : 'DATE_ADD(starts_at, INTERVAL duration MINUTE)';
    }

    /** @return BelongsTo<User, $this> */
    public function instructor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'instructor_id');
    }

    /** @return BelongsTo<Course, $this> */
    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }

    /** @return BelongsTo<LiveSeries, $this> */
    public function series(): BelongsTo
    {
        return $this->belongsTo(LiveSeries::class, 'series_id');
    }

    /** @return BelongsTo<Video, $this> */
    public function recordingVideo(): BelongsTo
    {
        return $this->belongsTo(Video::class, 'recording_video_id');
    }

    /** @return BelongsTo<Lesson, $this> */
    public function replayLesson(): BelongsTo
    {
        return $this->belongsTo(Lesson::class, 'replay_lesson_id');
    }

    /** @return HasMany<LiveRegistration, $this> */
    public function registrations(): HasMany
    {
        return $this->hasMany(LiveRegistration::class);
    }
}
