import { useQueryClient } from '@tanstack/react-query'
import { ExternalLink, Flame, Star, X } from 'lucide-react'
import { useState } from 'react'
import { Link } from 'react-router-dom'
import { toast } from 'sonner'
import { PageHeader } from '@/components/PageHeader'
import { Seo } from '@/components/Seo'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card'
import { FormField } from '@/components/ui/form-field'
import { Input } from '@/components/ui/input'
import { Skeleton } from '@/components/ui/skeleton'
import { ApiError } from '@/lib/api-client'
import { formatDate } from '@/lib/format'
import { cn } from '@/lib/utils'
import type { LearningProfile } from '@/types/learning'
import { learningApi, useAchievements, useLearningProfile } from '../api'
import { BadgeIcon } from '../components/BadgeIcon'

export default function LearningProfilePage() {
  const { data } = useLearningProfile()
  return (
    <>
      <Seo title="Learning profile" noindex />
      <PageHeader title="Learning profile" description="Your skills, goals and achievements." />
      <div className="grid gap-6 lg:grid-cols-2">
        {data ? <ProfileForm key={JSON.stringify(data)} initial={data} /> : <Skeleton className="h-80" />}
        <AchievementsCard />
      </div>
    </>
  )
}

function ProfileForm({ initial }: { initial: LearningProfile }) {
  const qc = useQueryClient()
  const [skills, setSkills] = useState(initial.skills)
  const [skill, setSkill] = useState('')
  const [goals, setGoals] = useState(initial.learning_goals ?? '')
  const [handle, setHandle] = useState(initial.public_handle ?? '')
  const [isPublic, setIsPublic] = useState(initial.is_public)
  const [errors, setErrors] = useState<Record<string, string>>({})
  const [saving, setSaving] = useState(false)

  const addSkill = () => {
    const s = skill.trim()
    if (s && !skills.some((x) => x.toLowerCase() === s.toLowerCase()) && skills.length < 30)
      setSkills([...skills, s])
    setSkill('')
  }

  const save = async () => {
    setSaving(true)
    setErrors({})
    try {
      const res = await learningApi.updateLearningProfile({
        skills,
        learning_goals: goals.trim() || null,
        public_handle: handle.trim().toLowerCase() || null,
        is_public: isPublic,
      })
      qc.setQueryData(['me', 'learning-profile'], res)
      toast.success('Profile saved')
    } catch (e) {
      if (e instanceof ApiError && e.isValidation) {
        setErrors(Object.fromEntries(Object.entries(e.errors).map(([k, v]) => [k.split('.')[0], v[0] ?? ''])))
      } else toast.error(e instanceof Error ? e.message : 'Could not save')
    } finally {
      setSaving(false)
    }
  }

  return (
    <Card>
      <CardHeader>
        <CardTitle>About your learning</CardTitle>
        <CardDescription>Shown on your public profile if you turn it on.</CardDescription>
      </CardHeader>
      <CardContent>
        <form
          className="grid gap-4"
          onSubmit={(e) => {
            e.preventDefault()
            void save()
          }}
        >
          <FormField id="lp-skill" label="Skills" hint="Press Enter to add" error={errors.skills}>
            {(a) => (
              <Input
                {...a}
                value={skill}
                maxLength={40}
                onChange={(e) => setSkill(e.target.value)}
                onKeyDown={(e) => {
                  if (e.key === 'Enter' || e.key === ',') {
                    e.preventDefault()
                    addSkill()
                  }
                }}
              />
            )}
          </FormField>
          {skills.length > 0 && (
            <ul className="-mt-2 flex flex-wrap gap-2" aria-label="Your skills">
              {skills.map((s) => (
                <li key={s}>
                  <Badge variant="secondary" className="gap-1 pr-1">
                    {s}
                    <button
                      type="button"
                      aria-label={`Remove ${s}`}
                      className="hover:bg-background/60 rounded-full p-0.5"
                      onClick={() => setSkills(skills.filter((x) => x !== s))}
                    >
                      <X className="size-3" aria-hidden />
                    </button>
                  </Badge>
                </li>
              ))}
            </ul>
          )}
          <div className="grid gap-1.5">
            <label htmlFor="lp-goals" className="text-sm font-medium">
              Learning goals
            </label>
            <textarea
              id="lp-goals"
              rows={3}
              maxLength={2000}
              value={goals}
              onChange={(e) => setGoals(e.target.value)}
              placeholder="e.g. Land my first freelance client in 3 months"
              className="border-input bg-background focus-visible:ring-ring rounded-md border px-3 py-2 text-sm focus-visible:ring-2 focus-visible:outline-none"
            />
          </div>
          <FormField
            id="lp-handle"
            label="Public handle"
            hint="Lowercase letters, numbers, - and _"
            error={errors.public_handle}
          >
            {(a) => (
              <Input {...a} value={handle} maxLength={40} onChange={(e) => setHandle(e.target.value)} />
            )}
          </FormField>
          <label className="flex items-center gap-3 text-sm">
            <input
              type="checkbox"
              checked={isPublic}
              onChange={(e) => setIsPublic(e.target.checked)}
              className="size-4"
            />
            Make my learning profile public (name, skills, badges and certificates)
          </label>
          {initial.is_public && initial.public_handle && (
            <Link
              to={`/u/${initial.public_handle}`}
              className="text-primary inline-flex items-center gap-1 text-sm hover:underline"
            >
              View public profile <ExternalLink className="size-3.5" aria-hidden />
            </Link>
          )}
          <Button type="submit" loading={saving} className="justify-self-start">
            Save profile
          </Button>
        </form>
      </CardContent>
    </Card>
  )
}

function AchievementsCard() {
  const { data } = useAchievements()
  if (!data) return <Skeleton className="h-80" />
  return (
    <Card>
      <CardHeader>
        <CardTitle>Achievements</CardTitle>
        <CardDescription className="flex flex-wrap gap-4">
          <span className="inline-flex items-center gap-1">
            <Star className="size-4" aria-hidden /> {data.points} points
          </span>
          <span className="inline-flex items-center gap-1">
            <Flame className="size-4" aria-hidden /> {data.streak.current}-day streak (best{' '}
            {data.streak.longest})
          </span>
        </CardDescription>
      </CardHeader>
      <CardContent>
        <ul className="grid grid-cols-2 gap-3 sm:grid-cols-3">
          {data.badges.map((b) => (
            <li
              key={b.key}
              className={cn(
                'grid justify-items-center gap-1 rounded-lg border p-3 text-center',
                !b.earned && 'opacity-50',
              )}
            >
              <span
                className={cn(
                  'grid size-10 place-items-center rounded-full',
                  b.earned ? 'bg-accent/15 text-accent' : 'bg-muted text-muted-foreground',
                )}
              >
                <BadgeIcon badgeKey={b.key} className="size-5" />
              </span>
              <span className="text-sm font-medium">{b.name}</span>
              <span className="text-muted-foreground text-xs">
                {b.earned ? `Earned ${formatDate(b.awarded_at)}` : b.description}
              </span>
              <span className="sr-only">{b.earned ? 'earned' : 'not yet earned'}</span>
            </li>
          ))}
        </ul>
      </CardContent>
    </Card>
  )
}
