<?php

namespace App\Exceptions;

use Illuminate\Http\JsonResponse;
use RuntimeException;

/**
 * Auth failures that carry a machine-readable `code` so clients can branch
 * (account_locked, account_suspended, two_factor_invalid, otp_invalid, ...).
 */
class AuthFlowException extends RuntimeException
{
    /** @param  array<string, mixed>  $extra */
    public function __construct(
        string $message,
        public readonly string $errorCode,
        public readonly int $status = 422,
        public readonly array $extra = [],
    ) {
        parent::__construct($message);
    }

    public static function locked(int $retryAfterSeconds): self
    {
        $minutes = max(1, (int) ceil($retryAfterSeconds / 60));

        return new self(
            "Too many failed attempts. Your account is locked for {$minutes} minute(s).",
            'account_locked',
            423,
            ['retry_after' => $retryAfterSeconds],
        );
    }

    public static function blocked(): self
    {
        return new self('This account has been suspended. Please contact support.', 'account_suspended', 403);
    }

    public function render(): JsonResponse
    {
        return response()->json(['message' => $this->getMessage(), 'code' => $this->errorCode, ...$this->extra], $this->status);
    }
}
