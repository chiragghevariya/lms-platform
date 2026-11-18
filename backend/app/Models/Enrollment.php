<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $user_id
 * @property int $course_id
 * @property string $source
 * @property Carbon $started_at
 * @property Carbon|null $last_accessed_at
 */
class Enrollment extends Model
{
    protected $fillable = ['user_id', 'course_id', 'source', 'started_at', 'last_accessed_at'];

    protected $attributes = ['last_accessed_at' => null];

    protected function casts(): array
    {
        return ['started_at' => 'datetime', 'last_accessed_at' => 'datetime'];
    }

    /** @return BelongsTo<Course, $this> */
    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }
}
