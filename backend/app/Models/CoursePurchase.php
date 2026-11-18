<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $user_id
 * @property int $course_id
 * @property int|null $payment_id
 * @property Carbon $purchased_at
 */
class CoursePurchase extends Model
{
    protected $fillable = ['user_id', 'course_id', 'payment_id', 'purchased_at'];

    protected function casts(): array
    {
        return ['purchased_at' => 'datetime'];
    }

    /** @return BelongsTo<Course, $this> */
    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }
}
