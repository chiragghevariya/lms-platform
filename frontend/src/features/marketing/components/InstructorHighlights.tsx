import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar'
import { Badge } from '@/components/ui/badge'
import { Card, CardContent } from '@/components/ui/card'
import { Skeleton } from '@/components/ui/skeleton'
import { useFeaturedInstructors } from '../api'

export function InstructorHighlights() {
  const { data, isLoading, isError } = useFeaturedInstructors()
  if (isError || (data && data.length === 0)) return null

  return (
    <div className="grid gap-6 sm:grid-cols-2 lg:grid-cols-3">
      {isLoading && Array.from({ length: 3 }).map((_, i) => <Skeleton key={i} className="h-48 rounded-xl" />)}
      {data?.map((i) => (
        <Card key={i.id}>
          <CardContent className="flex flex-col items-center gap-3 p-6 text-center">
            <Avatar className="size-20 text-lg">
              {i.avatar_url && <AvatarImage src={i.avatar_url} alt="" />}
              <AvatarFallback>{i.name.slice(0, 2).toUpperCase()}</AvatarFallback>
            </Avatar>
            <div>
              <h3 className="font-semibold">{i.name}</h3>
              {i.headline && <p className="text-muted-foreground text-sm">{i.headline}</p>}
            </div>
            <div className="flex flex-wrap justify-center gap-1">
              {i.expertise.map((e) => (
                <Badge key={e} variant="secondary" className="normal-case">
                  {e}
                </Badge>
              ))}
            </div>
          </CardContent>
        </Card>
      ))}
    </div>
  )
}
