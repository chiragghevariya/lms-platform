<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Indexes for the hot read paths: catalogue listing, analytics (payments/subscriptions by
 * date), admin tables and learning dashboards. Skips any index that already exists.
 */
return new class extends Migration
{
    /** @var array<string, list<list<string>>> */
    private const INDEXES = [
        'payments' => [['status', 'currency', 'paid_at'], ['refunded_at'], ['course_id', 'paid_at'], ['subscription_id', 'status'], ['created_at']],
        'subscriptions' => [['ended_at'], ['created_at'], ['trial_ends_at'], ['plan_id', 'status']],
        'users' => [['created_at'], ['country'], ['last_login_at']],
        'courses' => [['status', 'is_featured', 'enrollments_count'], ['category_id', 'status'], ['instructor_id', 'status']],
        'enrollments' => [['course_id', 'started_at'], ['started_at']],
        'course_progress' => [['course_id', 'completed_at'], ['completed_at']],
        'course_reviews' => [['course_id', 'hidden_at', 'created_at']],
        'commissions' => [['currency', 'created_at'], ['affiliate_id', 'status']],
        'activity_log' => [['event', 'created_at'], ['created_at']],
        'notification_logs' => [['created_at']],
    ];

    public function up(): void
    {
        foreach (self::INDEXES as $table => $indexes) {
            if (! Schema::hasTable($table)) {
                continue;
            }
            foreach ($indexes as $cols) {
                $name = $table.'_'.implode('_', $cols).'_perf';
                if (Schema::hasIndex($table, $cols) || Schema::hasIndex($table, $name)) {
                    continue;
                }
                Schema::table($table, fn (Blueprint $t) => $t->index($cols, $name));
            }
        }
    }

    public function down(): void
    {
        foreach (self::INDEXES as $table => $indexes) {
            foreach ($indexes as $cols) {
                $name = $table.'_'.implode('_', $cols).'_perf';
                if (Schema::hasIndex($table, $name)) {
                    Schema::table($table, fn (Blueprint $t) => $t->dropIndex($name));
                }
            }
        }
    }
};
