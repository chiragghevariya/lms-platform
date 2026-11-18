<?php

namespace App\Console\Commands;

use App\Courses\CourseAccessService;
use App\Enums\CourseStatus;
use App\Enums\Gateway;
use App\Enums\SubscriptionStatus;
use App\Models\Course;
use App\Models\Lesson;
use App\Models\Plan;
use App\Models\PlanPrice;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * Seeds k6 load-test fixtures (NEVER in production): learners with an active membership and an
 * API token (heartbeats), free users with a token (checkout), and lists of course slugs, lesson
 * ids and price ids, written to tests/load/fixtures.json.
 */
class LoadTestPrepare extends Command
{
    protected $signature = 'loadtest:prepare {--learners=1000} {--buyers=200}';

    protected $description = 'Create k6 load-test users, tokens and fixtures (non-production only)';

    public function handle(): int
    {
        if (app()->isProduction()) {
            $this->error('Refusing to run in production.');

            return self::FAILURE;
        }

        $plan = Plan::where('tier_level', 3)->first() ?? Plan::orderByDesc('tier_level')->firstOrFail();
        $price = PlanPrice::where('plan_id', $plan->id)->where('is_active', true)->firstOrFail();
        $password = Hash::make('loadtest-password');

        $learnerTokens = [];
        $buyerTokens = [];
        $bar = $this->output->createProgressBar((int) $this->option('learners') + (int) $this->option('buyers'));

        foreach (range(1, (int) $this->option('learners')) as $i) {
            $u = User::firstOrCreate(['email' => "k6-learner-{$i}@loadtest.local"], ['name' => "K6 Learner {$i}", 'password' => $password]);
            $u->forceFill(['email_verified_at' => now()])->save();
            $u->assignRole('student');
            Subscription::firstOrCreate(['user_id' => $u->id, 'gateway' => Gateway::Manual], [
                'plan_id' => $plan->id, 'status' => SubscriptionStatus::Active, 'current_period_start' => now(),
            ]);
            PersonalAccessToken::query()->whereMorphedTo('tokenable', $u)->delete();
            $learnerTokens[] = $u->createToken('k6')->plainTextToken;
            $bar->advance();
        }
        foreach (range(1, (int) $this->option('buyers')) as $i) {
            $u = User::firstOrCreate(['email' => "k6-buyer-{$i}@loadtest.local"], ['name' => "K6 Buyer {$i}", 'password' => $password, 'country' => 'IN']);
            $u->forceFill(['email_verified_at' => now()])->save();
            $u->assignRole('student');
            PersonalAccessToken::query()->whereMorphedTo('tokenable', $u)->delete();
            $buyerTokens[] = $u->createToken('k6')->plainTextToken;
            $bar->advance();
        }
        $bar->finish();
        $this->newLine();

        $courses = Course::where('status', CourseStatus::Published)->pluck('slug')->all();
        // Only lessons a fresh member can actually watch (not drip-locked or purchase-only).
        $sample = User::where('email', 'k6-learner-1@loadtest.local')->firstOrFail();
        $access = app(CourseAccessService::class);
        $lessons = Lesson::with('course')->where('type', 'video')
            ->whereHas('course', fn ($q) => $q->where('status', CourseStatus::Published))->limit(500)->get()
            ->filter(fn (Lesson $l) => $access->lessonState($sample, $l, $access->enrollmentFor($sample, $l->course))['unlocked'])
            ->take(200)->pluck('uuid')->values()->all();
        $prices = PlanPrice::where('is_active', true)->where('currency', 'INR')->where('interval', '!=', 'lifetime')->pluck('uuid')->all();
        $categories = DB::table('categories')->pluck('slug')->all();

        $path = base_path('tests/load/fixtures.json');
        @mkdir(dirname($path), 0755, true);
        file_put_contents($path, json_encode(compact('learnerTokens', 'buyerTokens', 'courses', 'lessons', 'prices', 'categories'), JSON_PRETTY_PRINT));

        $this->info(sprintf('Wrote %s: %d learners, %d buyers, %d courses, %d lessons, %d prices.', $path, count($learnerTokens), count($buyerTokens), count($courses), count($lessons), count($prices)));

        return self::SUCCESS;
    }
}
