import { Link } from 'react-router-dom'
import { Button } from '@/components/ui/button'

export default function NotFound() {
  return (
    <div className="grid min-h-[60dvh] place-items-center px-4 text-center">
      <div>
        <p className="text-primary text-sm font-semibold">404</p>
        <h1 className="mt-2 text-3xl font-bold">Page not found</h1>
        <p className="text-muted-foreground mt-2">The page you're looking for doesn't exist.</p>
        <Button asChild className="mt-6">
          <Link to="/">Go home</Link>
        </Button>
      </div>
    </div>
  )
}
