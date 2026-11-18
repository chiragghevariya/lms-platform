<?php

namespace App\Courses;

use App\Enums\MembershipTier;
use App\Models\Course;
use App\Models\CoursePurchase;
use App\Models\Enrollment;
use App\Models\Lesson;
use App\Models\User;
use App\Services\MembershipService;
use Illuminate\Support\Carbon;

/**
 * Who may see what:
 *  - staff & the course's instructors: everything (drafts included, drip bypassed)
 *  - published course: members whose tier passes MembershipService::canAccess, or buyers of the course
 *  - preview lessons: anyone (guests included)
 *  - drip: lesson unlocks `drip_days` after the learner's enrollment started
 */
class CourseAccessService
{
    public function __construct(private readonly MembershipService $membership) {}

    public function canManage(?User $user, Course $course): bool
    {
        return $user !== null && ($user->isStaff() || $course->isTaughtBy($user));
    }

    public function hasPurchased(?User $user, Course $course): bool
    {
        return $user !== null && CoursePurchase::where('user_id', $user->id)->where('course_id', $course->id)->exists();
    }

    /** @return 'manage'|'purchase'|'tier'|null */
    public function accessReason(?User $user, Course $course): ?string
    {
        if ($this->canManage($user, $course)) {
            return 'manage';
        }
        if (! $user || ! $course->isPublished()) {
            return null;
        }
        if ($this->membership->canAccess($user, $course)) {
            return 'tier';
        }

        return $this->hasPurchased($user, $course) ? 'purchase' : null;
    }

    public function canAccessCourse(?User $user, Course $course): bool
    {
        return $this->accessReason($user, $course) !== null;
    }

    /**
     * @return array{unlocked: bool, reason: string, required_tier: MembershipTier|null, available_at: Carbon|null}
     *                                                                                                              reason: preview | access | login_required | membership_required | drip_locked | unpublished
     */
    public function lessonState(?User $user, Lesson $lesson, ?Enrollment $enrollment = null): array
    {
        $course = $lesson->course;
        $base = ['required_tier' => $course->requiredTier(), 'available_at' => null];

        if (! $course->isPublished() && ! $this->canManage($user, $course)) {
            return ['unlocked' => false, 'reason' => 'unpublished'] + $base;
        }
        if ($lesson->is_preview) {
            return ['unlocked' => true, 'reason' => 'preview'] + $base;
        }

        $reason = $this->accessReason($user, $course);
        if ($reason === null) {
            return ['unlocked' => false, 'reason' => $user ? 'membership_required' : 'login_required'] + $base;
        }

        if ($reason !== 'manage' && $lesson->drip_days > 0) {
            $start = $enrollment->started_at ?? now();
            $availableAt = $start->copy()->addDays($lesson->drip_days);
            if ($availableAt->isFuture()) {
                return ['unlocked' => false, 'reason' => 'drip_locked', 'available_at' => $availableAt] + $base;
            }
        }

        return ['unlocked' => true, 'reason' => 'access'] + $base;
    }

    /** Auto-enrolment on first access (no manual enroll step); starts the drip clock. */
    public function enroll(User $user, Course $course): Enrollment
    {
        $enrollment = Enrollment::firstOrCreate(
            ['user_id' => $user->id, 'course_id' => $course->id],
            ['source' => $this->accessReason($user, $course) ?? 'preview', 'started_at' => now()],
        );

        if ($enrollment->wasRecentlyCreated) {
            Course::whereKey($course->id)->increment('enrollments_count');
        }

        return $enrollment;
    }

    public function enrollmentFor(?User $user, Course $course): ?Enrollment
    {
        return $user ? Enrollment::where('user_id', $user->id)->where('course_id', $course->id)->first() : null;
    }
}
