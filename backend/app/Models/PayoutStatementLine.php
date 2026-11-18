<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $statement_id
 * @property string $type
 * @property int|null $course_id
 * @property int|null $payment_id
 * @property string $description
 * @property int $watch_seconds
 * @property int $gross
 * @property int $amount
 * @property Course|null $course
 */
class PayoutStatementLine extends Model
{
    public $timestamps = false;

    protected $guarded = ['id'];

    /** @return BelongsTo<Course, $this> */
    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }
}
