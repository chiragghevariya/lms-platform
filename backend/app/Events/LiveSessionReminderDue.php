<?php

namespace App\Events;

use App\Models\LiveRegistration;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A registrant should be reminded ($minutesBefore = 1440 or 15). Phase 8 (notifications) adds
 * channels — WhatsApp/SMS/push — as listeners; today SendLiveReminder emails.
 */
class LiveSessionReminderDue
{
    use Dispatchable;

    public function __construct(public LiveRegistration $registration, public int $minutesBefore) {}
}
