<?php

namespace App\Notify;

/** A pre-approved WhatsApp template message (free-form text isn't allowed outside the 24 h session window). */
final class WhatsAppMessage
{
    /** @param list<string> $params body variables {{1}}, {{2}}…; $buttonParam = OTP for authentication templates' copy-code button */
    public function __construct(public readonly string $template, public readonly string $language, public readonly array $params, public readonly ?string $fallbackText = null, public readonly ?string $buttonParam = null) {}
}
