import { lazy, Suspense, type ReactNode } from 'react'
import { prefetchCourse } from '@/features/courses/api'
import { queryClient } from './query-client'
import { createBrowserRouter, Navigate, Outlet } from 'react-router-dom'
import { ReferralCapture } from '@/components/ReferralCapture'
import { FullPageSpinner } from '@/components/FullPageSpinner'
import NotFound from '@/components/NotFound'
import { PublicLayout } from '@/layouts/PublicLayout'
import type { Permission } from '@/lib/permissions'
import { GuestOnly, ProtectedRoute, RequirePermission, RoleRoute } from '@/routes/guards'

// Public
const HomePage = lazy(() => import('@/features/marketing/pages/HomePage'))
const AboutPage = lazy(() => import('@/features/marketing/pages/AboutPage'))
const CatalogPage = lazy(() => import('@/features/courses/pages/CatalogPage'))
const CourseDetailPage = lazy(() => import('@/features/courses/pages/CourseDetailPage'))
const LessonPlayerPage = lazy(() => import('@/features/learn/pages/LessonPlayerPage'))
const MyCoursesPage = lazy(() => import('@/features/learn/pages/MyCoursesPage'))
const CertificatesPage = lazy(() => import('@/features/learning/pages/CertificatesPage'))
const BookmarksPage = lazy(() => import('@/features/learning/pages/BookmarksPage'))
const NotesPage = lazy(() => import('@/features/learning/pages/NotesPage'))
const LearningProfilePage = lazy(() => import('@/features/learning/pages/LearningProfilePage'))
const VerifyCertificatePage = lazy(() => import('@/features/learning/pages/VerifyCertificatePage'))
const PublicProfilePage = lazy(() => import('@/features/learning/pages/PublicProfilePage'))
const GradingPage = lazy(() => import('@/features/instructor/pages/GradingPage'))
const InstructorLivePage = lazy(() => import('@/features/instructor/pages/InstructorLivePage'))
const StudentsPage = lazy(() => import('@/features/instructor/pages/StudentsPage'))
const QuestionsInboxPage = lazy(() => import('@/features/instructor/pages/QuestionsInboxPage'))
const AnnouncementsPage = lazy(() => import('@/features/instructor/pages/AnnouncementsPage'))
const EarningsPage = lazy(() => import('@/features/instructor/pages/EarningsPage'))
const TeachingProfilePage = lazy(() => import('@/features/instructor/pages/TeachingProfilePage'))
const NotificationsPage = lazy(() => import('@/features/notifications/pages/NotificationsPage'))
const NotificationSettingsPage = lazy(() => import('@/features/notifications/pages/NotificationSettingsPage'))
const AdminTemplatesPage = lazy(() => import('@/features/admin/pages/AdminTemplatesPage'))
const AdminCampaignsPage = lazy(() => import('@/features/admin/pages/AdminCampaignsPage'))
const AdminDeliveryPage = lazy(() => import('@/features/admin/pages/AdminDeliveryPage'))
const AffiliatePage = lazy(() => import('@/features/affiliate/pages/AffiliatePage'))
const InvitePage = lazy(() => import('@/features/affiliate/pages/InvitePage'))
const BecomeInstructorPage = lazy(() => import('@/features/learning/pages/BecomeInstructorPage'))
const AdminInstructorApplicationsPage = lazy(
  () => import('@/features/admin/pages/AdminInstructorApplicationsPage'),
)
const AdminPayoutsPage = lazy(() => import('@/features/admin/pages/AdminPayoutsPage'))
const LiveClassesPage = lazy(() => import('@/features/live/pages/LiveClassesPage'))
const LiveRoomPage = lazy(() => import('@/features/live/pages/LiveRoomPage'))
const ReplaysPage = lazy(() => import('@/features/live/pages/ReplaysPage'))
const ReplayPage = lazy(() => import('@/features/live/pages/ReplayPage'))
const StudioCoursesPage = lazy(() => import('@/features/studio/pages/StudioCoursesPage'))
const CourseEditorPage = lazy(() => import('@/features/studio/pages/CourseEditorPage'))
const AdminCoursesPage = lazy(() => import('@/features/studio/pages/AdminCoursesPage'))
const ContactPage = lazy(() => import('@/features/marketing/pages/ContactPage'))
const TermsPage = lazy(() => import('@/features/marketing/pages/TermsPage'))
const PrivacyPage = lazy(() => import('@/features/marketing/pages/PrivacyPage'))
const PricingPage = lazy(() => import('@/features/membership/pages/PricingPage'))
// Auth
const LoginPage = lazy(() => import('@/features/auth/pages/LoginPage'))
const RegisterPage = lazy(() => import('@/features/auth/pages/RegisterPage'))
const ForgotPasswordPage = lazy(() => import('@/features/auth/pages/ForgotPasswordPage'))
const ResetPasswordPage = lazy(() => import('@/features/auth/pages/ResetPasswordPage'))
const VerifyEmailPage = lazy(() => import('@/features/auth/pages/VerifyEmailPage'))
const VerifyEmailPendingPage = lazy(() => import('@/features/auth/pages/VerifyEmailPendingPage'))
const VerifyPhonePage = lazy(() => import('@/features/auth/pages/VerifyPhonePage'))
const TwoFactorChallengePage = lazy(() => import('@/features/auth/pages/TwoFactorChallengePage'))
const AuthCallbackPage = lazy(() => import('@/features/auth/pages/AuthCallbackPage'))
// Account (shared by every area)
const ProfilePage = lazy(() => import('@/features/profile/pages/ProfilePage'))
const SecurityPage = lazy(() => import('@/features/security/pages/SecurityPage'))
// Areas
const StudentDashboardPage = lazy(() => import('@/features/student/pages/StudentDashboardPage'))
const BillingPage = lazy(() => import('@/features/billing/pages/BillingPage'))
const CheckoutSuccessPage = lazy(() =>
  import('@/features/billing/pages/CheckoutResultPages').then((m) => ({ default: m.CheckoutSuccessPage })),
)
const CheckoutFailedPage = lazy(() =>
  import('@/features/billing/pages/CheckoutResultPages').then((m) => ({ default: m.CheckoutFailedPage })),
)
const InstructorDashboardPage = lazy(() => import('@/features/instructor/pages/InstructorDashboardPage'))
const AdminDashboardPage = lazy(() => import('@/features/admin/pages/AdminDashboardPage'))
const AdminUsersPage = lazy(() => import('@/features/admin/pages/AdminUsersPage'))
const AdminPlansPage = lazy(() => import('@/features/admin/pages/AdminPlansPage'))
const AdminAnalyticsPage = lazy(() => import('@/features/admin/pages/AdminAnalyticsPage'))
const AdminAuditLogPage = lazy(() => import('@/features/admin/pages/AdminAuditLogPage'))
const AdminSubscriptionsPage = lazy(() => import('@/features/admin/pages/AdminSubscriptionsPage'))
const AdminPaymentsPage = lazy(() => import('@/features/admin/pages/AdminPaymentsPage'))
const AdminCouponsPage = lazy(() => import('@/features/admin/pages/AdminCouponsPage'))
const AdminCategoriesPage = lazy(() => import('@/features/admin/pages/AdminCategoriesPage'))
const AdminReviewsPage = lazy(() => import('@/features/admin/pages/AdminReviewsPage'))
const AdminLiveSessionsPage = lazy(() => import('@/features/admin/pages/AdminLiveSessionsPage'))
const AdminAffiliatesPage = lazy(() => import('@/features/admin/pages/AdminAffiliatesPage'))
const AdminHomepagePage = lazy(() => import('@/features/cms/admin/AdminHomepagePage'))
const AdminFaqsPage = lazy(() => import('@/features/cms/admin/AdminFaqsPage'))
const AdminTestimonialsPage = lazy(() => import('@/features/cms/admin/AdminTestimonialsPage'))
const AdminBannersPage = lazy(() => import('@/features/cms/admin/AdminBannersPage'))
const AdminLegalPagesPage = lazy(() => import('@/features/cms/admin/AdminLegalPagesPage'))
const AdminBlogPage = lazy(() => import('@/features/cms/admin/AdminBlogPage'))
const AdminPostEditorPage = lazy(() => import('@/features/cms/admin/AdminPostEditorPage'))
const AdminSettingsPage = lazy(() => import('@/features/cms/admin/AdminSettingsPage'))
const BlogPage = lazy(() => import('@/features/cms/pages/BlogPage'))
const BlogPostPage = lazy(() => import('@/features/cms/pages/BlogPostPage'))
const CmsPage = lazy(() => import('@/features/cms/pages/CmsPage'))

