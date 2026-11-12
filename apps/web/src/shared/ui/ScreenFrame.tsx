import { useEffect, useState } from 'react'

const CLIP_SECONDS = 10

function timecode(seconds: number): string {
  return `00:00:${String(seconds).padStart(2, '0')}`
}

function prefersReducedMotion(): boolean {
  return typeof window.matchMedia === 'function'
    ? window.matchMedia('(prefers-reduced-motion: reduce)').matches
    : false
}

/**
 * The one memorable thing in the interface: a vertical 9:16 viewfinder that plays out a
 * ten-second clip, the length of every video the studio makes. Purely decorative (hidden from
 * assistive tech); motion stops for people who ask for less of it.
 */
export function ScreenFrame() {
  const [second, setSecond] = useState(() => (prefersReducedMotion() ? CLIP_SECONDS : 0))

  useEffect(() => {
    if (prefersReducedMotion()) return
    const timer = window.setInterval(() => setSecond((s) => (s + 1) % (CLIP_SECONDS + 1)), 1000)
    return () => window.clearInterval(timer)
  }, [])

  return (
    <div
      aria-hidden="true"
      className="relative aspect-[9/16] w-full max-w-[19rem] overflow-hidden rounded-[2rem] border border-white/15 bg-[#0d0618] shadow-2xl shadow-black/40"
    >
      <div className="animate-drift-a absolute -left-1/4 top-0 size-[85%] rounded-full bg-[radial-gradient(closest-side,#f5a524,transparent)] opacity-80 blur-2xl [animation:drift-a_14s_ease-in-out_infinite]" />
      <div className="animate-drift-b absolute -right-1/4 bottom-0 size-[95%] rounded-full bg-[radial-gradient(closest-side,#7a4cf0,transparent)] opacity-80 blur-2xl [animation:drift-b_17s_ease-in-out_infinite]" />

      {/* Viewfinder corners */}
      <span className="absolute left-4 top-4 size-4 border-l-2 border-t-2 border-white/70" />
      <span className="absolute right-4 top-4 size-4 border-r-2 border-t-2 border-white/70" />
      <span className="absolute bottom-4 left-4 size-4 border-b-2 border-l-2 border-white/70" />
      <span className="absolute bottom-4 right-4 size-4 border-b-2 border-r-2 border-white/70" />

      <div className="absolute inset-x-0 bottom-0 p-7">
        <p className="font-display text-3xl font-semibold tabular-nums tracking-tight text-white">
          {timecode(second)}
        </p>
        <div className="mt-3 h-1 overflow-hidden rounded-full bg-white/25">
          <div className="animate-run h-full origin-left rounded-full bg-accent [animation:run-ten-seconds_10s_linear_infinite]" />
        </div>
      </div>
    </div>
  )
}
