<?php

namespace App\Jobs;

use App\Learning\CertificateService;
use App\Models\Course;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

class IssueCertificate implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 3;

    public function __construct(public int $userId, public int $courseId)
    {
        $this->onQueue('default');
    }

    public function handle(CertificateService $certificates): void
    {
        $user = User::find($this->userId);
        $course = Course::withTrashed()->find($this->courseId);
        if ($user && $course) {
            $certificates->issue($user, $course);
        }
    }
}
