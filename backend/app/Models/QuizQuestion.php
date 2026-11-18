<?php

namespace App\Models;

use App\Enums\QuestionType;
use App\Models\Concerns\HasPublicUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property string $uuid
 * @property int $quiz_id
 * @property QuestionType $type
 * @property string $prompt
 * @property int $points
 * @property string|null $explanation
 * @property list<string>|null $accepted_answers
 * @property int $sort
 */
class QuizQuestion extends Model
{
    use HasPublicUuid;

    protected $fillable = ['quiz_id', 'type', 'prompt', 'points', 'explanation', 'accepted_answers', 'sort'];

    protected $attributes = ['points' => 1, 'explanation' => null, 'accepted_answers' => null, 'sort' => 0];

    protected function casts(): array
    {
        return ['type' => QuestionType::class, 'points' => 'integer', 'accepted_answers' => 'array', 'sort' => 'integer'];
    }

    /** @return HasMany<QuizOption, $this> */
    public function options(): HasMany
    {
        return $this->hasMany(QuizOption::class, 'question_id')->orderBy('sort')->orderBy('id');
    }
}
