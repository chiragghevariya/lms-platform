import { ChevronLeft, ChevronRight, Search, SlidersHorizontal } from 'lucide-react'
import { useEffect, useState } from 'react'
import { useSearchParams } from 'react-router-dom'
import { Seo } from '@/components/Seo'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Skeleton } from '@/components/ui/skeleton'
import { cn } from '@/lib/utils'
import { type CatalogFilters, useCatalog, useCategories } from '../api'
import { CourseCard } from '../components/CourseCard'

const LEVELS = [
  ['beginner', 'Beginner'],
  ['intermediate', 'Intermediate'],
  ['advanced', 'Advanced'],
  ['all_levels', 'All levels'],
] as const
const TIERS = [
  ['free', 'Free'],
  ['1', 'Starter'],
  ['2', 'Pro'],
  ['3', 'Elite'],
] as const
const DURATIONS = [
  ['short', 'Under 1 hour'],
  ['medium', '1–5 hours'],
  ['long', '5+ hours'],
] as const
const SORTS = [
  ['popular', 'Most popular'],
  ['newest', 'Newest'],
  ['rating', 'Highest rated'],
  ['duration_asc', 'Shortest'],
  ['duration_desc', 'Longest'],
  ['relevance', 'Best match'],
] as const

/** Filters live in the URL so results are shareable and survive back/forward. */
export default function CatalogPage() {
  const [params, setParams] = useSearchParams()
  const [search, setSearch] = useState(params.get('q') ?? '')
  const [showFilters, setShowFilters] = useState(false)
  const { data: categories } = useCategories()

  const filters: CatalogFilters = {
    q: params.get('q') || undefined,
    category: params.get('category') || undefined,
    level: params.getAll('level'),
    tier: params.getAll('tier'),
    duration: params.getAll('duration'),
    rating: params.get('rating') || undefined,
    sort: params.get('sort') || undefined,
    page: Number(params.get('page') ?? 1),
    per_page: 12,
  }
  const { data, isLoading, isFetching } = useCatalog(filters)

  useEffect(() => {
    const t = setTimeout(() => {
      if (search === (params.get('q') ?? '')) return
      setParams((p) => {
        if (search) p.set('q', search)
        else p.delete('q')
        p.delete('page')
        return p
      })
    }, 350)
    return () => clearTimeout(t)
  }, [search]) // eslint-disable-line react-hooks/exhaustive-deps

  const toggle = (key: string, value: string) =>
    setParams((p) => {
      const values = p.getAll(key)
      p.delete(key)
      ;(values.includes(value) ? values.filter((v) => v !== value) : [...values, value]).forEach((v) =>
        p.append(key, v),
      )
      p.delete('page')
      return p
    })
  const setOne = (key: string, value: string) =>
    setParams((p) => {
      if (value) p.set(key, value)
      else p.delete(key)
      if (key !== 'page') p.delete('page')
      return p
    })

  const activeCount =
    ['level', 'tier', 'duration'].reduce((n, k) => n + params.getAll(k).length, 0) +
    (params.get('rating') ? 1 : 0)

  const Check = ({ group, value, label }: { group: string; value: string; label: string }) => (
    <label className="flex items-center gap-2 text-sm">
      <input
        type="checkbox"
        className="accent-primary size-4"
        checked={params.getAll(group).includes(value)}
        onChange={() => toggle(group, value)}
      />
      {label}
    </label>
  )

  return (
    <section className="mx-auto max-w-6xl px-4 py-12">
      <Seo
        title="Courses"
        path="/courses"
        description="Browse practical courses in development, marketing, design and the creator economy."
      />
      <h1 className="text-4xl font-extrabold tracking-tight">Course catalogue</h1>
      <p className="text-muted-foreground mt-2">
        Every course is included in a membership tier. Higher tiers include everything below.
      </p>

      <div className="mt-8 flex flex-col gap-3 sm:flex-row">
        <div className="relative flex-1">
          <Search
            className="text-muted-foreground absolute top-1/2 left-3 size-4 -translate-y-1/2"
            aria-hidden
          />
          <Input
            type="search"
            placeholder="Search courses, skills or instructors"
            aria-label="Search courses"
            className="pl-9"
            value={search}
            onChange={(e) => setSearch(e.target.value)}
          />
        </div>
        <select
          aria-label="Sort by"
          className="border-input bg-background h-10 rounded-md border px-3 text-sm"
          value={params.get('sort') ?? ''}
          onChange={(e) => setOne('sort', e.target.value)}
        >
          <option value="">{filters.q ? 'Best match' : 'Most popular'}</option>
          {SORTS.filter(([v]) => v !== 'relevance' || filters.q).map(([v, l]) => (
            <option key={v} value={v}>
              {l}
            </option>
          ))}
        </select>
        <Button
          variant="outline"
          className="lg:hidden"
          onClick={() => setShowFilters((s) => !s)}
          aria-expanded={showFilters}
          aria-controls="catalog-filters"
        >
          <SlidersHorizontal aria-hidden /> Filters{activeCount ? ` (${activeCount})` : ''}
        </Button>
      </div>

      <nav aria-label="Categories" className="mt-4 flex flex-wrap gap-2">
        {[{ slug: '', name: 'All' }, ...(categories ?? [])].map((c) => (
          <button
            key={c.slug || 'all'}
            onClick={() => setOne('category', c.slug)}
            aria-pressed={(params.get('category') ?? '') === c.slug}
            className={cn(
              'rounded-full border px-3 py-1 text-sm',
              (params.get('category') ?? '') === c.slug
                ? 'bg-primary text-primary-foreground border-primary'
                : 'hover:bg-muted',
            )}
          >
            {c.name}
          </button>
        ))}
      </nav>

      <div className="mt-8 grid gap-8 lg:grid-cols-[14rem_1fr]">
        <aside
          id="catalog-filters"
          aria-label="Filters"
          className={cn('grid content-start gap-6', !showFilters && 'hidden lg:grid')}
        >
          <fieldset className="grid gap-2">
            <legend className="mb-1 text-sm font-semibold">Membership tier</legend>
            {TIERS.map(([v, l]) => (
              <Check key={v} group="tier" value={v} label={l} />
            ))}
          </fieldset>
          <fieldset className="grid gap-2">
            <legend className="mb-1 text-sm font-semibold">Level</legend>
            {LEVELS.map(([v, l]) => (
              <Check key={v} group="level" value={v} label={l} />
            ))}
          </fieldset>
          <fieldset className="grid gap-2">
            <legend className="mb-1 text-sm font-semibold">Duration</legend>
            {DURATIONS.map(([v, l]) => (
              <Check key={v} group="duration" value={v} label={l} />
            ))}
          </fieldset>
          <fieldset className="grid gap-2">
            <legend className="mb-1 text-sm font-semibold">Rating</legend>
            {['4.5', '4', '3.5'].map((r) => (
              <label key={r} className="flex items-center gap-2 text-sm">
                <input
                  type="radio"
                  name="rating"
                  className="accent-primary size-4"
                  checked={params.get('rating') === r}
                  onChange={() => setOne('rating', r)}
                />
                {r}+ stars
              </label>
            ))}
          </fieldset>
          {activeCount > 0 && (
            <Button
              variant="ghost"
              size="sm"
              className="justify-self-start"
              onClick={() => setParams(params.get('q') ? { q: params.get('q')! } : {})}
            >
              Clear filters
            </Button>
          )}
        </aside>

        <div aria-busy={isFetching} aria-live="polite">
          <p className="text-muted-foreground mb-4 text-sm">
            {data ? `${data.meta.total} course${data.meta.total === 1 ? '' : 's'}` : ' '}
          </p>
          <div className="grid gap-6 sm:grid-cols-2 xl:grid-cols-3">
            {isLoading &&
              Array.from({ length: 6 }).map((_, i) => <Skeleton key={i} className="h-80 rounded-xl" />)}
            {data?.data.map((c) => (
              <CourseCard key={c.id} course={c} headingLevel="h2" />
            ))}
          </div>
          {data && data.data.length === 0 && (
            <div className="text-muted-foreground rounded-xl border border-dashed p-12 text-center">
              No courses match these filters.
            </div>
          )}
          {data && data.meta.last_page > 1 && (
            <nav aria-label="Pagination" className="mt-8 flex items-center justify-center gap-3 text-sm">
              <Button
                variant="outline"
                size="sm"
                disabled={data.meta.current_page <= 1}
                onClick={() => setOne('page', String(data.meta.current_page - 1))}
              >
                <ChevronLeft aria-hidden /> Previous
              </Button>
              <span>
                Page {data.meta.current_page} of {data.meta.last_page}
              </span>
              <Button
                variant="outline"
                size="sm"
                disabled={data.meta.current_page >= data.meta.last_page}
                onClick={() => setOne('page', String(data.meta.current_page + 1))}
              >
                Next <ChevronRight aria-hidden />
              </Button>
            </nav>
          )}
        </div>
      </div>
    </section>
  )
}
