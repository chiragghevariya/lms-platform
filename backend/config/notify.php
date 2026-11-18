<?php

/*
 * Notification events. For each event:
 *   channels   default on/off per channel (users can change non-mandatory ones)
 *   mandatory  channels users can't turn off (transactional / security)
 *   whatsapp   suggested pre-approved Meta template name + ordered body variables
 *   sms_fallback  send an SMS when WhatsApp isn't possible or fails
 *   vars       variables available in templates (for the admin editor)
 */
$m = fn (array $on, array $mandatory = [], array $extra = []) => [
    'channels' => array_merge(['mail' => false, 'database' => false, 'whatsapp' => false, 'push' => false, 'sms' => false], array_fill_keys($on, true)),
    'mandatory' => $mandatory,
] + $extra;

return [
    'categories' => [
        'account' => 'Account & security',
        'billing' => 'Membership & billing',
        'learning' => 'Courses & learning',
        'live' => 'Live classes',
        'affiliate' => 'Affiliate programme',
        'marketing' => 'Announcements & tips',
        'teaching' => 'Teaching',
        'admin' => 'Administration',
    ],

    'events' => [
        // Account
        'welcome' => ['category' => 'account', 'label' => 'Welcome message', 'vars' => ['name', 'url']] + $m(['mail', 'database', 'whatsapp'], [], ['whatsapp' => ['template' => 'welcome', 'params' => ['name']]]),
        'email_verify' => ['category' => 'account', 'label' => 'Email verification', 'vars' => ['name', 'url']] + $m(['mail'], ['mail']),
        'password_reset' => ['category' => 'account', 'label' => 'Password reset', 'vars' => ['name', 'url']] + $m(['mail'], ['mail']),
        'otp' => ['category' => 'account', 'label' => 'One-time codes', 'vars' => ['code', 'minutes']] + $m(['sms'], ['sms'], ['whatsapp' => ['template' => 'otp_code', 'params' => ['code'], 'auth' => true]]),

        // Billing
        'subscription_started' => ['category' => 'billing', 'label' => 'Membership started', 'vars' => ['name', 'plan', 'url']] + $m(['mail', 'database', 'whatsapp', 'push'], [], ['whatsapp' => ['template' => 'membership_started', 'params' => ['name', 'plan']]]),
        'subscription_renewed' => ['category' => 'billing', 'label' => 'Membership renewed', 'vars' => ['name', 'plan', 'amount', 'url']] + $m(['database', 'push']),
        'invoice' => ['category' => 'billing', 'label' => 'Payment receipts & invoices', 'vars' => ['name', 'amount', 'invoice_number', 'url']] + $m(['mail', 'database'], ['mail']),
        'payment_failed' => ['category' => 'billing', 'label' => 'Payment failed', 'vars' => ['name', 'plan', 'amount', 'url']] + $m(['mail', 'database', 'whatsapp', 'push'], ['mail'], ['sms_fallback' => true, 'whatsapp' => ['template' => 'payment_failed', 'params' => ['name', 'plan', 'url']]]),
        'subscription_expiring_7d' => ['category' => 'billing', 'label' => 'Membership ends in 7 days', 'vars' => ['name', 'plan', 'date', 'url']] + $m(['mail', 'database', 'push']),
        'subscription_expiring_1d' => ['category' => 'billing', 'label' => 'Membership ends tomorrow', 'vars' => ['name', 'plan', 'date', 'url']] + $m(['mail', 'database', 'whatsapp', 'push'], [], ['whatsapp' => ['template' => 'membership_ending', 'params' => ['name', 'plan', 'date']]]),
        'subscription_cancelled' => ['category' => 'billing', 'label' => 'Membership ended', 'vars' => ['name', 'plan', 'url']] + $m(['mail', 'database']),

        // Learning
        'new_course' => ['category' => 'learning', 'label' => 'New course in your tier', 'vars' => ['name', 'course', 'url']] + $m(['mail', 'database', 'push']),
        'assignment_graded' => ['category' => 'learning', 'label' => 'Assignment graded', 'vars' => ['name', 'lesson', 'score', 'url']] + $m(['mail', 'database', 'push']),
        'certificate_issued' => ['category' => 'learning', 'label' => 'Certificate issued', 'vars' => ['name', 'course', 'code', 'url']] + $m(['mail', 'database', 'whatsapp', 'push'], [], ['whatsapp' => ['template' => 'certificate_issued', 'params' => ['name', 'course', 'url']]]),
        'question_answered' => ['category' => 'learning', 'label' => 'Answers to your questions', 'vars' => ['name', 'question', 'course', 'url']] + $m(['mail', 'database', 'push']),
        'course_announcement' => ['category' => 'learning', 'label' => 'Course announcements', 'vars' => ['name', 'course', 'title', 'url']] + $m(['mail', 'database', 'push']),
        'inactivity_7d' => ['category' => 'learning', 'label' => 'Nudge after 7 days away', 'vars' => ['name', 'course', 'url']] + $m(['mail', 'database', 'push']),
        'inactivity_14d' => ['category' => 'learning', 'label' => 'Nudge after 14 days away', 'vars' => ['name', 'course', 'url']] + $m(['mail', 'database', 'push']),

        // Live
        'live_registered' => ['category' => 'live', 'label' => 'Live class registration', 'vars' => ['name', 'title', 'when', 'url']] + $m(['mail', 'database']),
        'live_reminder_24h' => ['category' => 'live', 'label' => 'Live class tomorrow', 'vars' => ['name', 'title', 'when', 'url']] + $m(['mail', 'database', 'push']),
        'live_reminder_15m' => ['category' => 'live', 'label' => 'Live class in 15 minutes', 'vars' => ['name', 'title', 'when', 'url']] + $m(['mail', 'database', 'whatsapp', 'push'], [], ['whatsapp' => ['template' => 'live_starting', 'params' => ['name', 'title', 'url']]]),
        'live_cancelled' => ['category' => 'live', 'label' => 'Live class cancelled', 'vars' => ['name', 'title', 'when', 'url']] + $m(['mail', 'database', 'push']),

        // Affiliate
        'commission_earned' => ['category' => 'affiliate', 'label' => 'Commission earned', 'vars' => ['name', 'amount', 'url']] + $m(['mail', 'database', 'push']),
        'commission_paid' => ['category' => 'affiliate', 'label' => 'Commission paid out', 'vars' => ['name', 'amount', 'reference', 'url']] + $m(['mail', 'database'], ['mail']),

        // Marketing / campaigns
        'admin_announcement' => ['category' => 'marketing', 'label' => 'Announcements from us', 'vars' => ['name', 'url']] + $m(['mail', 'database', 'whatsapp']),

        // Teaching / admin (staff)
        'course_reviewed' => ['category' => 'teaching', 'label' => 'Course review decisions', 'vars' => ['name', 'course', 'url']] + $m(['mail', 'database']),
        'instructor_application' => ['category' => 'teaching', 'label' => 'Instructor application decision', 'vars' => ['name', 'url']] + $m(['mail', 'database']),
        'course_submitted' => ['category' => 'admin', 'label' => 'Course submitted for review', 'vars' => ['course', 'url']] + $m(['mail', 'database']),
        'instructor_applied' => ['category' => 'admin', 'label' => 'New instructor application', 'vars' => ['name', 'url']] + $m(['mail', 'database']),
        'contact_message' => ['category' => 'admin', 'label' => 'Contact form messages', 'vars' => ['name', 'url']] + $m(['mail']),
    ],

    // Per-channel send rate (jobs over the limit are released and retried).
    'rate_limits' => ['mail' => 10, 'whatsapp' => 20, 'sms' => 10, 'push' => 100, 'database' => 200], // per second

    'whatsapp' => [
        'token' => env('WHATSAPP_TOKEN'),
        'phone_number_id' => env('WHATSAPP_PHONE_NUMBER_ID'),
        'app_secret' => env('WHATSAPP_APP_SECRET'),          // webhook signature (X-Hub-Signature-256)
        'verify_token' => env('WHATSAPP_VERIFY_TOKEN'),      // webhook subscription handshake
        'graph_version' => env('WHATSAPP_GRAPH_VERSION', 'v26.0'),
        'language' => env('WHATSAPP_TEMPLATE_LANGUAGE', 'en'),
    ],

    'sms' => [
        'driver' => env('SMS_DRIVER', 'log'),                // log | msg91 | twilio
        'msg91' => [
            'authkey' => env('MSG91_AUTHKEY'),
            'otp_template_id' => env('MSG91_OTP_TEMPLATE_ID'),        // DLT-approved flow/template ids
            'alert_template_id' => env('MSG91_ALERT_TEMPLATE_ID'),
        ],
        'twilio' => [
            'sid' => env('TWILIO_ACCOUNT_SID'),
            'token' => env('TWILIO_AUTH_TOKEN'),
            'from' => env('TWILIO_FROM'),                              // number or Messaging Service SID (MG…)
        ],
    ],

    'fcm' => [
        'project_id' => env('FCM_PROJECT_ID'),
        'credentials' => env('FCM_CREDENTIALS'),            // path to the service-account JSON
    ],

    'mailgun' => ['webhook_signing_key' => env('MAILGUN_WEBHOOK_SIGNING_KEY')],

    'otp_channel' => env('OTP_CHANNEL', 'sms'),             // sms | whatsapp
    'inactivity_days' => [7, 14],
    'expiring_days' => [7, 1],
];
