<?php

namespace Database\Seeders;

use App\Enums\CourseStatus;
use App\Enums\Gateway;
use App\Enums\PaymentStatus;
use App\Models\Course;
use App\Models\InstructorProfile;
use App\Models\Payment;
use App\Models\PlanPrice;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Deterministic earnings data for October 2026 (non-production). Expected results — asserted by
 * tests/Feature/Instructors/EarningsTest and listed in docs/PROGRESS.md:
 *
 *   Subscriptions, net of tax: INR 1,000.00 + 2,000.00 (a refunded and a failed payment are ignored); USD 20.00
 *   Pool 50 %: INR 1,500.00 · USD 10.00
 *   Pool watch: Meera's course 6,000 s, Ivan's course 3,000 s (a complimentary member's views never count)
 *   Sales: Ivan's course INR 590.00 incl. 90.00 GST → net 500.00 × 60 % (custom share) = 300.00
 *          Meera's course USD 49.00 → × 70 % (default share) = 34.30
 *
 *   Meera  INR 1,000.00 (pool)            · USD 6.66 (pool) + 34.30 (sale) = 40.96
 *   Ivan   INR 500.00 (pool) + 300.00     · USD 3.33 (pool)
 */
class EarningsDemoSeeder extends Seeder
{
    public const PERIOD = '2026-10';

    public function run(): void
    {
        $meera = $this->instructor('meera@example.com', 'Meera Kapoor');
        $ivan = $this->instructor('instructor@example.com', 'Ivan Instructor');
        InstructorProfile::updateOrCreate(['user_id' => $ivan->id], ['revenue_share_percent' => 60]);
        InstructorProfile::firstOrCreate(['user_id' => $meera->id]);

        $courseA = $this->course($meera, 'Meta Ads from zero (earnings demo)');
        $courseB = $this->course($ivan, 'Freelance web basics (earnings demo)');
        $member = User::firstOrCreate(['email' => 'earnings-member@example.com'], ['name' => 'Earnings Member', 'password' => bcrypt(str()->random(32))]);
        // Reuse a real plan price (PlanSeeder) so demo data never adds plans; fall back to a factory one in bare test DBs.
        $price = PlanPrice::where('currency', 'INR')->where('interval', 'month')->first();
        $subscription = Subscription::where('user_id', $member->id)->first()
            ?? ($price ? Subscription::factory()->forPrice($price) : Subscription::factory())->create(['user_id' => $member->id]);

        $at = fn (int $day) => sprintf('%s-%02d 10:00:00', self::PERIOD, $day);
        $this->payment('demo_earn_sub_1', $member, $subscription, null, 'INR', 118000, 18000, PaymentStatus::Succeeded, $at(3));
        $this->payment('demo_earn_sub_2', $member, $subscription, null, 'INR', 236000, 36000, PaymentStatus::Succeeded, $at(12));
        $this->payment('demo_earn_sub_3', $member, $subscription, null, 'INR', 118000, 18000, PaymentStatus::Refunded, $at(15));
        $this->payment('demo_earn_sub_4', $member, $subscription, null, 'INR', 118000, 18000, PaymentStatus::Failed, $at(16));
        $this->payment('demo_earn_sub_usd', $member, $subscription, null, 'USD', 2000, 0, PaymentStatus::Succeeded, $at(20));
        $this->payment('demo_earn_sale_b', $member, null, $courseB, 'INR', 59000, 9000, PaymentStatus::Succeeded, $at(21));
        $this->payment('demo_earn_sale_a', $member, null, $courseA, 'USD', 4900, 0, PaymentStatus::Succeeded, $at(22));
        // Outside the period: must not count.
        $this->payment('demo_earn_sub_nov', $member, $subscription, null, 'INR', 118000, 18000, PaymentStatus::Succeeded, '2026-11-01 00:00:00');

        $complimentary = User::firstOrCreate(['email' => 'earnings-comp@example.com'], ['name' => 'Comp Member', 'password' => bcrypt(str()->random(32))]);
        foreach ([[$courseA, $member, 5, 4000, 4000], [$courseA, $member, 9, 2000, 2000], [$courseB, $member, 9, 3000, 3000], [$courseA, $complimentary, 9, 5000, 0]] as [$course, $user, $day, $seconds, $pool]) {
            DB::table('course_watch_daily')->updateOrInsert(
                ['course_id' => $course->id, 'user_id' => $user->id, 'day' => sprintf('%s-%02d', self::PERIOD, $day)],
                ['seconds' => $seconds, 'pool_seconds' => $pool],
            );
        }
    }

    private function instructor(string $email, string $name): User
    {
        $user = User::where('email', $email)->first() ?? User::factory()->instructor()->create(['email' => $email, 'name' => $name]);
        if (! $user->hasRole('instructor')) {
            $user->assignRole('instructor');
        }

        return $user;
    }

    private function course(User $instructor, string $title): Course
    {
        $course = Course::where('title', $title)->first() ?? Course::factory()->create(['instructor_id' => $instructor->id, 'title' => $title]);
        $course->forceFill(['required_tier' => 1, 'status' => CourseStatus::Published, 'published_at' => $course->published_at ?? now()])->save();

        return $course;
    }

    private function payment(string $ref, User $user, ?Subscription $sub, ?Course $course, string $currency, int $amount, int $tax, PaymentStatus $status, string $paidAt): void
    {
        Payment::updateOrCreate(['gateway_payment_id' => $ref], [
            'user_id' => $user->id, 'subscription_id' => $sub?->id, 'course_id' => $course?->id,
            'gateway' => $currency === 'INR' ? Gateway::Razorpay : Gateway::Stripe, 'status' => $status, 'currency' => $currency,
            'subtotal' => $amount - $tax, 'tax_amount' => $tax, 'amount' => $amount, 'tax_rate' => $tax ? '18.00' : '0',
            'description' => $course ? 'Course: '.$course->title : 'Membership', 'paid_at' => $status === PaymentStatus::Failed ? null : $paidAt,
            'invoice_number' => $status === PaymentStatus::Failed ? null : 'DEMO-'.strtoupper($ref),
        ]);
    }
}
