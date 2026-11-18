import * as React from 'react'
import { cn } from '@/lib/utils'

type DivProps = React.HTMLAttributes<HTMLDivElement>

export const Card = ({ className, ...p }: DivProps) => (
  <div className={cn('bg-card text-card-foreground rounded-xl border shadow-sm', className)} {...p} />
)
export const CardHeader = ({ className, ...p }: DivProps) => (
  <div className={cn('flex flex-col gap-1.5 p-6', className)} {...p} />
)
export const CardTitle = ({ className, children, ...p }: React.HTMLAttributes<HTMLHeadingElement>) => (
  <h3 className={cn('text-lg leading-tight font-semibold tracking-tight', className)} {...p}>
    {children}
  </h3>
)
export const CardDescription = ({ className, ...p }: React.HTMLAttributes<HTMLParagraphElement>) => (
  <p className={cn('text-muted-foreground text-sm', className)} {...p} />
)
export const CardContent = ({ className, ...p }: DivProps) => (
  <div className={cn('p-6 pt-0', className)} {...p} />
)
export const CardFooter = ({ className, ...p }: DivProps) => (
  <div className={cn('flex items-center p-6 pt-0', className)} {...p} />
)
