<?php

namespace App\Instructors;

use App\Enums\Role;
use App\Models\InstructorApplication;
use App\Models\InstructorProfile;
use App\Models\User;
use App\Notifications\Instructors\InstructorApplicationDecidedNotification;
use App\Notifications\Instructors\InstructorApplicationReceivedNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;

/** apply → admin approves (role + profile) or rejects with notes. Rejected applicants may apply again. */
class InstructorApplicationService
{
    /** @param array{headline: string, expertise: list<string>, bio: string, motivation: string, links?: list<string>|null} $data */
    public function apply(User $user, array $data): InstructorApplication
    {
        if ($user->hasRole(Role::Instructor->value)) {
            throw ValidationException::withMessages(['application' => 'You are already an instructor.']);
        }
        if (InstructorApplication::where('user_id', $user->id)->where('status', 'pending')->exists()) {
            throw ValidationException::withMessages(['application' => 'Your application is already being reviewed.']);
        }

        $application = InstructorApplication::create(['user_id' => $user->id, ...$data]);
        $admins = User::role([Role::Admin->value, Role::SuperAdmin->value])->get();
        Notification::send($admins, new InstructorApplicationReceivedNotification($application));

        return $application;
    }

    public function approve(InstructorApplication $application, User $admin, ?string $notes = null): InstructorApplication
    {
        $this->assertPending($application);

        DB::transaction(function () use ($application, $admin, $notes) {
            $user = $application->user;
            $user->assignRole(Role::Instructor->value);
            $profile = $user->profileOrNew();
            $profile->fill(['headline' => $application->headline, 'expertise' => $application->expertise])->save();
            if (! $user->bio) {
                $user->forceFill(['bio' => $application->bio])->save();
            }
            InstructorProfile::firstOrCreate(['user_id' => $user->id])->forceFill(['approved_at' => now()])->save();
            $application->forceFill(['status' => 'approved', 'reviewed_by' => $admin->id, 'reviewed_at' => now(), 'review_notes' => $notes])->save();
        });
        $application->user->notify(new InstructorApplicationDecidedNotification($application));

        return $application;
    }

    public function reject(InstructorApplication $application, User $admin, string $notes): InstructorApplication
    {
        $this->assertPending($application);
        $application->forceFill(['status' => 'rejected', 'reviewed_by' => $admin->id, 'reviewed_at' => now(), 'review_notes' => $notes])->save();
        $application->user->notify(new InstructorApplicationDecidedNotification($application));

        return $application;
    }

    private function assertPending(InstructorApplication $application): void
    {
        if ($application->status !== 'pending') {
            throw ValidationException::withMessages(['application' => 'This application was already decided.']);
        }
    }
}
