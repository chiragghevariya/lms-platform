<?php

namespace Database\Seeders;

use App\Learning\GamificationService;
use App\Models\Badge;
use Illuminate\Database\Seeder;

class BadgeSeeder extends Seeder
{
    public function run(): void
    {
        foreach (GamificationService::BADGES as $key => [$name, $description, $icon]) {
            Badge::updateOrCreate(['key' => $key], ['name' => $name, 'description' => $description, 'icon' => $icon]);
        }
    }
}
