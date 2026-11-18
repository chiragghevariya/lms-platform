import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { fireEvent, render, screen, within } from '@testing-library/react'
import { HelmetProvider } from 'react-helmet-async'
import { MemoryRouter } from 'react-router-dom'
import AdminTemplatesPage from '@/features/admin/pages/AdminTemplatesPage'
import type { PreferenceMatrix } from '@/types/notifications'
import { notificationsAdminApi, notificationsApi } from './api'
import { NotificationBell } from './components/NotificationBell'
import NotificationSettingsPage from './pages/NotificationSettingsPage'

afterEach(() => vi.restoreAllMocks())

const wrap = (node: React.ReactNode) =>
  render(
    <HelmetProvider>
      <QueryClientProvider client={new QueryClient({ defaultOptions: { queries: { retry: false } } })}>
        <MemoryRouter>{node}</MemoryRouter>
      </QueryClientProvider>
    </HelmetProvider>,
  )

const matrix = (o: Partial<PreferenceMatrix> = {}): PreferenceMatrix => ({
  categories: [
    {
      key: 'billing',
      label: 'Membership & billing',
      events: [
        {
          event: 'invoice',
          label: 'Payment receipts & invoices',
          channels: { mail: { enabled: true, locked: true }, database: { enabled: true, locked: false } },
        },
      ],
    },
    {
      key: 'learning',
      label: 'Courses & learning',
      events: [
        {
          event: 'new_course',
          label: 'New course in your tier',
          channels: { mail: { enabled: true, locked: false }, push: { enabled: true, locked: false } },
        },
      ],
    },
  ],
  channels: { mail: true, database: true, whatsapp: true, push: true, sms: false },
  whatsapp: { opted_in: false, opted_in_at: null, phone: '+91••••••3210', phone_verified: true },
  push_devices: 1,
  ...o,
})

describe('notification bell', () => {
  it('shows the unread count and lists notifications', async () => {
    vi.spyOn(notificationsApi, 'unreadCount').mockResolvedValue({ unread: 3 })
    vi.spyOn(notificationsApi, 'list').mockResolvedValue({
      data: [
        {
          id: 'n1',
          event: 'certificate_issued',
          title: 'Certificate issued 🎉',
          body: 'You completed Meta Ads',
          url: '/app/certificates',
          read_at: null,
          created_at: new Date().toISOString(),
        },
      ],
      meta: { total: 1, current_page: 1, last_page: 1, unread: 3 },
    })
    wrap(<NotificationBell />)
    const bell = await screen.findByRole('button', { name: 'Notifications, 3 unread' })
    fireEvent.pointerDown(bell, { button: 0, ctrlKey: false })
    expect(await screen.findByText('Certificate issued 🎉')).toBeInTheDocument()
    expect(screen.getByRole('link', { name: 'Settings' })).toHaveAttribute(
      'href',
      '/app/notification-settings',
    )
  })
})

describe('notification settings', () => {
  it('locks mandatory channels and saves a toggle', async () => {
    vi.spyOn(notificationsApi, 'preferences').mockResolvedValue(matrix())
    const update = vi.spyOn(notificationsApi, 'updatePreferences').mockResolvedValue(matrix())
    wrap(<NotificationSettingsPage />)

    const billing = (await screen.findByText('Payment receipts & invoices')).closest('tr')!
    expect(within(billing).getByText('Always')).toBeInTheDocument()
    expect(within(billing).queryByRole('checkbox', { name: /Email for/ })).toBeNull()

    fireEvent.click(screen.getByRole('checkbox', { name: 'Push for New course in your tier' }))
    await vi.waitFor(() =>
      expect(update).toHaveBeenCalledWith([{ event: 'new_course', channel: 'push', enabled: false }]),
    )
  })

  it('needs explicit consent before turning WhatsApp on', async () => {
    vi.spyOn(notificationsApi, 'preferences').mockResolvedValue(matrix())
    const wa = vi.spyOn(notificationsApi, 'whatsapp').mockResolvedValue({} as never)
    wrap(<NotificationSettingsPage />)
    const btn = await screen.findByRole('button', { name: 'Turn on WhatsApp' })
    expect(btn).toBeDisabled()
    fireEvent.click(screen.getByRole('checkbox', { name: /I agree to receive messages on WhatsApp/ }))
    fireEvent.click(btn)
    await vi.waitFor(() => expect(wa).toHaveBeenCalledWith(true))
  })

  it('asks for phone verification first', async () => {
    vi.spyOn(notificationsApi, 'preferences').mockResolvedValue(
      matrix({ whatsapp: { opted_in: false, opted_in_at: null, phone: null, phone_verified: false } }),
    )
    wrap(<NotificationSettingsPage />)
    expect(await screen.findByRole('link', { name: 'Verify your phone number' })).toHaveAttribute(
      'href',
      '/verify-phone',
    )
  })
})

describe('template manager', () => {
  it('previews with sample data and blocks unknown variables', async () => {
    vi.spyOn(notificationsAdminApi, 'templates').mockResolvedValue([
      {
        event: 'new_course',
        label: 'New course in your tier',
        category: 'learning',
        vars: ['name', 'course', 'url'],
        channels: [
          {
            channel: 'database',
            customised: false,
            subject: 'New course: {{course}}',
            body: "It's included",
            action_text: null,
            whatsapp_template: null,
            whatsapp_language: 'en',
            whatsapp_params: [],
          },
        ],
      },
    ])
    const preview = vi.spyOn(notificationsAdminApi, 'preview').mockResolvedValue({
      subject: 'New course: Meta Ads from Zero',
      body: 'Hi {{oops}}',
      html: false,
      unknown_vars: ['oops'],
    })
    wrap(<AdminTemplatesPage />)

    expect(await screen.findByDisplayValue('New course: {{course}}')).toBeInTheDocument()
    fireEvent.click(screen.getByRole('button', { name: '{{course}}' })) // variable chip inserts into the text
    expect((screen.getByLabelText('Text') as HTMLTextAreaElement).value).toContain('{{course}}')
    await vi.waitFor(() => expect(preview).toHaveBeenCalled(), { timeout: 2000 })
    expect(await screen.findByText('Unknown variables: oops')).toBeInTheDocument()
    expect(screen.getByRole('button', { name: 'Save' })).toBeDisabled()
  })
})
