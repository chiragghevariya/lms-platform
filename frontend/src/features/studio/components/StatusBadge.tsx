import type { ReactNode } from 'react'
import { Badge } from '@/components/ui/badge'
import type { CourseStatus } from '@/types/courses'

export const STATUS_BADGE: Record<CourseStatus, ReactNode> = {
  draft: <Badge variant="outline">Draft</Badge>,
  review: <Badge variant="secondary">In review</Badge>,
  published: <Badge>Published</Badge>,
}
