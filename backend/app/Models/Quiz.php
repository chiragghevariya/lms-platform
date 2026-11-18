<?php

namespace App\Models;

use App\Models\Concerns\HasPublicUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property string $uuid
 * @property int $lesson_id
 * @property int $pass_mark
 * @property int|null $time_limit_seconds
 * @property int|null $attempts_allowed
 * @property bool $shuffle
 * @property bool $show_answers
 * @property Lesson $lesson
 */
class Quiz extends Model
{
    use HasPublicUuid;

    protected $fillable = ['lesson_id', 'pass_mark', 'time_limit_seconds', 'attempts_allowed', 'shuffle', 'show_answers'];

    protected $attributes = ['pass_mark' => 70, 'time_limit_seconds' => null, 'attempts_allowed' => null, 'shuffle' => false, 'show_answers' => true];

    protected function casts(): array
    {
        return ['pass_mark' => 'integer', 'time_limit_seconds' => 'integer', 'attempts_allowed' => 'integer', 'shuffle' => 'boolean', 'show_answers' => 'boolean'];
    }

    /** @return BelongsTo<Lesson, $this> */
    public function lesson(): BelongsTo
    {
        return $this->belongsTo(Lesson::class);
    }

    /** @return HasMany<QuizQuestion, $this> */
    public function questions(): HasMany
    {
        return $this->hasMany(QuizQuestion::class)->orderBy('sort')->orderBy('id');
    }

    /** @return HasMany<QuizAttempt, $this> */
    public function attempts(): HasMany
    {
        return $this->hasMany(QuizAttempt::class);
    }
}
