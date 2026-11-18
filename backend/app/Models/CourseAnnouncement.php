<?php

namespace App\Models;

use App\Models\Concerns\HasPublicUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $uuid
 * @property int $course_id
 * @property int $user_id
 * @property string $title
 * @property string $body
 * @property int $recipients_count
 * @property Carbon|null $created_at
 * @property Course $course
 * @property User $author
 */
class CourseAnnouncement extends Model
{
    use HasPublicUuid;

    protected $fillable = ['course_id', 'user_id', 'title', 'body', 'recipients_count'];

    /** @return BelongsTo<Course, $this> */
    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }

    /** @return BelongsTo<User, $this> */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
