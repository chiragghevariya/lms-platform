<?php

namespace App\Courses;

use App\Billing\Data\CheckoutResult;
use App\Billing\Data\CheckoutUrls;
use App\Billing\GatewayException;
use App\Billing\GatewayResolver;
use App\Billing\SubscriptionSync;
use App\Enums\Gateway;
use App\Enums\PaymentStatus;
use App\Models\Course;
use App\Models\CoursePurchase;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/** One-time purchase of a single course (for non-members / members below its tier). */
class CoursePurchaseService
{
    public function __construct(
        private readonly GatewayResolver $gateways,
        private readonly SubscriptionSync $sync,
        private readonly CourseAccessService $access,
    ) {}

    public function checkout(User $user, Course $course): CheckoutResult
    {
        if (! $course->isPublished() || $course->price_amount === null || ! $course->currency) {
            throw new GatewayException('This course is not available for individual purchase.', 'not_for_sale');
        }
        if ($this->access->canAccessCourse($user, $course)) {
            throw new GatewayException('You already have access to this course.', 'already_has_access', 409);
        }

        $gateway = $this->gateways->for(strtoupper($course->currency) === 'INR' ? Gateway::Razorpay : Gateway::Stripe);
        $frontend = (string) config('lms.frontend_url');

        return $gateway->checkoutCourse($user, $course, new CheckoutUrls(
            $frontend.'/billing/success?course='.$course->slug,
            $frontend.'/courses/'.$course->slug,
        ));
    }

    /** Called from gateway webhooks / success-page sync. Idempotent. */
    public function complete(Gateway $gateway, string $reference, User $user, Course $course, int $amount, string $currency): CoursePurchase
    {
        $payment = $this->sync->recordPayment($gateway, $reference, [
            'user' => $user,
            'course' => $course,
            'amount' => $amount,
            'currency' => $currency,
            'status' => PaymentStatus::Succeeded,
            'description' => 'Course: '.$course->title,
        ]);

        return DB::transaction(function () use ($user, $course, $payment) {
            $purchase = CoursePurchase::firstOrCreate(
                ['user_id' => $user->id, 'course_id' => $course->id],
                ['payment_id' => $payment->id, 'purchased_at' => now()],
            );
            $this->access->enroll($user, $course);

            return $purchase;
        });
    }
}
