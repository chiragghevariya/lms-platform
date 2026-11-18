<?php

return [
    // Cookie set by ?ref=CODE (and ?invite=CODE for student invites).
    'cookie' => ['name' => 'lms_ref', 'days' => (int) env('AFFILIATE_COOKIE_DAYS', 60)],
    // first_touch keeps the first affiliate within the cookie window; last_touch lets a newer click win.
    'attribution' => env('AFFILIATE_ATTRIBUTION', 'last_touch'),

    // Rate tiers. An affiliate's own commission_type/rate overrides its tier.
    'tiers' => [
        'standard' => ['type' => 'percent', 'rate' => 20],
        'partner' => ['type' => 'percent', 'rate' => 30],
    ],
    // Commission on the first payment and renewals paid within this many months of the first one (0 = first payment only).
    'recurring_months' => (int) env('AFFILIATE_RECURRING_MONTHS', 12),
    'include_course_sales' => (bool) env('AFFILIATE_INCLUDE_COURSE_SALES', true),
    // Optional second level: the affiliate who recruited the affiliate earns this % of the same base.
    'level2' => ['enabled' => (bool) env('AFFILIATE_LEVEL2_ENABLED', false), 'percent' => (int) env('AFFILIATE_LEVEL2_PERCENT', 5)],
    'hold_days' => (int) env('AFFILIATE_HOLD_DAYS', 14),

    'fraud' => [
        'max_signups_per_hour' => 20,          // per affiliate, above → flagged
        'max_signups_per_ip_per_day' => 3,     // same affiliate + same IP, above → flagged
        'affiliate_ip_lookback_days' => 90,    // compare with the affiliate's own login IPs / devices
    ],

    'payouts' => [
        'minimum' => ['INR' => (int) env('AFFILIATE_MIN_PAYOUT_INR', 100000), 'USD' => (int) env('AFFILIATE_MIN_PAYOUT_USD', 2500)],
    ],

    // Student "invite a friend": reward when the friend's first paid membership clears the hold period.
    'student_rewards' => [
        'enabled' => (bool) env('STUDENT_REFERRALS_ENABLED', true),
        'type' => env('STUDENT_REFERRAL_REWARD', 'days'), // days | credit
        'days' => (int) env('STUDENT_REFERRAL_DAYS', 30),
        'credit' => ['INR' => 50000, 'USD' => 500],
        'max_per_year' => 12,
    ],

    'assets' => [
        'swipe_copy' => [
            ['title' => 'Short post', 'body' => "I've been learning on {brand} — practical skills that actually pay. Join with my link: {link}"],
            ['title' => 'WhatsApp message', 'body' => 'Hey! If you want to learn freelancing, ads or video editing, {brand} is great. Use my link to join: {link}'],
            ['title' => 'Email', 'body' => "Subject: The course platform I recommend\n\nHi,\n\nI've been using {brand} to learn skills that bring in income. Members get every course in their tier plus live classes.\n\nHave a look: {link}\n\nCheers"],
        ],
    ],
];
