<?php

namespace App\Models;

use App\Enums\SubmissionStatus;
use App\Models\Concerns\HasPublicUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $uuid
 * @property int $assignment_id
 * @property int $user_id
 * @property string|null $text
 * @property list<array{path: string, name: string, size: int, mime: string|null}>|null $files
 * @property SubmissionStatus $status
 * @property int|null $score
 * @property string|null $feedback
 * @property int|null $graded_by
 * @property Carbon|null $graded_at
 * @property Carbon $submitted_at
 * @property int $revision
 * @property Assignment $assignment
 * @property User $user
 */
class AssignmentSubmission extends Model
{
    use HasPublicUuid;

    protected $fillable = ['assignment_id', 'user_id', 'text', 'files', 'status', 'score', 'feedback', 'graded_by', 'graded_at', 'submitted_at', 'revision'];

    protected $attributes = ['text' => null, 'files' => null, 'score' => null, 'feedback' => null, 'graded_by' => null, 'graded_at' => null, 'revision' => 1];

    protected function casts(): array
    {
        return ['files' => 'array', 'status' => SubmissionStatus::class, 'score' => 'integer', 'graded_at' => 'datetime', 'submitted_at' => 'datetime', 'revision' => 'integer'];
    }

    /** @return BelongsTo<Assignment, $this> */
    public function assignment(): BelongsTo
    {
        return $this->belongsTo(Assignment::class);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
