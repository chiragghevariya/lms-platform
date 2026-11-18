<?php

namespace App\Models;

use App\Enums\AttemptStatus;
use App\Models\Concerns\HasPublicUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $uuid
 * @property int $quiz_id
 * @property int $user_id
 * @property AttemptStatus $status
 * @property list<int> $question_order
 * @property Carbon $started_at
 * @property Carbon|null $expires_at
 * @property Carbon|null $submitted_at
 * @property int $score
 * @property int $max_score
 * @property int $percent
 * @property bool $passed
 * @property Quiz $quiz
 */
class QuizAttempt extends Model
{
    use HasPublicUuid;

    protected $fillable = ['quiz_id', 'user_id', 'status', 'question_order', 'started_at', 'expires_at', 'submitted_at', 'score', 'max_score', 'percent', 'passed'];

    protected $attributes = ['expires_at' => null, 'submitted_at' => null, 'score' => 0, 'max_score' => 0, 'percent' => 0, 'passed' => false];

    protected function casts(): array
    {
        return [
            'status' => AttemptStatus::class,
            'question_order' => 'array',
            'started_at' => 'datetime',
            'expires_at' => 'datetime',
            'submitted_at' => 'datetime',
            'score' => 'integer',
            'max_score' => 'integer',
            'percent' => 'integer',
            'passed' => 'boolean',
        ];
    }

    /** @return BelongsTo<Quiz, $this> */
    public function quiz(): BelongsTo
    {
        return $this->belongsTo(Quiz::class);
    }

    /** @return HasMany<QuizAnswer, $this> */
    public function answers(): HasMany
    {
        return $this->hasMany(QuizAnswer::class, 'attempt_id');
    }

    /** Past the time limit (with a small grace for network latency)? */
    public function isExpired(): bool
    {
        return $this->expires_at !== null && now()->greaterThan($this->expires_at->copy()->addSeconds(10));
    }
}