// Dashboard shells load on demand so public pages don't download the admin/instructor UI.
const StudentLayout = lazy(() =>
  import('@/layouts/StudentLayout').then((m) => ({ default: m.StudentLayout })),
)
const InstructorLayout = lazy(() =>
  import('@/layouts/InstructorLayout').then((m) => ({ default: m.InstructorLayout })),
)
const AdminLayout = lazy(() => import('@/layouts/AdminLayout').then((m) => ({ default: m.AdminLayout })))

const page = (node: ReactNode) => <Suspense fallback={<FullPageSpinner />}>{node}</Suspense>
const gated = (permission: Permission, node: ReactNode) => (
  <RequirePermission permission={permission}>{page(node)}</RequirePermission>
)

const accountRoutes = [
  { path: 'profile', element: page(<ProfilePage />) },
  { path: 'security', element: page(<SecurityPage />) },
]

export const router = createBrowserRouter(
  [
    {
      // Root: referral capture on every page (?ref / ?invite).
      element: (
        <>
          <ReferralCapture />
          <Outlet />
        </>
      ),
      children: [
        {
          element: <PublicLayout />,
          children: [
            { index: true, element: page(<HomePage />) },
            { path: 'about', element: page(<AboutPage />) },
            { path: 'courses', element: page(<CatalogPage />) },
            {
              path: 'courses/:slug',
              // Start the page chunk and the course data together instead of one after the other (LCP).
              loader: ({ params }) => {
                if (params.slug) prefetchCourse(queryClient, params.slug)
                return null
              },
              element: page(<CourseDetailPage />),
            },
            { path: 'pricing', element: page(<PricingPage />) },
            { path: 'contact', element: page(<ContactPage />) },
            { path: 'terms', element: page(<TermsPage />) },
            { path: 'privacy', element: page(<PrivacyPage />) },
            { path: 'blog', element: page(<BlogPage />) },
            { path: 'blog/:slug', element: page(<BlogPostPage />) },
            { path: 'p/:slug', element: page(<CmsPage />) },
            { path: 'verify/:code', element: page(<VerifyCertificatePage />) },
            { path: 'u/:handle', element: page(<PublicProfilePage />) },
            { path: 'reset-password', element: page(<ResetPasswordPage />) },
            { path: 'auth/callback', element: page(<AuthCallbackPage />) },
            {
              element: <GuestOnly />,
              children: [
                { path: 'login', element: page(<LoginPage />) },
                { path: 'register', element: page(<RegisterPage />) },
                { path: 'forgot-password', element: page(<ForgotPasswordPage />) },
                { path: 'two-factor', element: page(<TwoFactorChallengePage />) },
              ],
            },
            {
              element: <ProtectedRoute />,
              children: [
                { path: 'verify-email', element: page(<VerifyEmailPage />) },
                { path: 'verify-email/pending', element: page(<VerifyEmailPendingPage />) },
                { path: 'verify-phone', element: page(<VerifyPhonePage />) },
                { path: 'billing/success', element: page(<CheckoutSuccessPage />) },
                { path: 'billing/failed', element: page(<CheckoutFailedPage />) },
              ],
            },
          ],
        },
        {
          path: 'app',
          element: <ProtectedRoute>{page(<StudentLayout />)}</ProtectedRoute>,
          children: [
            { index: true, element: page(<StudentDashboardPage />) },
            { path: 'courses', element: page(<MyCoursesPage />) },
            { path: 'live', element: page(<LiveClassesPage />) },
            { path: 'replays', element: page(<ReplaysPage />) },
            { path: 'replays/:id', element: page(<ReplayPage />) },
            { path: 'certificates', element: page(<CertificatesPage />) },
            { path: 'bookmarks', element: page(<BookmarksPage />) },
            { path: 'notes', element: page(<NotesPage />) },
            { path: 'learning-profile', element: page(<LearningProfilePage />) },
            { path: 'become-instructor', element: page(<BecomeInstructorPage />) },
            { path: 'affiliate', element: page(<AffiliatePage />) },
            { path: 'notifications', element: page(<NotificationsPage />) },
            { path: 'notification-settings', element: page(<NotificationSettingsPage />) },
            { path: 'invite', element: page(<InvitePage />) },
            { path: 'billing', element: page(<BillingPage />) },
            { path: 'membership', element: <Navigate to="/app/billing" replace /> },
            ...accountRoutes,
          ],
        },
        {
          path: 'instructor',
          element: <RoleRoute roles={['instructor', 'admin']}>{page(<InstructorLayout />)}</RoleRoute>,
          children: [
            { index: true, element: page(<InstructorDashboardPage />) },
            { path: 'courses', element: page(<StudioCoursesPage />) },
            { path: 'courses/:courseId/edit', element: page(<CourseEditorPage />) },
            { path: 'grading', element: page(<GradingPage />) },
            { path: 'students', element: page(<StudentsPage />) },
            { path: 'questions', element: page(<QuestionsInboxPage />) },
            { path: 'announcements', element: page(<AnnouncementsPage />) },
            { path: 'earnings', element: page(<EarningsPage />) },
            { path: 'teaching-profile', element: page(<TeachingProfilePage />) },
            { path: 'live', element: page(<InstructorLivePage />) },
            ...accountRoutes,
          ],
        },
        {
          path: 'admin',
          element: <RoleRoute roles={['admin']}>{page(<AdminLayout />)}</RoleRoute>,
          children: [
            { index: true, element: page(<AdminDashboardPage />) },
            { path: 'analytics', element: gated('analytics.view', <AdminAnalyticsPage />) },
            { path: 'users', element: gated('users.view', <AdminUsersPage />) },
            { path: 'subscriptions', element: gated('subscriptions.manage', <AdminSubscriptionsPage />) },
            { path: 'payments', element: gated('payments.view', <AdminPaymentsPage />) },
            { path: 'plans', element: gated('plans.manage', <AdminPlansPage />) },
            { path: 'coupons', element: gated('plans.manage', <AdminCouponsPage />) },
            { path: 'courses', element: gated('courses.manage-any', <AdminCoursesPage />) },
            { path: 'categories', element: gated('courses.manage-any', <AdminCategoriesPage />) },
            { path: 'reviews', element: gated('reviews.moderate', <AdminReviewsPage />) },
            { path: 'live', element: gated('live.manage-any', <AdminLiveSessionsPage />) },
            {
              path: 'instructor-applications',
              element: gated('instructors.manage', <AdminInstructorApplicationsPage />),
            },
            { path: 'payouts', element: gated('instructors.manage', <AdminPayoutsPage />) },
            { path: 'affiliates', element: gated('affiliates.manage', <AdminAffiliatesPage />) },
            {
              path: 'notification-templates',
              element: gated('notifications.manage', <AdminTemplatesPage />),
            },
            { path: 'campaigns', element: gated('notifications.manage', <AdminCampaignsPage />) },
            { path: 'delivery', element: gated('notifications.manage', <AdminDeliveryPage />) },
            { path: 'cms/homepage', element: gated('cms.manage', <AdminHomepagePage />) },
            { path: 'cms/faqs', element: gated('cms.manage', <AdminFaqsPage />) },
            { path: 'cms/testimonials', element: gated('cms.manage', <AdminTestimonialsPage />) },
            { path: 'cms/banners', element: gated('cms.manage', <AdminBannersPage />) },
            { path: 'cms/pages', element: gated('cms.manage', <AdminLegalPagesPage />) },
            { path: 'blog', element: gated('cms.manage', <AdminBlogPage />) },
            { path: 'blog/:id', element: gated('cms.manage', <AdminPostEditorPage />) },
            { path: 'settings', element: gated('settings.manage', <AdminSettingsPage />) },
            { path: 'audit-log', element: gated('audit.view', <AdminAuditLogPage />) },
            ...accountRoutes,
          ],
        },
        {
          path: 'live/:id',
          element: <ProtectedRoute>{page(<LiveRoomPage />)}</ProtectedRoute>,
        },
        {
          path: 'learn/:slug/:lessonId',
          element: <ProtectedRoute>{page(<LessonPlayerPage />)}</ProtectedRoute>,
        },
        { path: '*', element: <NotFound /> },
      ],
    },
  ],
  { future: { v7_relativeSplatPath: true } },
)
