<?php

namespace App\Cms;

/**
 * Built-in homepage copy. The admin edits override these per section; a section the
 * admin never touched keeps the default, so a fresh install has a complete homepage.
 */
final class CmsDefaults
{
    /** @var list<string> fixed homepage sections, in default order */
    public const SECTIONS = ['hero', 'why', 'featured', 'pricing', 'instructors', 'testimonials', 'faq', 'cta'];

    /** @return array<string, array<string, mixed>> */
    public static function sections(): array
    {
        return [
            'hero' => [
                'badge' => 'New live cohorts every month',
                'title' => 'Learn Skills.',
                'highlight' => 'Build Income.',
                'subtitle' => 'One membership for practical courses, live classes and mentors — designed to turn what you learn into real earnings.',
                'primary_cta' => ['label' => 'Start learning', 'url' => '/register'],
                'secondary_cta' => ['label' => 'Browse courses', 'url' => '/courses'],
                'items' => [
                    ['title' => 'Courses', 'body' => 'Practical, project-based courses'],
                    ['title' => 'Live', 'body' => 'Learn with instructors in real time'],
                    ['title' => 'Mentors', 'body' => 'Mentors who earn from their skills'],
                    ['title' => 'Earn', 'body' => 'Refer friends and earn commission'],
                ],
            ],
            'why' => [
                'eyebrow' => 'Why us',
                'title' => 'Built for outcomes, not just certificates',
                'items' => [
                    ['title' => 'Skills people pay for', 'body' => 'Every course is built around a skill with clear earning paths — freelancing, jobs or your own business.'],
                    ['title' => 'Learn live, not alone', 'body' => 'Weekly live classes, Q&A and a community of learners keep you moving.'],
                    ['title' => 'Proof of skill', 'body' => 'Projects and certificates you can show clients and employers.'],
                    ['title' => 'Earn while you learn', 'body' => 'Refer friends with your affiliate link and earn on every membership.'],
                ],
            ],
            'featured' => ['eyebrow' => 'Featured', 'title' => 'Courses learners love', 'intro' => "A preview of what's inside your membership."],
            'pricing' => ['eyebrow' => 'Membership', 'title' => 'One plan, everything in your tier', 'intro' => 'Higher tiers include everything below them.'],
            'instructors' => ['eyebrow' => 'Mentors', 'title' => 'Learn from people who earn from it'],
            'testimonials' => ['eyebrow' => 'Stories', 'title' => 'What learners say'],
            'faq' => ['eyebrow' => 'FAQ', 'title' => 'Questions, answered'],
            'cta' => [
                'title' => 'Your next income skill starts today',
                'subtitle' => "Join free, explore the platform, and upgrade when you're ready.",
                'primary_cta' => ['label' => 'Create free account', 'url' => '/register'],
            ],
        ];
    }

    /**
     * Validation for a section's content (same shape for every key; unused fields are ignored).
     *
     * @return array<string, mixed>
     */
    public static function rules(): array
    {
        $cta = fn (string $k) => [
            "content.{$k}" => ['nullable', 'array'],
            "content.{$k}.label" => ["required_with:content.{$k}", 'nullable', 'string', 'max:40'],
            // Internal path ("/courses") or an absolute https URL.
            "content.{$k}.url" => ["required_with:content.{$k}", 'nullable', 'string', 'max:300', 'regex:#^(/[^\s]*|https?://[^\s]+)$#'],
        ];

        return [
            'content' => ['required', 'array'],
            'content.badge' => ['nullable', 'string', 'max:80'],
            'content.eyebrow' => ['nullable', 'string', 'max:40'],
            'content.title' => ['nullable', 'string', 'max:120'],
            'content.highlight' => ['nullable', 'string', 'max:80'],
            'content.subtitle' => ['nullable', 'string', 'max:400'],
            'content.intro' => ['nullable', 'string', 'max:300'],
            ...$cta('primary_cta'),
            ...$cta('secondary_cta'),
            'content.items' => ['nullable', 'array', 'max:8'],
            'content.items.*.title' => ['required', 'string', 'max:80'],
            'content.items.*.body' => ['nullable', 'string', 'max:300'],
            'is_visible' => ['sometimes', 'boolean'],
            'sort' => ['sometimes', 'integer', 'min:0', 'max:100'],
        ];
    }

    /** Default legal pages are rendered by the SPA until an admin saves a CMS version. */
    public const LEGAL_PAGES = ['terms' => 'Terms of Service', 'privacy' => 'Privacy Policy', 'refund-policy' => 'Refund Policy', 'cookies' => 'Cookie Policy'];
}
