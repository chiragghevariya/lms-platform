import { useState } from 'react'
import { Input } from '@/components/ui/input'

/** Two-letter country filter: only applies once the code is complete (or cleared). */
export function CountryInput({ value, onChange }: { value?: string; onChange: (code: string) => void }) {
  const [text, setText] = useState(value ?? '')
  return (
    <Input
      aria-label="Country code"
      placeholder="Country (IN)"
      maxLength={2}
      className="w-28 uppercase"
      value={text}
      onChange={(e) => {
        const v = e.target.value.replace(/[^a-z]/gi, '').toUpperCase()
        setText(v)
        if (v.length === 0 || v.length === 2) onChange(v)
      }}
    />
  )
}
