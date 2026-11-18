<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property string $key
 * @property string $name
 * @property string $description
 * @property string $icon
 */
class Badge extends Model
{
    protected $fillable = ['key', 'name', 'description', 'icon'];
}
