<?php

namespace App\Rules;

use App\Enums\Permission;
use App\Models\Video;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * A video can be attached only by the instructor who uploaded it (or staff), except that
 * the video already attached to this lesson/course may be kept. Stops one instructor from
 * re-publishing another course's paid video as a free preview.
 */
class OwnVideo implements ValidationRule
{
    public function __construct(private readonly ?int $currentVideoId = null) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if ($value === null) {
            return;
        }
        $video = Video::where('uuid', $value)->first(['id', 'uploaded_by']);
        $user = auth()->user();
        if (! $video) {
            $fail('The selected video does not exist.');

            return;
        }
        if ($video->id === $this->currentVideoId || $user?->can(Permission::CoursesManageAny->value) || $video->uploaded_by === $user?->getAuthIdentifier()) {
            return;
        }
        $fail('You can only use videos you uploaded.');
    }
}
