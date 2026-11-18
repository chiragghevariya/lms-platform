<?php

namespace App\Models;

use App\Models\Concerns\HasPublicUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $uuid
 * @property int $user_id
 * @property string $headline
 * @property list<string> $expertise
 * @property string $bio
 * @property string $motivation
 * @property list<string>|null $links
 * @property string $status
 * @property int|null $reviewed_by
 * @property Carbon|null $reviewed_at
 * @property string|null $review_notes
 * @property Carbon|null $created_at
 * @property User $user
 */
class InstructorApplication extends Model
{
    use HasPublicUuid;

    protected $fillable = ['user_id', 'headline', 'expertise', 'bio', 'motivation', 'links', 'status'];

    protected $attributes = ['status' => 'pending'];

    protected function casts(): array
    {
        return ['expertise' => 'array', 'links' => 'array', 'reviewed_at' => 'datetime'];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
