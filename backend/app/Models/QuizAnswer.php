<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property int $attempt_id
 * @property int $question_id
 * @property list<int>|null $selected_option_ids
 * @property string|null $text_answer
 * @property bool $is_correct
 * @property int $points_awarded
 */
class QuizAnswer extends Model
{
    protected $fillable = ['attempt_id', 'question_id', 'selected_option_ids', 'text_answer', 'is_correct', 'points_awarded'];

    protected $attributes = ['selected_option_ids' => null, 'text_answer' => null, 'is_correct' => false, 'points_awarded' => 0];

    protected function casts(): array
    {
        return ['selected_option_ids' => 'array', 'is_correct' => 'boolean', 'points_awarded' => 'integer'];
    }
}
