import { cn } from '@/shared/lib/cn'

export type ButtonVariant = 'primary' | 'secondary' | 'danger' | 'ghost'
export type ButtonSize = 'md' | 'sm'

const variants: Record<ButtonVariant, string> = {
  primary: 'bg-brand text-brand-contrast hover:bg-brand-hover',
  secondary: 'border border-line bg-raised text-ink hover:bg-sunken',
  danger: 'bg-danger text-surface hover:opacity-90',
  ghost: 'text-ink hover:bg-sunken',
}

const sizes: Record<ButtonSize, string> = {
  md: 'min-h-11 px-5 text-sm',
  sm: 'min-h-9 px-3 text-sm',
}

/** Shared by <Button> and links that should look like one. */
export function buttonClasses(
  variant: ButtonVariant = 'primary',
  size: ButtonSize = 'md',
  className?: string,
) {
  return cn(
    'inline-flex items-center justify-center gap-2 rounded-lg font-semibold transition-colors',
    'disabled:cursor-not-allowed disabled:opacity-60',
    variants[variant],
    sizes[size],
    className,
  )
}
