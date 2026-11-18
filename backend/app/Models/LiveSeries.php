<?php

namespace App\Models;

use App\Models\Concerns\HasPublicUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $uuid
 * @property int $instructor_id
 * @property list<int> $weekdays
 * @property string $local_time
 * @property string $timezone
 * @property Carbon $starts_on
 * @property int $occurrences
 */
class LiveSeries extends Model
{
    use HasPublicUuid;

    protected $table = 'live_series';

    protected $fillable = ['instructor_id', 'weekdays', 'local_time', 'timezone', 'starts_on', 'occurrences'];

    protected function casts(): array
    {
        return ['weekdays' => 'array', 'starts_on' => 'date'];
    }

    /** @return HasMany<LiveSession, $this> */
    public function sessions(): HasMany
    {
        return $this->hasMany(LiveSession::class, 'series_id');
    }
}
