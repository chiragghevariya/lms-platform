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
 * @property string|null $instructions
 * @property int|null $due_days
 * @property int $max_score
 * @property int|null $pass_score
 * @property list<string>|null $file_types
 * @property int $max_file_mb
 * @property bool $allow_text
 * @property Lesson $lesson
 */
class Assignment extends Model
{
    use HasPublicUuid;

    protected $fillable = ['lesson_id', 'instructions', 'due_days', 'max_score', 'pass_score', 'file_types', 'max_file_mb', 'allow_text'];

    protected $attributes = ['instructions' => null, 'due_days' => null, 'max_score' => 100, 'pass_score' => null, 'file_types' => null, 'max_file_mb' => 20, 'allow_text' => true];

    protected function casts(): array
    {
        return ['due_days' => 'integer', 'max_score' => 'integer', 'pass_score' => 'integer', 'file_types' => 'array', 'max_file_mb' => 'integer', 'allow_text' => 'boolean'];
    }

    /** @return BelongsTo<Lesson, $this> */
    public function lesson(): BelongsTo
    {
        return $this->belongsTo(Lesson::class);
    }

    /** @return HasMany<AssignmentSubmission, $this> */
    public function submissions(): HasMany
    {
        return $this->hasMany(AssignmentSubmission::class);
    }
}
