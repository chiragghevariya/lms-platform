<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $user_id
 * @property int $lesson_id
 * @property int $course_id
 * @property int $position_seconds
 * @property int $watched_seconds
 * @property Carbon|null $last_heartbeat_at
 * @property Carbon|null $completed_at
 */
class LessonProgress extends Model
{
    protected $table = 'lesson_progress';

    protected $fillable = ['user_id', 'lesson_id', 'course_id', 'position_seconds', 'watched_seconds', 'last_heartbeat_at', 'completed_at'];

    protected $attributes = ['position_seconds' => 0, 'watched_seconds' => 0, 'last_heartbeat_at' => null, 'completed_at' => null];

    protected function casts(): array
    {
        return ['position_seconds' => 'integer', 'watched_seconds' => 'integer', 'last_heartbeat_at' => 'datetime', 'completed_at' => 'datetime'];
    }
}
