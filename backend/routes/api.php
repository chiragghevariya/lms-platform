<?php

use App\Http\Controllers\Api\V1\AccountController;
use App\Http\Controllers\Api\V1\Admin;
use App\Http\Controllers\Api\V1\AffiliateBannerController;
use App\Http\Controllers\Api\V1\AffiliateController;
use App\Http\Controllers\Api\V1\AssignmentController;
use App\Http\Controllers\Api\V1\Auth;
use App\Http\Controllers\Api\V1\AvatarController;
use App\Http\Controllers\Api\V1\BillingController;
use App\Http\Controllers\Api\V1\CatalogController;
use App\Http\Controllers\Api\V1\CheckoutSyncController;
use App\Http\Controllers\Api\V1\ContactController;
use App\Http\Controllers\Api\V1\CourseEngagementController;
use App\Http\Controllers\Api\V1\Instructor;
use App\Http\Controllers\Api\V1\InstructorApplicationController;
use App\Http\Controllers\Api\V1\InstructorController;
use App\Http\Controllers\Api\V1\InviteController;
use App\Http\Controllers\Api\V1\InvoiceController;
use App\Http\Controllers\Api\V1\LearnController;
use App\Http\Controllers\Api\V1\LearnerController;
use App\Http\Controllers\Api\V1\LiveSessionController;
use App\Http\Controllers\Api\V1\LoginActivityController;
use App\Http\Controllers\Api\V1\MeController;
use App\Http\Controllers\Api\V1\NotificationController;
use App\Http\Controllers\Api\V1\NotificationWebhookController;
use App\Http\Controllers\Api\V1\PhoneVerificationController;
use App\Http\Controllers\Api\V1\PlanController;
use App\Http\Controllers\Api\V1\PlaybackController;
use App\Http\Controllers\Api\V1\QuestionController;
use App\Http\Controllers\Api\V1\QuizController;
use App\Http\Controllers\Api\V1\ReferralTrackingController;
use App\Http\Controllers\Api\V1\SessionController;
use App\Http\Controllers\Api\V1\SiteController;
use App\Http\Controllers\Api\V1\TwoFactorController;
use App\Http\Controllers\Api\V1\VideoWebhookController;
use App\Http\Controllers\Api\V1\WebhookController;
use App\Http\Controllers\Api\V1\ZoomWebhookController;
use App\Http\Middleware\AuditAdminWrite;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->name('api.v1.')->group(function () {
    // ---- Gateway webhooks (signature-verified, no auth, not rate limited) ----
    Route::post('/webhooks/stripe', [WebhookController::class, 'stripe'])->withoutMiddleware('throttle:api')->name('webhooks.stripe');
    Route::post('/webhooks/razorpay', [WebhookController::class, 'razorpay'])->withoutMiddleware('throttle:api')->name('webhooks.razorpay');

    Route::post('/webhooks/video', VideoWebhookController::class)->withoutMiddleware('throttle:api')->name('webhooks.video');
    Route::post('/webhooks/zoom', ZoomWebhookController::class)->withoutMiddleware('throttle:api')->name('webhooks.zoom');
    Route::get('/webhooks/whatsapp', [NotificationWebhookController::class, 'whatsappVerify'])->withoutMiddleware('throttle:api')->name('webhooks.whatsapp.verify');
    Route::post('/webhooks/whatsapp', [NotificationWebhookController::class, 'whatsapp'])->withoutMiddleware('throttle:api')->name('webhooks.whatsapp');
    Route::post('/webhooks/mailgun', [NotificationWebhookController::class, 'mailgun'])->withoutMiddleware('throttle:api')->name('webhooks.mailgun');
    Route::post('/webhooks/twilio', [NotificationWebhookController::class, 'twilio'])->withoutMiddleware('throttle:api')->name('webhooks.twilio');

    // Referral / invite tracking (public; sets signed attribution cookies) and affiliate banners.
    Route::post('/referrals/track', ReferralTrackingController::class)->middleware('throttle:30,1')->name('referrals.track');
    Route::get('/affiliate-banners/{size}/{code}.svg', AffiliateBannerController::class)->name('affiliate-banner');

    // Calendar file for one session (no secrets inside; links point at the in-app room).
    Route::get('/live-sessions/{session}/calendar.ics', [LiveSessionController::class, 'ics'])->name('live.ics');

    // ---- Catalogue (public; auth optional for access flags) ----
    Route::get('/categories', [CatalogController::class, 'categories'])->name('categories.index');
    Route::get('/courses', [CatalogController::class, 'index'])->name('courses.index');
    Route::get('/courses/{slug}', [CatalogController::class, 'show'])->name('courses.show');
    Route::get('/courses/{slug}/reviews', [CatalogController::class, 'reviews'])->name('courses.reviews');
    Route::get('/courses/{slug}/promo', [PlaybackController::class, 'promo'])->middleware('throttle:60,1')->name('courses.promo');
    Route::get('/lessons/{lesson}/playback', [PlaybackController::class, 'lesson'])->middleware('throttle:60,1')->name('lessons.playback');

    Route::get('/certificates/verify/{code}', [LearnerController::class, 'verify'])->middleware('throttle:30,1')->name('certificates.verify');
    Route::get('/learners/{handle}', [LearnerController::class, 'publicProfile'])->name('learners.show');

    Route::get('/ping', fn () => ['data' => ['status' => 'ok', 'version' => 'v1']])->name('ping');

    // ---- Auth (guest) ----------------------------------------------------
    Route::prefix('auth')->name('auth.')->middleware('throttle:auth')->group(function () {
        Route::post('/register', Auth\RegisterController::class)->middleware('captcha')->name('register');
        Route::post('/login', Auth\LoginController::class)->middleware('captcha')->name('login');
        Route::post('/forgot-password', [Auth\PasswordResetController::class, 'forgot'])->middleware('captcha')->name('password.email');
        Route::post('/reset-password', [Auth\PasswordResetController::class, 'reset'])->name('password.update');
        Route::post('/google/token', Auth\GoogleTokenController::class)->name('google.token');
    });
    Route::post('/auth/two-factor-challenge', Auth\TwoFactorChallengeController::class)
        ->middleware('throttle:two-factor')->name('auth.two-factor.challenge');

    // ---- Public catalog --------------------------------------------------
    Route::get('/plans', [PlanController::class, 'index'])->name('plans.index');
    Route::get('/plans/{plan}', [PlanController::class, 'show'])->name('plans.show');
    Route::get('/instructors', [InstructorController::class, 'index'])->name('instructors.index');
    Route::get('/auth/session', [MeController::class, 'session'])->name('auth.session');
    Route::post('/contact', [ContactController::class, 'store'])->middleware(['throttle:contact', 'captcha'])->name('contact.store');

    // Public site content (CMS) and settings for the SPA shell.
    Route::get('/site', [SiteController::class, 'site'])->name('site');
    Route::get('/cms/home', [SiteController::class, 'home'])->name('cms.home');
    Route::get('/cms/pages/{slug}', [SiteController::class, 'page'])->name('cms.page');
    Route::get('/blog', [SiteController::class, 'posts'])->name('blog.index');
    Route::get('/blog/{slug}', [SiteController::class, 'post'])->name('blog.show');

    // ---- Authenticated ---------------------------------------------------
    Route::middleware(['auth:sanctum', 'active'])->group(function () {
        Route::post('/auth/logout', Auth\LogoutController::class)->name('auth.logout');
        Route::post('/auth/email/verification-notification', [Auth\EmailVerificationController::class, 'send'])
            ->middleware('throttle:6,1')->name('verification.send');
        Route::get('/auth/email/verify/{id}/{hash}', [Auth\EmailVerificationController::class, 'verify'])
            ->middleware(['signed:relative', 'throttle:6,1'])->name('verification.verify');

        Route::get('/me', [MeController::class, 'show'])->name('me.show');
        Route::patch('/me', [MeController::class, 'update'])->name('me.update');
        Route::put('/me/password', [MeController::class, 'updatePassword'])->middleware('throttle:6,1')->name('me.password');
        Route::get('/me/export', [AccountController::class, 'export'])->middleware('throttle:3,60')->name('me.export');
        Route::get('/me/deletion-check', [AccountController::class, 'deletionCheck'])->name('me.deletion-check');
        Route::delete('/me', [AccountController::class, 'destroy'])->middleware('throttle:5,60')->name('me.destroy');
        Route::get('/me/subscription', [BillingController::class, 'subscription'])->name('me.subscription');
        Route::get('/me/access', [MeController::class, 'access'])->name('me.access');
        // ---- Affiliates & invites --------------------------------------
        Route::get('/me/affiliate', [AffiliateController::class, 'show'])->name('me.affiliate');
        Route::post('/me/affiliate', [AffiliateController::class, 'apply'])->middleware('throttle:5,1')->name('me.affiliate.apply');
        Route::put('/me/affiliate/payout', [AffiliateController::class, 'updatePayout'])->name('me.affiliate.payout');
        Route::get('/me/affiliate/stats', [AffiliateController::class, 'stats'])->name('me.affiliate.stats');
        Route::get('/me/affiliate/commissions', [AffiliateController::class, 'commissions'])->name('me.affiliate.commissions');
        Route::get('/me/affiliate/payouts', [AffiliateController::class, 'payouts'])->name('me.affiliate.payouts');
        Route::get('/me/affiliate/assets', [AffiliateController::class, 'assets'])->name('me.affiliate.assets');
        Route::get('/me/invite', InviteController::class)->name('me.invite');
        Route::post('/me/avatar', [AvatarController::class, 'store'])->middleware('throttle:uploads')->name('me.avatar.store');
        Route::delete('/me/avatar', [AvatarController::class, 'destroy'])->name('me.avatar.destroy');

        Route::post('/me/phone/otp', [PhoneVerificationController::class, 'send'])->middleware('throttle:otp')->name('me.phone.otp');
        Route::post('/me/phone/verify', [PhoneVerificationController::class, 'verify'])->middleware('throttle:10,1')->name('me.phone.verify');

        Route::get('/me/sessions', [SessionController::class, 'index'])->name('me.sessions.index');
        Route::post('/me/sessions/logout-others', [SessionController::class, 'destroyOthers'])->middleware('throttle:6,1')->name('me.sessions.others');
        Route::delete('/me/sessions/{session}', [SessionController::class, 'destroy'])->name('me.sessions.destroy');
        Route::get('/me/login-activity', LoginActivityController::class)->name('me.login-activity');

        Route::get('/me/two-factor', [TwoFactorController::class, 'show'])->name('me.two-factor.show');
        Route::post('/me/two-factor', [TwoFactorController::class, 'store'])->middleware('throttle:6,1')->name('me.two-factor.store');
        Route::post('/me/two-factor/confirm', [TwoFactorController::class, 'confirm'])->middleware('throttle:6,1')->name('me.two-factor.confirm');
        Route::post('/me/two-factor/recovery-codes', [TwoFactorController::class, 'recoveryCodes'])->middleware('throttle:6,1')->name('me.two-factor.recovery');
        Route::delete('/me/two-factor', [TwoFactorController::class, 'destroy'])->middleware('throttle:6,1')->name('me.two-factor.destroy');

        // ---- Learning ----------------------------------------------------
        Route::post('/courses/{slug}/purchase', [CourseEngagementController::class, 'purchase'])->middleware('throttle:10,1')->name('courses.purchase');
        Route::post('/courses/{slug}/reviews', [CourseEngagementController::class, 'review'])->middleware('throttle:10,1')->name('courses.reviews.store');
        Route::prefix('learn')->name('learn.')->group(function () {
            Route::get('/enrollments', [LearnController::class, 'enrollments'])->name('enrollments');
            Route::get('/lessons/{lesson}', [LearnController::class, 'lesson'])->name('lessons.show');
            Route::put('/lessons/{lesson}/progress', [LearnController::class, 'progress'])->middleware('throttle:120,1')->name('lessons.progress');
            Route::get('/lessons/{lesson}/attachment', [LearnController::class, 'attachment'])->name('lessons.attachment');
            Route::post('/lessons/{lesson}/notes', [LearnController::class, 'storeNote'])->name('notes.store');
            Route::delete('/notes/{note}', [LearnController::class, 'destroyNote'])->name('notes.destroy');
            Route::get('/resources/{resource}', [LearnController::class, 'resource'])->name('resources.download');
            Route::post('/lessons/{lesson}/heartbeat', [LearnController::class, 'heartbeat'])->middleware('throttle:20,1')->name('lessons.heartbeat');

            Route::get('/lessons/{lesson}/quiz', [QuizController::class, 'show'])->name('quiz.show');
            Route::post('/lessons/{lesson}/quiz/attempts', [QuizController::class, 'start'])->middleware('throttle:20,1')->name('quiz.start');
            Route::get('/quiz-attempts/{attempt}', [QuizController::class, 'attempt'])->name('quiz.attempt');
            Route::put('/quiz-attempts/{attempt}/answers', [QuizController::class, 'save'])->middleware('throttle:60,1')->name('quiz.save');
            Route::post('/quiz-attempts/{attempt}/submit', [QuizController::class, 'submit'])->name('quiz.submit');

            Route::get('/lessons/{lesson}/assignment', [AssignmentController::class, 'show'])->name('assignment.show');
            Route::post('/lessons/{lesson}/assignment/submission', [AssignmentController::class, 'submit'])->middleware('throttle:10,1')->name('assignment.submit');
            Route::get('/submissions/{submission}/files/{index}', [AssignmentController::class, 'file'])->whereNumber('index')->name('submissions.file');
        });

        // ---- Live classes ------------------------------------------------
        Route::get('/live-sessions', [LiveSessionController::class, 'index'])->name('live.index');
        Route::get('/live-sessions/mine.ics', [LiveSessionController::class, 'ics'])->name('live.mine.ics');
        Route::get('/live-sessions/{session}', [LiveSessionController::class, 'show'])->name('live.show');
        Route::post('/live-sessions/{session}/registration', [LiveSessionController::class, 'register'])->middleware('throttle:20,1')->name('live.register');
        Route::delete('/live-sessions/{session}/registration', [LiveSessionController::class, 'unregister'])->name('live.unregister');
        Route::post('/live-sessions/{session}/join', [LiveSessionController::class, 'join'])->middleware('throttle:30,1')->name('live.join');
        Route::get('/live-replays', [LiveSessionController::class, 'replays'])->name('live.replays');
        Route::get('/live-replays/{session}/playback', [LiveSessionController::class, 'replayPlayback'])->middleware('throttle:60,1')->name('live.replays.playback');

        // ---- Course Q&A + announcements (learners with access) -----------
        Route::get('/learn/lessons/{lesson}/questions', [QuestionController::class, 'index'])->name('questions.index');
        Route::post('/learn/lessons/{lesson}/questions', [QuestionController::class, 'store'])->middleware('throttle:10,1')->name('questions.store');
        Route::get('/questions/{question}', [QuestionController::class, 'show'])->name('questions.show');
        Route::delete('/questions/{question}', [QuestionController::class, 'destroy'])->name('questions.destroy');
        Route::post('/questions/{question}/answers', [QuestionController::class, 'answer'])->middleware('throttle:20,1')->name('questions.answer');
        Route::delete('/answers/{answer}', [QuestionController::class, 'destroyAnswer'])->name('answers.destroy');
        Route::get('/courses/{slug}/announcements', [QuestionController::class, 'announcements'])->name('courses.announcements');

        // ---- Become an instructor ---------------------------------------
        Route::get('/me/instructor-application', [InstructorApplicationController::class, 'show'])->name('me.instructor-application');
        Route::post('/me/instructor-application', [InstructorApplicationController::class, 'store'])->middleware('throttle:5,1')->name('me.instructor-application.store');

        // ---- Notifications ------------------------------------------------
        Route::get('/me/notifications', [NotificationController::class, 'index'])->name('me.notifications');
        Route::get('/me/notifications/unread-count', [NotificationController::class, 'unreadCount'])->middleware('throttle:120,1')->name('me.notifications.unread');
        Route::post('/me/notifications/read-all', [NotificationController::class, 'markAllRead'])->name('me.notifications.read-all');
        Route::post('/me/notifications/{id}/read', [NotificationController::class, 'markRead'])->whereUuid('id')->name('me.notifications.read');
        Route::get('/me/notification-preferences', [NotificationController::class, 'preferences'])->name('me.notification-preferences');
        Route::put('/me/notification-preferences', [NotificationController::class, 'updatePreferences'])->name('me.notification-preferences.update');
        Route::put('/me/whatsapp', [NotificationController::class, 'whatsapp'])->name('me.whatsapp');
        Route::post('/me/push-tokens', [NotificationController::class, 'registerPushToken'])->middleware('throttle:20,1')->name('me.push-tokens.store');
        Route::delete('/me/push-tokens', [NotificationController::class, 'removePushToken'])->name('me.push-tokens.destroy');

        Route::get('/me/dashboard', [LearnerController::class, 'dashboard'])->name('me.dashboard');
        Route::get('/me/achievements', [LearnerController::class, 'achievements'])->name('me.achievements');
        Route::get('/me/bookmarks', [LearnerController::class, 'bookmarks'])->name('me.bookmarks.index');
        Route::post('/me/bookmarks', [LearnerController::class, 'storeBookmark'])->name('me.bookmarks.store');
        Route::delete('/me/bookmarks', [LearnerController::class, 'unbookmark'])->name('me.bookmarks.remove');
        Route::delete('/me/bookmarks/{bookmark}', [LearnerController::class, 'destroyBookmark'])->name('me.bookmarks.destroy');
        Route::get('/me/notes', [LearnerController::class, 'notes'])->name('me.notes');
        Route::get('/me/certificates', [LearnerController::class, 'certificates'])->name('me.certificates');
        Route::get('/me/certificates/{certificate}/pdf', [LearnerController::class, 'certificatePdf'])->name('me.certificates.pdf');
        Route::get('/me/learning-profile', [LearnerController::class, 'learningProfile'])->name('me.learning-profile');
        Route::patch('/me/learning-profile', [LearnerController::class, 'updateLearningProfile'])->name('me.learning-profile.update');

        // ---- Instructor studio -------------------------------------------
        Route::prefix('instructor')->name('instructor.')->middleware('role:instructor|admin|super_admin')->group(function () {
            Route::get('/courses', [Instructor\CourseController::class, 'index'])->name('courses.index');
            Route::post('/courses', [Instructor\CourseController::class, 'store'])->name('courses.store');
            Route::get('/courses/{course}', [Instructor\CourseController::class, 'show'])->name('courses.show');
            Route::patch('/courses/{course}', [Instructor\CourseController::class, 'update'])->name('courses.update');
            Route::delete('/courses/{course}', [Instructor\CourseController::class, 'destroy'])->name('courses.destroy');
            Route::post('/courses/{course}/thumbnail', [Instructor\CourseController::class, 'thumbnail'])->middleware('throttle:uploads')->name('courses.thumbnail');
            Route::post('/courses/{course}/submit', [Instructor\CourseController::class, 'submit'])->name('courses.submit');
            Route::put('/courses/{course}/curriculum', [Instructor\CourseController::class, 'reorder'])->name('courses.reorder');
            Route::post('/courses/{course}/sections', [Instructor\CurriculumController::class, 'storeSection'])->name('sections.store');
            Route::patch('/sections/{section}', [Instructor\CurriculumController::class, 'updateSection'])->name('sections.update');
            Route::delete('/sections/{section}', [Instructor\CurriculumController::class, 'destroySection'])->name('sections.destroy');
            Route::post('/sections/{section}/lessons', [Instructor\CurriculumController::class, 'storeLesson'])->name('lessons.store');
            Route::patch('/lessons/{lesson}', [Instructor\CurriculumController::class, 'updateLesson'])->name('lessons.update');
            Route::delete('/lessons/{lesson}', [Instructor\CurriculumController::class, 'destroyLesson'])->name('lessons.destroy');
            Route::post('/lessons/{lesson}/attachment', [Instructor\CurriculumController::class, 'attachment'])->middleware('throttle:uploads')->name('lessons.attachment');
            Route::post('/lessons/{lesson}/resources', [Instructor\CurriculumController::class, 'storeResource'])->middleware('throttle:uploads')->name('resources.store');
            Route::delete('/resources/{resource}', [Instructor\CurriculumController::class, 'destroyResource'])->name('resources.destroy');
            Route::get('/lessons/{lesson}/quiz', [Instructor\AssessmentController::class, 'showQuiz'])->name('quiz.show');
            Route::put('/lessons/{lesson}/quiz', [Instructor\AssessmentController::class, 'saveQuiz'])->name('quiz.save');
            Route::get('/lessons/{lesson}/assignment', [Instructor\AssessmentController::class, 'showAssignment'])->name('assignment.show');
            Route::get('/analytics', [Instructor\TeachingController::class, 'analytics'])->name('analytics');
            Route::get('/activity', [Instructor\TeachingController::class, 'activity'])->name('activity');
            Route::get('/courses/{course}/students', [Instructor\TeachingController::class, 'students'])->name('courses.students');
            Route::get('/questions', [Instructor\TeachingController::class, 'questions'])->name('questions');
            Route::get('/courses/{course}/announcements', [Instructor\TeachingController::class, 'announcements'])->name('courses.announcements');
            Route::post('/courses/{course}/announcements', [Instructor\TeachingController::class, 'announce'])->middleware('throttle:10,1')->name('courses.announce');
            Route::get('/profile', [Instructor\ProfileController::class, 'show'])->name('profile');
            Route::put('/profile', [Instructor\ProfileController::class, 'update'])->name('profile.update');
            Route::get('/earnings', [Instructor\EarningsController::class, 'index'])->name('earnings');
            Route::get('/earnings/{statement}', [Instructor\EarningsController::class, 'show'])->name('earnings.show');
            Route::get('/live-sessions/options', [Instructor\LiveSessionController::class, 'options'])->name('live.options');
            Route::get('/live-sessions', [Instructor\LiveSessionController::class, 'index'])->name('live.index');
            Route::post('/live-sessions', [Instructor\LiveSessionController::class, 'store'])->middleware('throttle:20,1')->name('live.store');
            Route::patch('/live-sessions/{session}', [Instructor\LiveSessionController::class, 'update'])->name('live.update');
            Route::post('/live-sessions/{session}/cancel', [Instructor\LiveSessionController::class, 'cancel'])->name('live.cancel');
            Route::post('/live-sessions/{session}/start', [Instructor\LiveSessionController::class, 'start'])->name('live.start');
            Route::get('/live-sessions/{session}/registrations', [Instructor\LiveSessionController::class, 'registrations'])->name('live.registrations');
            Route::post('/live-sessions/{session}/attendance-sync', [Instructor\LiveSessionController::class, 'syncAttendance'])->name('live.attendance-sync');
            Route::put('/live-sessions/{session}/replay', [Instructor\LiveSessionController::class, 'attachReplay'])->name('live.replay');
            Route::put('/lessons/{lesson}/assignment', [Instructor\AssessmentController::class, 'saveAssignment'])->name('assignment.save');
            Route::get('/submissions', [Instructor\AssessmentController::class, 'submissions'])->name('submissions.index');
            Route::patch('/submissions/{submission}', [Instructor\AssessmentController::class, 'grade'])->name('submissions.grade');
            Route::post('/videos', [Instructor\VideoController::class, 'store'])->middleware('throttle:30,1')->name('videos.store');
            Route::get('/videos/{video}', [Instructor\VideoController::class, 'show'])->name('videos.show');
            Route::post('/videos/{video}/uploaded', [Instructor\VideoController::class, 'uploaded'])->name('videos.uploaded');
            Route::post('/videos/{video}/demo-upload', [Instructor\VideoController::class, 'demoUpload'])->middleware('throttle:uploads')->name('videos.demo-upload');
        });

        // ---- Billing -----------------------------------------------------
        Route::prefix('billing')->name('billing.')->group(function () {
            Route::get('/subscription', [BillingController::class, 'subscription'])->name('subscription');
            Route::post('/coupons/validate', [BillingController::class, 'validateCoupon'])->middleware('throttle:20,1')->name('coupons.validate');
            Route::post('/checkout', [BillingController::class, 'checkout'])->middleware('throttle:10,1')->name('checkout');
            Route::get('/checkout/stripe/{sessionId}', [CheckoutSyncController::class, 'stripe'])->where('sessionId', 'cs_[A-Za-z0-9_]+')->name('checkout.stripe');
            Route::post('/checkout/razorpay/verify', [CheckoutSyncController::class, 'razorpay'])->middleware('throttle:20,1')->name('checkout.razorpay');
            Route::post('/subscription/preview-change', [BillingController::class, 'previewChange'])->name('preview');
            Route::post('/subscription/change', [BillingController::class, 'changePlan'])->middleware('throttle:6,1')->name('change');
            Route::post('/subscription/cancel', [BillingController::class, 'cancel'])->middleware('throttle:6,1')->name('cancel');
            Route::post('/subscription/resume', [BillingController::class, 'resume'])->middleware('throttle:6,1')->name('resume');
            Route::get('/portal', [BillingController::class, 'portal'])->name('portal');
            Route::put('/details', [BillingController::class, 'updateDetails'])->name('details');
            Route::get('/invoices', [InvoiceController::class, 'index'])->name('invoices.index');
            Route::get('/invoices/{payment}/pdf', [InvoiceController::class, 'download'])->name('invoices.pdf');
        });

        // Impersonation: ends the "view as" session and signs the admin back in.
        Route::post('/impersonation/stop', [Admin\ImpersonationController::class, 'stop'])->name('impersonation.stop');

        // ---- Admin -------------------------------------------------------
        // Staff only; each area is gated by a granular permission (super_admin passes all).
        Route::prefix('admin')->name('admin.')->middleware(['role:super_admin|admin', 'throttle:admin'])->group(function () {
            Route::middleware('can:analytics.view')->group(function () {
                Route::get('/analytics/overview', [Admin\AnalyticsController::class, 'overview'])->name('analytics.overview');
                Route::get('/analytics/revenue', [Admin\AnalyticsController::class, 'revenue'])->name('analytics.revenue');
                Route::get('/analytics/members', [Admin\AnalyticsController::class, 'members'])->name('analytics.members');
                Route::get('/analytics/learning', [Admin\AnalyticsController::class, 'learning'])->name('analytics.learning');
                Route::get('/analytics/affiliates', [Admin\AnalyticsController::class, 'affiliates'])->name('analytics.affiliates');
                Route::get('/analytics/cohorts', [Admin\AnalyticsController::class, 'cohorts'])->name('analytics.cohorts');
            });

            Route::middleware('can:users.view')->group(function () {
                Route::get('/users', [Admin\UserController::class, 'index'])->name('users.index');
                Route::get('/users/export', [Admin\ExportController::class, 'users'])->middleware('throttle:10,1')->name('users.export');
                Route::get('/users/{user}', [Admin\UserController::class, 'show'])->name('users.show');
                Route::get('/users/{user}/subscriptions', [Admin\UserSubscriptionController::class, 'index'])->name('users.subscriptions.index');
                Route::get('/users/{user}/payments', [Admin\UserSubscriptionController::class, 'payments'])->name('users.payments');
            });
            Route::middleware('can:users.manage')->group(function () {
                Route::post('/users/bulk', [Admin\UserController::class, 'bulk'])->name('users.bulk');
                Route::post('/users/{user}/status', [Admin\UserController::class, 'updateStatus'])->name('users.status');
                Route::post('/users/{user}/password-reset', [Admin\UserController::class, 'sendPasswordReset'])->middleware('throttle:10,1')->name('users.password-reset');
            });
            Route::put('/users/{user}/roles', [Admin\UserController::class, 'updateRoles'])->name('users.roles'); // roles.assign (form request)
            Route::post('/users/{user}/impersonate', [Admin\ImpersonationController::class, 'start'])->middleware(['can:users.impersonate', 'throttle:10,1'])->name('users.impersonate');

            Route::middleware('can:subscriptions.manage')->group(function () {
                Route::get('/subscriptions', [Admin\SubscriptionController::class, 'index'])->name('subscriptions.index');
                Route::get('/subscriptions/{subscription}', [Admin\SubscriptionController::class, 'show'])->name('subscriptions.show');
                Route::post('/users/{user}/subscriptions', [Admin\UserSubscriptionController::class, 'store'])->name('users.subscriptions.store');
                Route::post('/users/{user}/subscriptions/{subscription}/cancel', [Admin\UserSubscriptionController::class, 'cancel'])->name('users.subscriptions.cancel');
            });

            Route::middleware('can:payments.view')->group(function () {
                Route::get('/payments', [Admin\PaymentController::class, 'index'])->name('payments.index');
                Route::get('/payments/export', [Admin\ExportController::class, 'payments'])->middleware('throttle:10,1')->name('payments.export');
                Route::get('/payments/{payment}', [Admin\PaymentController::class, 'show'])->name('payments.show');
            });
            Route::post('/payments/{payment}/refund', [Admin\PaymentController::class, 'refund'])->middleware(['can:payments.refund', 'throttle:10,1'])->name('payments.refund');

            Route::middleware('can:plans.manage')->group(function () {
                Route::get('/plans', [Admin\PlanController::class, 'index'])->name('plans.index');
                Route::post('/plans', [Admin\PlanController::class, 'store'])->name('plans.store');
                Route::patch('/plans/{plan}', [Admin\PlanController::class, 'update'])->name('plans.update');
                Route::delete('/plans/{plan}', [Admin\PlanController::class, 'destroy'])->name('plans.destroy');
                Route::post('/plans/{plan}/prices', [Admin\PlanController::class, 'storePrice'])->name('plans.prices.store');
                Route::patch('/plans/{plan}/prices/{price}', [Admin\PlanController::class, 'updatePrice'])->name('plans.prices.update');
                Route::get('/coupons', [Admin\CouponController::class, 'index'])->name('coupons.index');
                Route::post('/coupons', [Admin\CouponController::class, 'store'])->name('coupons.store');
                Route::patch('/coupons/{coupon}', [Admin\CouponController::class, 'update'])->name('coupons.update');
                Route::delete('/coupons/{coupon}', [Admin\CouponController::class, 'destroy'])->name('coupons.destroy');
            });

            Route::middleware('can:courses.manage-any')->group(function () {
                Route::get('/courses', [Admin\CourseController::class, 'index'])->name('courses.index');
                Route::patch('/courses/{course}', [Admin\CourseController::class, 'update'])->name('courses.update');
                Route::post('/courses/{course}/approve', [Admin\CourseController::class, 'approve'])->name('courses.approve');
                Route::post('/courses/{course}/reject', [Admin\CourseController::class, 'reject'])->name('courses.reject');
                Route::get('/categories', [Admin\CategoryController::class, 'index'])->name('categories.index');
                Route::post('/categories', [Admin\CategoryController::class, 'store'])->name('categories.store');
                Route::patch('/categories/{category}', [Admin\CategoryController::class, 'update'])->name('categories.update');
                Route::delete('/categories/{category}', [Admin\CategoryController::class, 'destroy'])->name('categories.destroy');
            });

            Route::middleware('can:reviews.moderate')->group(function () {
                Route::get('/reviews', [Admin\ReviewController::class, 'index'])->name('reviews.index');
                Route::post('/reviews/{review}/hide', [Admin\ReviewController::class, 'hide'])->name('reviews.hide');
                Route::post('/reviews/{review}/unhide', [Admin\ReviewController::class, 'unhide'])->name('reviews.unhide');
                Route::delete('/reviews/{review}', [Admin\ReviewController::class, 'destroy'])->name('reviews.destroy');
            });

            Route::middleware([AuditAdminWrite::class, 'can:instructors.manage'])->group(function () {
                Route::get('/instructor-applications', [Admin\InstructorApplicationController::class, 'index'])->name('instructor-applications.index');
                Route::post('/instructor-applications/{application}/approve', [Admin\InstructorApplicationController::class, 'approve'])->name('instructor-applications.approve');
                Route::post('/instructor-applications/{application}/reject', [Admin\InstructorApplicationController::class, 'reject'])->name('instructor-applications.reject');
                Route::get('/payouts', [Admin\PayoutController::class, 'index'])->name('payouts.index');
                Route::post('/payouts/generate', [Admin\PayoutController::class, 'generate'])->name('payouts.generate');
                Route::post('/payouts/finalize', [Admin\PayoutController::class, 'finalize'])->name('payouts.finalize');
                Route::get('/payouts/{statement}', [Admin\PayoutController::class, 'show'])->name('payouts.show');
                Route::post('/payouts/{statement}/paid', [Admin\PayoutController::class, 'markPaid'])->name('payouts.paid');
                Route::get('/instructors', [Admin\PayoutController::class, 'instructors'])->name('instructors.index');
                Route::patch('/instructors/{user}/revenue-share', [Admin\PayoutController::class, 'updateShare'])->name('instructors.share');
            });

            Route::middleware([AuditAdminWrite::class, 'can:affiliates.manage'])->group(function () {
                Route::get('/affiliates', [Admin\AffiliateController::class, 'index'])->name('affiliates.index');
                Route::post('/affiliates/{affiliate}/{action}', [Admin\AffiliateController::class, 'decide'])->whereIn('action', ['approve', 'reject', 'suspend'])->name('affiliates.decide');
                Route::patch('/affiliates/{affiliate}/terms', [Admin\AffiliateController::class, 'updateTerms'])->name('affiliates.terms');
                Route::get('/affiliate-reviews', [Admin\AffiliateController::class, 'reviews'])->name('affiliate-reviews.index');
                Route::post('/affiliate-reviews/{referral}', [Admin\AffiliateController::class, 'review'])->name('affiliate-reviews.decide');
                Route::get('/affiliate-payouts', [Admin\AffiliateController::class, 'payouts'])->name('affiliate-payouts.index');
                Route::post('/affiliate-payouts/batch', [Admin\AffiliateController::class, 'batch'])->name('affiliate-payouts.batch');
                Route::get('/affiliate-payouts/export', [Admin\AffiliateController::class, 'export'])->name('affiliate-payouts.export');
                Route::post('/affiliate-payouts/{payout}/paid', [Admin\AffiliateController::class, 'markPaid'])->name('affiliate-payouts.paid');
                Route::post('/affiliate-payouts/{payout}/failed', [Admin\AffiliateController::class, 'markFailed'])->name('affiliate-payouts.failed');
                Route::get('/commissions/export', [Admin\ExportController::class, 'commissions'])->middleware('throttle:10,1')->name('commissions.export');
            });

            Route::middleware('can:notifications.manage')->group(function () {
                Route::get('/notification-templates', [Admin\NotificationAdminController::class, 'templates'])->name('notification-templates.index');
                Route::put('/notification-templates/{event}/{channel}', [Admin\NotificationAdminController::class, 'updateTemplate'])->name('notification-templates.update');
                Route::delete('/notification-templates/{event}/{channel}', [Admin\NotificationAdminController::class, 'resetTemplate'])->name('notification-templates.reset');
                Route::post('/notification-templates/{event}/preview', [Admin\NotificationAdminController::class, 'preview'])->name('notification-templates.preview');
                Route::get('/campaigns', [Admin\NotificationAdminController::class, 'campaigns'])->name('campaigns.index');
                Route::post('/campaigns', [Admin\NotificationAdminController::class, 'storeCampaign'])->name('campaigns.store');
                Route::post('/campaigns/audience', [Admin\NotificationAdminController::class, 'audience'])->name('campaigns.audience');
                Route::get('/campaigns/{campaign}', [Admin\NotificationAdminController::class, 'showCampaign'])->name('campaigns.show');
                Route::patch('/campaigns/{campaign}', [Admin\NotificationAdminController::class, 'updateCampaign'])->name('campaigns.update');
                Route::post('/campaigns/{campaign}/schedule', [Admin\NotificationAdminController::class, 'schedule'])->name('campaigns.schedule');
                Route::post('/campaigns/{campaign}/cancel', [Admin\NotificationAdminController::class, 'cancel'])->name('campaigns.cancel');
                Route::post('/campaigns/{campaign}/test', [Admin\NotificationAdminController::class, 'test'])->middleware('throttle:10,1')->name('campaigns.test');
                Route::get('/notification-logs', [Admin\NotificationAdminController::class, 'logs'])->name('notification-logs.index');
                Route::post('/notification-logs/{log}/retry', [Admin\NotificationAdminController::class, 'retry'])->name('notification-logs.retry');
            });

            Route::middleware('can:cms.manage')->prefix('cms')->name('cms.')->group(function () {
                Route::get('/sections', [Admin\CmsController::class, 'sections'])->name('sections.index');
                Route::put('/sections/{key}', [Admin\CmsController::class, 'updateSection'])->name('sections.update');
                Route::get('/pages', [Admin\CmsController::class, 'pages'])->name('pages.index');
                Route::put('/pages/{slug}', [Admin\CmsController::class, 'savePage'])->name('pages.save');
                Route::get('/posts', [Admin\CmsController::class, 'posts'])->name('posts.index');
                Route::get('/posts/{post}', [Admin\CmsController::class, 'showPost'])->name('posts.show');
                Route::post('/posts', [Admin\CmsController::class, 'storePost'])->name('posts.store');
                Route::patch('/posts/{post}', [Admin\CmsController::class, 'updatePost'])->name('posts.update');
                Route::delete('/posts/{post}', [Admin\CmsController::class, 'destroyPost'])->name('posts.destroy');
                Route::get('/{type}', [Admin\CmsController::class, 'index'])->whereIn('type', ['faqs', 'testimonials', 'banners'])->name('items.index');
                Route::post('/{type}', [Admin\CmsController::class, 'store'])->whereIn('type', ['faqs', 'testimonials', 'banners'])->name('items.store');
                Route::put('/{type}/order', [Admin\CmsController::class, 'reorder'])->whereIn('type', ['faqs', 'testimonials'])->name('items.reorder');
                Route::patch('/{type}/{id}', [Admin\CmsController::class, 'update'])->whereIn('type', ['faqs', 'testimonials', 'banners'])->name('items.update');
                Route::delete('/{type}/{id}', [Admin\CmsController::class, 'destroy'])->whereIn('type', ['faqs', 'testimonials', 'banners'])->name('items.destroy');
            });

            Route::get('/settings', [Admin\SettingsController::class, 'show'])->middleware('can:settings.manage')->name('settings.show');
            Route::put('/settings/{group}', [Admin\SettingsController::class, 'update'])->middleware('can:settings.manage')->name('settings.update');
            Route::get('/audit-log', [Admin\AuditLogController::class, 'index'])->middleware('can:audit.view')->name('audit-log.index');
        });
    });
});
