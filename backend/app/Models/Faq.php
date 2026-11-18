<?php

namespace App\Models;

use App\Models\Concerns\Audited;
use App\Models\Concerns\HasPublicUuid;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property string $uuid
 * @property string $question
 * @property string $answer
 * @property string $category
 * @property bool $is_published
 * @property int $sort
 */
class Faq extends Model
{
    use Audited, HasPublicUuid;

    protected $fillable = ['question', 'answer', 'category', 'is_published', 'sort'];

    protected $attributes = ['category' => 'general', 'is_published' => true, 'sort' => 0];

    protected function casts(): array
    {
        return ['is_published' => 'boolean', 'sort' => 'integer'];
    }
}
