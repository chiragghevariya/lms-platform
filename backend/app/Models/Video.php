<?php

namespace App\Models;

use App\Enums\VideoStatus;
use App\Models\Concerns\HasPublicUuid;
use Database\Factories\VideoFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * @property int $id
 * @property string $uuid
 * @property int|null $uploaded_by
 * @property string $provider
 * @property string|null $provider_video_id
 * @property string $title
 * @property VideoStatus $status
 * @property int|null $duration_seconds
 * @property string|null $thumbnail_url
 * @property string|null $error
 */
class Video extends Model
{
    /** @use HasFactory<VideoFactory> */
    use HasFactory, HasPublicUuid, SoftDeletes;

    protected $fillable = ['uploaded_by', 'provider', 'provider_video_id', 'title', 'status', 'duration_seconds', 'thumbnail_url', 'error'];

    protected $attributes = ['provider_video_id' => null, 'duration_seconds' => null, 'thumbnail_url' => null, 'error' => null];

    protected function casts(): array
    {
        return ['status' => VideoStatus::class, 'duration_seconds' => 'integer'];
    }

    /** @return BelongsTo<User, $this> */
    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    public function isReady(): bool
    {
        return $this->status === VideoStatus::Ready;
    }
}
