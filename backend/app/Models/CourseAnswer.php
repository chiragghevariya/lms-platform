<?php

namespace App\Models;

use App\Models\Concerns\HasPublicUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $uuid
 * @property int $question_id
 * @property int $user_id
 * @property string $body
 * @property bool $is_instructor
 * @property Carbon|null $created_at
 * @property CourseQuestion $question
 * @property User $user
 */
class CourseAnswer extends Model
{
    use HasPublicUuid, SoftDeletes;

    protected $fillable = ['question_id', 'user_id', 'body', 'is_instructor'];

    protected function casts(): array
    {
        return ['is_instructor' => 'boolean'];
    }

    /** @return BelongsTo<CourseQuestion, $this> */
    public function question(): BelongsTo
    {
        return $this->belongsTo(CourseQuestion::class, 'question_id');
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
