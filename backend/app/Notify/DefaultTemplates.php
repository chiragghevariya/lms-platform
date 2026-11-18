<?php

namespace App\Notify;

/** Built-in copy. Mail bodies left null use the notification class's own (richer) mail. */
class DefaultTemplates
{
    /** @return array{subject?: string|null, body?: string|null, action_text?: string|null} */
    public static function for(string $event, string $channel): array
    {
        $t = self::all()[$event] ?? [];
        $short = $t['short'] ?? ['{{subject}}', ''];
        [$title, $text] = $short;

        return match ($channel) {
            'mail' => ['subject' => $t['subject'] ?? $title, 'body' => $t['mail'] ?? null, 'action_text' => $t['action'] ?? 'Open'],
            'database', 'push' => ['subject' => $title, 'body' => $text],
            'sms', 'whatsapp' => ['subject' => null, 'body' => $t['sms'] ?? trim($title.'. '.$text)],
            default => [],
        };
    }

    /** @return array<string, array{short?: array{0: string, 1: string}, subject?: string, mail?: string|null, action?: string, sms?: string}> */
    public static function all(): array
    {
        $brand = (string) config('lms.brand.name');

        return [
            'welcome' => ['short' => ["Welcome to {$brand}, {{name}}!", 'Start with a course in your tier or join a live class.'],
                'mail' => "Hi {{name}},\n\nWelcome to **{$brand}** — learn skills, build income.\n\nHere's how to get going:\n\n1. Pick a course from the catalogue\n2. Join an upcoming live class\n3. Invite friends and earn free days", 'action' => 'Start learning'],
            'email_verify' => ['short' => ['Verify your email', 'Confirm your email address to secure your account.']],
            'password_reset' => ['short' => ['Reset your password', 'Use the link we emailed you to choose a new password.']],
            'otp' => ['short' => ['Your code', '{{code}}'], 'sms' => "{{code}} is your {$brand} verification code. It expires in {{minutes}} minutes. Don't share it."],
            'subscription_started' => ['short' => ['Your {{plan}} membership is active', 'Every course in your tier is now unlocked.']],
            'subscription_renewed' => ['short' => ['Membership renewed', 'Your {{plan}} membership renewed ({{amount}}). Thanks for learning with us!']],
            'invoice' => ['short' => ['Payment received', 'Receipt {{invoice_number}} for {{amount}} is ready.']],
            'payment_failed' => ['short' => ['Payment failed', "We couldn't renew your {{plan}} membership. Update your payment method to keep access."],
                'sms' => "{$brand}: your {{plan}} payment failed. Update your payment method to keep access: {{url}}"],
            'subscription_expiring_7d' => ['short' => ['Your membership ends in 7 days', 'Your {{plan}} membership ends on {{date}}. Renew to keep your progress going.'],
                'mail' => "Hi {{name}},\n\nYour **{{plan}}** membership ends on **{{date}}**. After that, courses in your tier lock again — your progress and certificates stay safe.", 'action' => 'Renew membership'],
            'subscription_expiring_1d' => ['short' => ['Your membership ends tomorrow', 'Your {{plan}} membership ends on {{date}}.'],
                'mail' => "Hi {{name}},\n\nJust a heads-up: your **{{plan}}** membership ends **tomorrow ({{date}})**.", 'action' => 'Renew membership'],
            'subscription_cancelled' => ['short' => ['Membership ended', 'Your {{plan}} membership has ended. You can rejoin any time.']],
            'new_course' => ['short' => ['New course: {{course}}', "It's included in your membership — start now."],
                'mail' => "Hi {{name}},\n\nA new course just went live in your tier: **{{course}}**.", 'action' => 'View course'],
            'assignment_graded' => ['short' => ['Assignment graded', '“{{lesson}}” was graded: {{score}}.']],
            'certificate_issued' => ['short' => ['Certificate issued 🎉', 'You completed {{course}}. Add it to LinkedIn!']],
            'question_answered' => ['short' => ['Your question got an answer', '“{{question}}” in {{course}}']],
            'course_announcement' => ['short' => ['{{course}}: {{title}}', 'New announcement from your instructor.']],
            'inactivity_7d' => ['short' => ['Pick up where you left off', 'Your next lesson in {{course}} is waiting — 10 minutes today keeps the streak alive.'],
                'mail' => "Hi {{name}},\n\nIt's been a week since your last lesson in **{{course}}**. Ten focused minutes today is enough to keep momentum.", 'action' => 'Resume learning'],
            'inactivity_14d' => ['short' => ['We miss you, {{name}}', 'Two weeks away — your progress in {{course}} is saved. Jump back in?'],
                'mail' => "Hi {{name}},\n\nYour progress in **{{course}}** is right where you left it. Come back for one lesson — that's how skills (and income) compound.", 'action' => 'Resume learning'],
            'live_registered' => ['short' => ["You're registered: {{title}}", '{{when}}']],
            'live_reminder_24h' => ['short' => ['Tomorrow: {{title}}', '{{when}}']],
            'live_reminder_15m' => ['short' => ['Starting in 15 minutes: {{title}}', 'Join from the live room.']],
            'live_cancelled' => ['short' => ['Cancelled: {{title}}', 'The live class on {{when}} was cancelled.']],
            'commission_earned' => ['short' => ['You earned a commission', '{{amount}} — payable after the hold period.'],
                'mail' => "Hi {{name}},\n\nSomeone you referred just paid — you earned **{{amount}}**. It becomes payable after the hold period.", 'action' => 'Open affiliate dashboard'],
            'commission_paid' => ['short' => ['Payout sent', '{{amount}} was paid out (ref {{reference}}).'],
                'mail' => "Hi {{name}},\n\nWe've sent your affiliate payout of **{{amount}}**. Transfer reference: **{{reference}}**.", 'action' => 'View payouts'],
            'admin_announcement' => ['short' => ['{{subject}}', '']],
            'course_reviewed' => ['short' => ['Course review: {{course}}', 'Your course was reviewed.']],
            'instructor_application' => ['short' => ['Your instructor application', 'There is an update on your application.']],
            'course_submitted' => ['short' => ['Course submitted for review', '{{course}}']],
            'instructor_applied' => ['short' => ['New instructor application', '{{name}} applied to teach.']],
            'contact_message' => ['short' => ['New contact message', 'From {{name}}']],
        ];
    }
}
