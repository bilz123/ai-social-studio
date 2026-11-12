import { env } from '@/shared/lib/env'

/** A 9:16 frame with a tungsten dot: the format every video in the studio is made in. */
export function LogoMark({ className = 'h-7 w-[1.0625rem]' }: { className?: string }) {
  return (
    <span
      aria-hidden="true"
      className={`relative inline-block shrink-0 rounded-[5px] border-2 border-current ${className}`}
    >
      <span className="absolute bottom-[3px] left-1/2 size-1.5 -translate-x-1/2 rounded-full bg-accent" />
    </span>
  )
}

export function Logo() {
  return (
    <span className="inline-flex items-center gap-2.5 font-display text-lg font-semibold tracking-tight">
      <LogoMark />
      {env.appName}
    </span>
  )
}
