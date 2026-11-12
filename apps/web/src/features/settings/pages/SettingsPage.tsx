import { Monitor, Moon, Sun } from 'lucide-react'
import { useState } from 'react'
import type { ReactNode } from 'react'
import { useSearchParams } from 'react-router-dom'
import { DeleteAccount } from '@/features/account/components/DeleteAccount'
import { ProfileForm } from '@/features/account/components/ProfileForm'
import { PasswordForm } from '@/features/account/components/PasswordForm'
import { AccountActivity } from '@/features/dashboard/components/AccountActivity'
import { PageHeader } from '@/shared/layout/PageHeader'
import { cn } from '@/shared/lib/cn'
import { useTheme } from '@/shared/theme/useTheme'
import type { ThemeChoice } from '@/shared/theme/themeContext'
import { useToast } from '@/shared/toast/useToast'
import { Badge } from '@/shared/ui/Badge'
import { Card, CardHeader } from '@/shared/ui/Card'
import { Switch } from '@/shared/ui/Switch'
import { Tabs } from '@/shared/ui/Tabs'

const TABS = [
  { id: 'profile', label: 'Profile' },
  { id: 'security', label: 'Security' },
  { id: 'appearance', label: 'Appearance' },
  { id: 'notifications', label: 'Notifications' },
]

function Panel({ id, children }: { id: string; children: ReactNode }) {
  return (
    <div
      role="tabpanel"
      id={`settings-panel-${id}`}
      aria-labelledby={`settings-tab-${id}`}
      tabIndex={0}
      className="mt-6 space-y-6"
    >
      {children}
    </div>
  )
}

const THEME_OPTIONS: { id: ThemeChoice; label: string; hint: string; icon: typeof Sun }[] = [
  { id: 'light', label: 'Light', hint: 'Bright surfaces', icon: Sun },
  { id: 'dark', label: 'Dark', hint: 'Easy on the eyes', icon: Moon },
  { id: 'system', label: 'System', hint: 'Match your device', icon: Monitor },
]

function Appearance() {
  const { choice, setChoice } = useTheme()
  return (
    <Card aria-labelledby="appearance-heading">
      <CardHeader id="appearance-heading" title="Theme" description="Saved on this device." />
      <fieldset className="p-5">
        <legend className="sr-only">Theme</legend>
        <div className="grid gap-3 sm:grid-cols-3">
          {THEME_OPTIONS.map(({ id, label, hint, icon: Icon }) => (
            <label
              key={id}
              className={cn(
                'relative flex cursor-pointer items-center gap-3 rounded-xl border p-4 transition-colors',
                'has-[:focus-visible]:outline-2 has-[:focus-visible]:outline-offset-2 has-[:focus-visible]:outline-focus',
                choice === id ? 'border-brand bg-sunken' : 'border-line hover:bg-sunken',
              )}
            >
              <input
                type="radio"
                name="theme"
                value={id}
                checked={choice === id}
                onChange={() => setChoice(id)}
                className="sr-only"
              />
              <Icon aria-hidden="true" className="size-5 shrink-0 text-muted" />
              <span>
                <span className="block text-sm font-semibold">{label}</span>
                <span className="block text-sm text-muted">{hint}</span>
              </span>
            </label>
          ))}
        </div>
      </fieldset>
    </Card>
  )
}

function NotificationPreferences() {
  const toast = useToast()
  // PLACEHOLDER: preferences are not stored yet (no API endpoint); they reset on reload.
  const [prefs, setPrefs] = useState({
    videoReady: true,
    publishing: true,
    accounts: true,
    product: false,
  })
  const set = (key: keyof typeof prefs) => (value: boolean) => {
    setPrefs((current) => ({ ...current, [key]: value }))
    toast.show({ title: 'Preference updated', description: 'Sample setting: it is not saved yet.' })
  }

  return (
    <Card aria-labelledby="notif-heading">
      <CardHeader
        id="notif-heading"
        title="Email notifications"
        description="Choose what we email you about."
        action={<Badge tone="warn">Preview</Badge>}
      />
      <div className="divide-y divide-line px-5">
        <Switch
          label="Video ready for review"
          description="When a generated video is waiting for your approval."
          checked={prefs.videoReady}
          onChange={set('videoReady')}
        />
        <Switch
          label="Publishing results"
          description="When a post is published or fails."
          checked={prefs.publishing}
          onChange={set('publishing')}
        />
        <Switch
          label="Account connections"
          description="When a connected account needs to be reconnected."
          checked={prefs.accounts}
          onChange={set('accounts')}
        />
        <Switch
          label="Product updates"
          description="Occasional news about new features."
          checked={prefs.product}
          onChange={set('product')}
        />
      </div>
    </Card>
  )
}

export function SettingsPage() {
  const [params, setParams] = useSearchParams()
  const requested = params.get('tab')
  const tab = TABS.some((item) => item.id === requested) ? (requested as string) : 'profile'

  return (
    <>
      <PageHeader
        title="Settings"
        description="Manage your profile, security and how the studio looks."
      />
      <Tabs
        label="Settings sections"
        idPrefix="settings"
        tabs={TABS}
        value={tab}
        onChange={(id) => setParams(id === 'profile' ? {} : { tab: id }, { replace: true })}
      />

      {tab === 'profile' && (
        <Panel id="profile">
          <Card aria-labelledby="profile-heading" className="max-w-2xl">
            <CardHeader
              id="profile-heading"
              title="Your details"
              description="Your name and sign-in email."
            />
            <div className="p-5">
              <ProfileForm />
            </div>
          </Card>
          <Card aria-labelledby="delete-heading" className="max-w-2xl">
            <CardHeader
              id="delete-heading"
              title="Delete account"
              description="Remove your data from the studio."
            />
            <div className="p-5">
              <DeleteAccount />
            </div>
          </Card>
        </Panel>
      )}
      {tab === 'security' && (
        <Panel id="security">
          <Card aria-labelledby="password-heading" className="max-w-2xl">
            <CardHeader
              id="password-heading"
              title="Password"
              description="Changing it signs you out everywhere else."
            />
            <div className="p-5">
              <PasswordForm />
            </div>
          </Card>
          <div className="max-w-2xl">
            <AccountActivity />
          </div>
        </Panel>
      )}
      {tab === 'appearance' && (
        <Panel id="appearance">
          <div className="max-w-3xl">
            <Appearance />
          </div>
        </Panel>
      )}
      {tab === 'notifications' && (
        <Panel id="notifications">
          <div className="max-w-2xl">
            <NotificationPreferences />
          </div>
        </Panel>
      )}
    </>
  )
}
