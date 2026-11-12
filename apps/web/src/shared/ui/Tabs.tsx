import { useRef } from 'react'
import type { KeyboardEvent } from 'react'
import { cn } from '@/shared/lib/cn'

export interface TabItem {
  id: string
  label: string
}

/** Accessible tab list: arrow keys move between tabs, only the active tab is in the tab order. */
export function Tabs({
  label,
  tabs,
  value,
  onChange,
  idPrefix,
}: {
  label: string
  tabs: TabItem[]
  value: string
  onChange: (id: string) => void
  idPrefix: string
}) {
  const refs = useRef<Record<string, HTMLButtonElement | null>>({})

  const onKeyDown = (event: KeyboardEvent, index: number) => {
    let target = -1
    if (event.key === 'ArrowRight') target = (index + 1) % tabs.length
    else if (event.key === 'ArrowLeft') target = (index - 1 + tabs.length) % tabs.length
    else if (event.key === 'Home') target = 0
    else if (event.key === 'End') target = tabs.length - 1
    if (target < 0) return
    event.preventDefault()
    const next = tabs[target]
    if (next) {
      onChange(next.id)
      refs.current[next.id]?.focus()
    }
  }

  return (
    <div
      role="tablist"
      aria-label={label}
      className="-mx-4 flex gap-1 overflow-x-auto border-b border-line px-4 sm:mx-0 sm:px-0"
    >
      {tabs.map((tab, index) => {
        const active = tab.id === value
        return (
          <button
            key={tab.id}
            ref={(node) => {
              refs.current[tab.id] = node
            }}
            role="tab"
            type="button"
            id={`${idPrefix}-tab-${tab.id}`}
            aria-selected={active}
            aria-controls={`${idPrefix}-panel-${tab.id}`}
            tabIndex={active ? 0 : -1}
            onClick={() => onChange(tab.id)}
            onKeyDown={(event) => onKeyDown(event, index)}
            className={cn(
              '-mb-px min-h-11 shrink-0 border-b-2 px-3 text-sm font-medium transition-colors',
              active ? 'border-brand text-ink' : 'border-transparent text-muted hover:text-ink',
            )}
          >
            {tab.label}
          </button>
        )
      })}
    </div>
  )
}
