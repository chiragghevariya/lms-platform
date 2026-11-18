<?php

namespace App\Rules;

use App\Security\UploadScanner;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Http\UploadedFile;
use RuntimeException;

/** Rejects uploads the malware scanner flags (and, when configured, when it can't scan). */
class CleanUpload implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! $value instanceof UploadedFile || ! $value->isValid()) {
            return; // other rules report invalid uploads
        }

        try {
            $result = app(UploadScanner::class)->scan($value->getRealPath());
        } catch (RuntimeException $e) {
            report($e);
            if (config('security.upload_scan.fail_closed')) {
                $fail('We could not check this file for viruses right now. Please try again shortly.');
            }

            return;
        }

        if ($result !== true) {
            logger()->warning('Infected upload rejected', ['signature' => $result, 'user_id' => auth()->id()]);
            $fail('This file looks unsafe and was rejected.');
        }
    }
}
