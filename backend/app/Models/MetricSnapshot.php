<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property Carbon $day
 * @property string $metric
 * @property string $dimension
 * @property int $value
 */
class MetricSnapshot extends Model
{
    protected $fillable = ['day', 'metric', 'dimension', 'value'];

    protected function casts(): array
    {
        return ['day' => 'date', 'value' => 'integer'];
    }
}
