<?php

return [
    // Share of net (ex-tax) subscription revenue that forms the instructor pool, split by watch minutes.
    'pool_percent' => (int) env('EARNINGS_POOL_PERCENT', 50),
    // Default instructor share of their direct course sales (overridable per instructor by admins).
    'default_revenue_share_percent' => (int) env('EARNINGS_DEFAULT_SHARE_PERCENT', 70),
    // Anti-gaming: at most this much of one member's watch time per day counts toward the pool.
    'max_counted_seconds_per_user_day' => (int) env('EARNINGS_MAX_SECONDS_PER_USER_DAY', 4 * 3600),
];
