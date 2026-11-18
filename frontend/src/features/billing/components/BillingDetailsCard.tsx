import { useMutation, useQueryClient } from '@tanstack/react-query'
import { useState } from 'react'
import { toast } from 'sonner'
import { Button } from '@/components/ui/button'
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card'
import { FormField } from '@/components/ui/form-field'
import { Input } from '@/components/ui/input'
import { applyServerErrors } from '@/lib/form-errors'
import { useAuthStore } from '@/stores/auth-store'
import { billingApi } from '../api'

/** Name/address/GSTIN printed on future invoices. */
export function BillingDetailsCard() {
  const qc = useQueryClient()
  const billing = useAuthStore((s) => s.user?.billing)
  const [values, setValues] = useState({
    billing_name: billing?.name ?? '',
    tax_id: billing?.tax_id ?? '',
    line1: billing?.address.line1 ?? '',
    city: billing?.address.city ?? '',
    state: billing?.address.state ?? '',
    postal_code: billing?.address.postal_code ?? '',
  })
  const [errors, setErrors] = useState<Record<string, string>>({})
  const save = useMutation({
    mutationFn: () =>
      billingApi.updateDetails({
        billing_name: values.billing_name || null,
        tax_id: values.tax_id || null,
        billing_address: {
          line1: values.line1,
          city: values.city,
          state: values.state,
          postal_code: values.postal_code,
        },
      }),
    onSuccess: () => {
      toast.success('Billing details saved')
      void qc.invalidateQueries({ queryKey: ['me'] })
    },
  })

  const field = (key: keyof typeof values, label: string, hint?: string) => (
    <FormField id={`bd-${key}`} label={label} hint={hint} error={errors[key]}>
      {(a) => (
        <Input
          {...a}
          value={values[key]}
          onChange={(e) => setValues((v) => ({ ...v, [key]: e.target.value }))}
        />
      )}
    </FormField>
  )

  return (
    <Card>
      <CardHeader>
        <CardTitle>Invoice details</CardTitle>
        <CardDescription>Shown on future invoices. Add a GSTIN to claim input tax credit.</CardDescription>
      </CardHeader>
      <CardContent>
        <form
          className="grid gap-4 sm:grid-cols-2"
          onSubmit={async (e) => {
            e.preventDefault()
            setErrors({})
            try {
              await save.mutateAsync()
            } catch (err) {
              const collected: Record<string, string> = {}
              applyServerErrors<Record<string, string>>(err, (name, { message }) => {
                collected[String(name).replace('billing_address.', '')] = message ?? ''
              })
              setErrors(collected)
            }
          }}
        >
          {field('billing_name', 'Name / company')}
          {field('tax_id', 'GSTIN / VAT ID', 'Optional')}
          {field('line1', 'Address')}
          {field('city', 'City')}
          {field('state', 'State')}
          {field('postal_code', 'PIN / postal code')}
          <Button type="submit" loading={save.isPending} className="justify-self-start">
            Save details
          </Button>
        </form>
      </CardContent>
    </Card>
  )
}
