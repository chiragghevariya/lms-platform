<?php

namespace App\Models;

use App\Models\Concerns\HasPublicUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property string $uuid
 * @property int $lesson_id
 * @property string $title
 * @property string $disk
 * @property string $path
 * @property string|null $mime
 * @property int $size
 * @property Lesson $lesson
 */
class LessonResource extends Model
{
    use HasPublicUuid;

    protected $fillable = ['lesson_id', 'title', 'disk', 'path', 'mime', 'size'];

    /** @return BelongsTo<Lesson, $this> */
    public function lesson(): BelongsTo
    {
        return $this->belongsTo(Lesson::class);
    }
}
