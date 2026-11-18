<?php

namespace App\Learning;

use App\Models\Certificate;
use App\Models\Course;
use App\Models\User;
use App\Notifications\Learning\CertificateIssuedNotification;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class CertificateService
{
    /** Unambiguous alphabet (no 0/O, 1/I/L) for codes people may type. */
    private const ALPHABET = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';

    /** Idempotent: one certificate per learner per course. */
    public function issue(User $user, Course $course): Certificate
    {
        $certificate = DB::transaction(function () use ($user, $course) {
            $existing = Certificate::where('user_id', $user->id)->where('course_id', $course->id)->lockForUpdate()->first();
            if ($existing) {
                return $existing;
            }

            return Certificate::create([
                'user_id' => $user->id,
                'course_id' => $course->id,
                'verification_code' => $this->uniqueCode(),
                'holder_name' => $user->name,
                'course_title' => $course->title,
                'instructor_name' => $course->instructor->name,
                'issued_at' => now(),
            ]);
        });

        if ($certificate->wasRecentlyCreated) {
            $this->renderPdf($certificate);
            $user->notify(new CertificateIssuedNotification($certificate));
        }

        return $certificate;
    }

    public function renderPdf(Certificate $certificate): string
    {
        $pdf = Pdf::loadView('certificates.certificate', [
            'certificate' => $certificate,
            'brand' => config('lms.brand'),
            'verifyUrl' => $certificate->verificationUrl(),
        ])->setPaper('a4', 'landscape')->setOption('isFontSubsettingEnabled', true);

        $path = "certificates/{$certificate->user->uuid}/{$certificate->verification_code}.pdf";
        Storage::disk((string) config('billing.invoices.disk'))->put($path, $pdf->output());
        $certificate->forceFill(['pdf_path' => $path])->save();

        return $path;
    }

    /** LinkedIn "Add licence or certification" prefill URL. */
    public function linkedInUrl(Certificate $certificate): string
    {
        return 'https://www.linkedin.com/profile/add?'.http_build_query([
            'startTask' => 'CERTIFICATION_NAME',
            'name' => $certificate->course_title,
            'organizationName' => (string) config('lms.brand.name'),
            'issueYear' => $certificate->issued_at->year,
            'issueMonth' => $certificate->issued_at->month,
            'certUrl' => $certificate->verificationUrl(),
            'certId' => $certificate->verification_code,
        ]);
    }

    private function uniqueCode(): string
    {
        do {
            $code = '';
            for ($i = 0; $i < 10; $i++) {
                $code .= self::ALPHABET[random_int(0, strlen(self::ALPHABET) - 1)];
            }
        } while (Certificate::where('verification_code', $code)->exists());

        return $code;
    }
}
