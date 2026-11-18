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
 * @property int $course_id
 * @property string $verification_code
 * @property string $holder_name
 * @property string $course_title
 * @property string $instructor_name
 * @property Carbon $issued_at
 * @property string|null $pdf_path
 * @property Carbon|null $revoked_at
 * @property User $user
 * @property Course $course
 */
class Certificate extends Model
{
    use HasPublicUuid;

    protected $fillable = ['user_id', 'course_id', 'verification_code', 'holder_name', 'course_title', 'instructor_name', 'issued_at', 'pdf_path'];

    protected $attributes = ['pdf_path' => null, 'revoked_at' => null];

    protected function casts(): array
    {
        return ['issued_at' => 'datetime', 'revoked_at' => 'datetime'];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<Course, $this> */
    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class)->withTrashed();
    }

    public function verificationUrl(): string
    {
        return config('lms.frontend_url').'/verify/'.$this->verification_code;
    }
}
