<?php

namespace App\Billing;

use Illuminate\Http\JsonResponse;
use RuntimeException;

/** A gateway call failed or an operation isn't possible; the message is safe to show users. */
class GatewayException extends RuntimeException
{
    public function __construct(string $message, public readonly string $errorCode = 'gateway_error', public readonly int $status = 422)
    {
        parent::__construct($message);
    }

    public function render(): JsonResponse
    {
        return response()->json(['message' => $this->getMessage(), 'code' => $this->errorCode], $this->status);
    }
}
