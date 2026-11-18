import * as React from 'react'
import { cva, type VariantProps } from 'class-variance-authority'
import { cn } from '@/lib/utils'

const alertVariants = cva('relative w-full rounded-lg border p-4 text-sm', {
  variants: {
    variant: {
      default: 'bg-card',
      info: 'border-primary/30 bg-primary/5',
      destructive: 'border-destructive/40 bg-destructive/5 text-destructive',
      success: 'border-accent/40 bg-accent/5',
    },
  },
  defaultVariants: { variant: 'default' },
})

export const Alert = ({
  className,
  variant,
  ...props
}: React.HTMLAttributes<HTMLDivElement> & VariantProps<typeof alertVariants>) => (
  <div role="alert" className={cn(alertVariants({ variant }), className)} {...props} />
)
