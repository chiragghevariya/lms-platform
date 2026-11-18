<?php

namespace App\Policies;

use App\Enums\CourseStatus;
use App\Enums\Permission;
use App\Models\Course;
use App\Models\User;

class CoursePolicy
{
    public function create(User $actor): bool
    {
        return $actor->can(Permission::CoursesManageOwn->value) || $actor->can(Permission::CoursesManageAny->value);
    }

    /** Owner, co-instructors and admins edit; in-review courses are frozen for instructors. */
    public function update(User $actor, Course $course): bool
    {
        if ($actor->can(Permission::CoursesManageAny->value)) {
            return true;
        }

        return $course->isTaughtBy($actor) && $course->status !== CourseStatus::Review;
    }

    public function view(User $actor, Course $course): bool
    {
        return $actor->can(Permission::CoursesManageAny->value) || $course->isTaughtBy($actor);
    }

    public function delete(User $actor, Course $course): bool
    {
        return $actor->can(Permission::CoursesManageAny->value)
            || ($course->instructor_id === $actor->id && $course->status === CourseStatus::Draft && $course->enrollments_count === 0);
    }

    public function submit(User $actor, Course $course): bool
    {
        return $course->isTaughtBy($actor) || $actor->can(Permission::CoursesManageAny->value);
    }

    public function review(User $actor): bool
    {
        return $actor->can(Permission::CoursesManageAny->value);
    }
}
