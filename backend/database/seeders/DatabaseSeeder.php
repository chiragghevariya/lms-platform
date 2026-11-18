<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            RolesAndPermissionsSeeder::class,
            PlanSeeder::class,
            BadgeSeeder::class,
            SuperAdminSeeder::class,
        ]);

        if (! app()->isProduction()) {
            $this->call([DemoUserSeeder::class, CourseSeeder::class, LearningDemoSeeder::class, EarningsDemoSeeder::class]);
        }
    }
}
