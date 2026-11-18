<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $user_id
 * @property string|null $headline
 * @property string|null $website
 * @property string|null $city
 * @property string|null $state
 * @property string|null $occupation
 * @property Carbon|null $date_of_birth
 * @property array<string, string>|null $social_links
 * @property list<string>|null $interests
 * @property list<string>|null $expertise
 * @property bool $is_featured
 * @property list<string>|null $skills
 * @property string|null $learning_goals
 * @property bool $is_public
 * @property string|null $public_handle
 */
class UserProfile extends Model
{
    protected $fillable = [
        'headline', 'website', 'city', 'state', 'occupation', 'date_of_birth',
        'social_links', 'interests', 'expertise', 'is_featured', 'skills', 'learning_goals', 'is_public', 'public_handle',
    ];

    protected $attributes = [
        'headline' => null, 'website' => null, 'city' => null, 'state' => null,
        'occupation' => null, 'date_of_birth' => null, 'social_links' => null,
        'interests' => null, 'expertise' => null, 'is_featured' => false,
        'skills' => null, 'learning_goals' => null, 'is_public' => false, 'public_handle' => null,
    ];

    protected function casts(): array
    {
        return [
            'date_of_birth' => 'date',
            'social_links' => 'array',
            'interests' => 'array',
            'expertise' => 'array',
            'is_featured' => 'boolean',
            'skills' => 'array',
            'is_public' => 'boolean',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
