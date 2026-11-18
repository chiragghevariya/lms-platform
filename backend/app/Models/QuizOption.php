<?php

namespace App\Models;

use App\Models\Concerns\HasPublicUuid;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property string $uuid
 * @property int $question_id
 * @property string $text
 * @property bool $is_correct
 * @property int $sort
 */
class QuizOption extends Model
{
    use HasPublicUuid;

    public $timestamps = false;

    protected $fillable = ['question_id', 'text', 'is_correct', 'sort'];

    protected $attributes = ['is_correct' => false, 'sort' => 0];

    protected function casts(): array
    {
        return ['is_correct' => 'boolean', 'sort' => 'integer'];
    }
}
