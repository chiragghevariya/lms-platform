import { zodResolver } from '@hookform/resolvers/zod'
import { CaptchaWidget } from '@/components/CaptchaWidget'
import { Mail, MessageCircle } from 'lucide-react'
import { useEffect } from 'react'
import { useForm } from 'react-hook-form'
import { z } from 'zod'
import { Seo } from '@/components/Seo'
import { Alert } from '@/components/ui/alert'
import { Button } from '@/components/ui/button'
import { Card, CardContent } from '@/components/ui/card'
import { FormField } from '@/components/ui/form-field'
import { Input } from '@/components/ui/input'
import { applyServerErrors } from '@/lib/form-errors'
import { useAuthStore } from '@/stores/auth-store'
import { useContact } from '../api'

const schema = z.object({
  name: z.string().trim().min(2, 'Tell us your name').max(120),
  email: z.string().trim().email('Enter a valid email'),
  phone: z.string().trim().max(32).optional(),
  subject: z.string().trim().min(2, 'Add a subject').max(160),
  message: z.string().trim().min(10, 'At least 10 characters').max(5000),
  website: z.string().optional(),
})
type Input = z.infer<typeof schema>

export default function ContactPage() {
  const user = useAuthStore((s) => s.user)
  const contact = useContact()
  const { register, handleSubmit, setError, reset, formState, getValues, setValue } = useForm<Input>({
    resolver: zodResolver(schema),
    defaultValues: {
      name: user?.name ?? '',
      email: user?.email ?? '',
      phone: '',
      subject: '',
      message: '',
      website: '',
    },
  })
  const { errors } = formState

  // Prefill once the session resolves (it may load after the form mounts).
  useEffect(() => {
    if (!user) return
    if (!getValues('name')) setValue('name', user.name)
    if (!getValues('email')) setValue('email', user.email)
  }, [user, getValues, setValue])

  const onSubmit = handleSubmit(async (v) => {
    try {
      await contact.mutateAsync({ ...v, phone: v.phone || undefined, website: v.website || undefined })
      reset()
    } catch (e) {
      applyServerErrors(e, setError, ['name', 'email', 'phone', 'subject', 'message'])
    }
  })

  return (
    <>
      <Seo
        title="Contact us"
        path="/contact"
        description="Questions about courses, membership or partnerships? Get in touch."
      />
      <section className="mx-auto grid max-w-6xl gap-12 px-4 py-16 lg:grid-cols-[1fr_1.4fr]">
        <div>
          <h1 className="text-4xl font-extrabold tracking-tight">Contact us</h1>
          <p className="text-muted-foreground mt-3">We usually reply within one business day.</p>
          <ul className="mt-8 grid gap-4">
            <li className="flex items-center gap-3">
              <Mail className="text-primary size-5" aria-hidden /> Use the form for support, billing or
              partnerships.
            </li>
            <li className="flex items-center gap-3">
              <MessageCircle className="text-primary size-5" aria-hidden /> Members get priority support
              inside the app.
            </li>
          </ul>
        </div>
        <Card>
          <CardContent className="p-6">
            {contact.isSuccess ? (
              <Alert variant="success" role="status">
                {contact.data.message}
              </Alert>
            ) : (
              <form onSubmit={onSubmit} className="grid gap-4" noValidate>
                {contact.isError && !Object.keys(errors).length && (
                  <Alert variant="destructive">{contact.error.message}</Alert>
                )}
                <div className="grid gap-4 sm:grid-cols-2">
                  <FormField id="c-name" label="Name" error={errors.name?.message}>
                    {(a) => <Input autoComplete="name" {...a} {...register('name')} />}
                  </FormField>
                  <FormField id="c-email" label="Email" error={errors.email?.message}>
                    {(a) => <Input type="email" autoComplete="email" {...a} {...register('email')} />}
                  </FormField>
                </div>
                <FormField id="c-phone" label="Phone (optional)" error={errors.phone?.message}>
                  {(a) => <Input type="tel" autoComplete="tel" {...a} {...register('phone')} />}
                </FormField>
                <FormField id="c-subject" label="Subject" error={errors.subject?.message}>
                  {(a) => <Input {...a} {...register('subject')} />}
                </FormField>
                <FormField id="c-message" label="Message" error={errors.message?.message}>
                  {(a) => (
                    <textarea
                      rows={5}
                      className="border-input bg-background focus-visible:ring-ring rounded-md border px-3 py-2 text-sm focus-visible:ring-2 focus-visible:outline-none"
                      {...a}
                      {...register('message')}
                    />
                  )}
                </FormField>
                {/* Honeypot: hidden from people and assistive tech */}
                <input
                  type="text"
                  tabIndex={-1}
                  autoComplete="off"
                  aria-hidden
                  className="hidden"
                  {...register('website')}
                />
                <CaptchaWidget />
                <Button type="submit" loading={contact.isPending} className="justify-self-start">
                  Send message
                </Button>
              </form>
            )}
          </CardContent>
        </Card>
      </section>
    </>
  )
}
