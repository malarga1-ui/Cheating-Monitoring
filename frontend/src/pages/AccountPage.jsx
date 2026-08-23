import { useEffect, useState } from 'react'
import { Link } from 'react-router-dom'
import { api } from '../api'
import { useAuth } from '../auth'
import { useI18n } from '../i18n'
import { fmtTime } from '../lib/format'

function Row({ label, value, hint, copyable }) {
  const { t } = useI18n()
  const [copied, setCopied] = useState(false)

  async function copy() {
    try {
      await navigator.clipboard.writeText(value)
      setCopied(true)
      setTimeout(() => setCopied(false), 1500)
    } catch {
      /* ignore */
    }
  }

  return (
    <div className="rounded-xl border border-slate-200 bg-white p-4">
      <p className="text-xs font-bold text-slate-400">{label}</p>
      <div className="mt-1 flex items-center gap-2">
        <p className="min-w-0 flex-1 break-all text-sm font-bold text-slate-800" dir="ltr" style={{ textAlign: 'left' }}>
          {value}
        </p>
        {copyable && (
          <button
            onClick={copy}
            className="shrink-0 rounded-lg border border-slate-200 px-2.5 py-1 text-xs font-bold text-slate-500 transition-colors hover:bg-slate-50 hover:text-brand-600"
          >
            {copied ? t('common.copied') : t('common.copy')}
          </button>
        )}
      </div>
      {hint && <p className="mt-1.5 text-xs font-semibold text-slate-400">{hint}</p>}
    </div>
  )
}

function StatusBadge({ status, remainingDays }) {
  const { t } = useI18n()
  const map = {
    trial: ['bg-emerald-50 text-emerald-700 ring-emerald-200', t('account.status.trial')],
    active: ['bg-emerald-50 text-emerald-700 ring-emerald-200', t('account.status.active')],
    expired: ['bg-rose-50 text-rose-700 ring-rose-200', t('account.status.expired')],
    suspended: ['bg-slate-100 text-slate-600 ring-slate-200', t('account.status.suspended')],
  }
  const [cls, label] = map[status] || map.trial
  return (
    <span className={`inline-flex items-center gap-1.5 rounded-full px-3 py-1 text-xs font-bold ring-1 ${cls}`}>
      {status === 'trial' && Number(remainingDays) > 0 && (
        <span className="h-1.5 w-1.5 animate-pulse rounded-full bg-emerald-500" />
      )}
      {label}
    </span>
  )
}

export default function AccountPage() {
  const { t } = useI18n()
  const { user, refresh } = useAuth()
  const [me, setMe] = useState(null)
  const [error, setError] = useState('')
  const [busy, setBusy] = useState(false)
  const [showBuy, setShowBuy] = useState(false)
  const [editDomain, setEditDomain] = useState(false)
  const [domainInput, setDomainInput] = useState('')
  const [savingDomain, setSavingDomain] = useState(false)

  function load() {
    api
      .get('/api/accounts/me')
      .then((d) => {
        setMe(d)
        setError('')
      })
      .catch((e) => setError(e.message || t('err.generic')))
  }

  useEffect(() => {
    load()
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [])

  async function rotate() {
    if (!window.confirm(t('account.rotate.confirm'))) return
    setBusy(true)
    try {
      const d = await api.post('/api/accounts/rotate-secret', {})
      setMe((m) => ({ ...m, api_secret: d?.api_secret }))
      await refresh()
    } catch (e) {
      setError(e.message || t('err.generic'))
    } finally {
      setBusy(false)
    }
  }

  async function saveDomain() {
    const url = domainInput.trim()
    if (!url) return
    setSavingDomain(true)
    try {
      await api.post('/api/accounts/set-site-domain', { site_domain: url })
      setMe((m) => ({ ...m, status: { ...m.status, site_domain: url } }))
      setEditDomain(false)
      setDomainInput('')
    } catch (e) {
      setError(e.message || t('err.generic'))
    } finally {
      setSavingDomain(false)
    }
  }

  const status = me?.status || { status: 'trial', remaining_days: 0 }
  const isOwner = user?.role === 'owner'
  const secret = me?.api_secret || ''

  return (
    <div className="mx-auto max-w-2xl">
      <div className="mb-6">
        <h1 className="text-2xl font-extrabold text-slate-800">{t('account.title')}</h1>
        <p className="mt-1 text-sm font-semibold text-slate-500">{user?.email}</p>
      </div>

      {error && (
        <div className="mb-5 rounded-xl border border-rose-200 bg-rose-50 px-4 py-3 text-sm font-bold text-rose-700">
          {error}
        </div>
      )}

      <div className="mb-5 flex flex-wrap items-center gap-3 rounded-2xl bg-gradient-to-br from-brand-600 to-violet-600 p-5 text-white shadow-lg shadow-brand-600/20">
        <div className="min-w-0 flex-1">
          <p className="text-xs font-bold uppercase tracking-wide text-white/70">{t('account.plan')}</p>
          <p className="mt-1 text-lg font-extrabold">
            {isOwner ? t('role.owner') : status.status === 'trial' ? t('account.plan.trial') : status.status === 'active' ? t('account.status.active') : t('account.status.expired')}
          </p>
        </div>
        <div className="flex items-center gap-2">
          <StatusBadge status={status.status} remainingDays={status.remaining_days} />
          {!isOwner && status.status === 'trial' && Number(status.remaining_days) >= 0 && (
            <span className="rounded-full bg-white/20 px-3 py-1 text-xs font-extrabold ring-1 ring-white/30">
              {t('trial.badge', { days: status.remaining_days })}
            </span>
          )}
        </div>
      </div>

      <div className="mb-5 grid gap-3">
        <Row label={t('account.org')} value={me?.user?.org_name || user?.org_name || '—'} />
        <Row label={t('account.role')} value={isOwner ? t('role.owner') : user?.staffRole === 'admin' ? t('role.staff.admin') : user?.staffRole === 'supervisor' ? t('role.staff.supervisor') : t('role.customer')} />
        {!isOwner && status.status === 'trial' && (
          <>
            <Row label={t('account.remaining')} value={`${status.remaining_days}`} />
            <Row label={t('account.trial.ends')} value={status.trial_ends_at ? fmtTime(status.trial_ends_at) : '—'} />
          </>
        )}
        <Row label={t('account.domain')} value={me?.status?.site_domain || t('account.domain.empty')} />
        {!isOwner && (
          editDomain ? (
            <div className="rounded-xl border border-blue-200 bg-blue-50 p-4">
              <p className="text-xs font-bold text-blue-700 mb-2">أدخل رابط موقع المودل</p>
              <input
                type="text"
                value={domainInput}
                onChange={(e) => setDomainInput(e.target.value)}
                placeholder="مثال: moodle.example.com"
                className="w-full rounded-lg border border-blue-200 bg-white px-3 py-2 text-sm font-bold text-slate-800 placeholder:text-slate-400 focus:border-blue-500 focus:outline-none focus:ring-2 focus:ring-blue-200"
                onKeyDown={(e) => e.key === 'Enter' && saveDomain()}
                autoFocus
              />
              <div className="mt-2 flex gap-2">
                <button onClick={() => setEditDomain(false)} className="rounded-lg px-3 py-1.5 text-xs font-bold text-slate-500 hover:bg-white">إلغاء</button>
                <button onClick={saveDomain} disabled={savingDomain || !domainInput.trim()} className="rounded-lg bg-blue-600 px-3 py-1.5 text-xs font-bold text-white hover:bg-blue-700 disabled:opacity-50">
                  {savingDomain ? '...' : 'حفظ'}
                </button>
              </div>
            </div>
          ) : (
            <button onClick={() => { setEditDomain(true); setDomainInput(me?.status?.site_domain || '') }} className="text-xs font-bold text-brand-600 hover:underline -mt-3 mb-2">
              {me?.status?.site_domain ? 'تغيير الرابط' : '🔗 ربط موقع المودل'}
            </button>
          )
        )}
      </div>

      {secret && (
        <div className="mb-5 rounded-xl border border-slate-200 bg-white p-4">
          <div className="flex items-center justify-between gap-3">
            <div>
              <p className="text-xs font-bold text-slate-400">{t('account.api_secret')}</p>
              <p className="mt-1 text-sm font-bold text-slate-800" dir="ltr" style={{ textAlign: 'left' }}>
                {secret}
              </p>
            </div>
            <div className="flex shrink-0 items-center gap-2">
              <button
                onClick={() => navigator.clipboard?.writeText(secret)}
                className="rounded-lg border border-slate-200 px-3 py-1.5 text-xs font-bold text-slate-500 transition-colors hover:bg-slate-50 hover:text-brand-600"
              >
                {t('common.copy')}
              </button>
              <button
                onClick={rotate}
                disabled={busy}
                className="rounded-lg bg-slate-800 px-3 py-1.5 text-xs font-bold text-white transition-colors hover:bg-slate-700 disabled:opacity-60"
              >
                {busy ? '…' : t('account.rotate')}
              </button>
            </div>
          </div>
          <p className="mt-2 text-xs font-semibold text-slate-400">{t('account.api_secret.hint')}</p>
        </div>
      )}

      {!isOwner && user?.authType === 'account' && (
        <div className="rounded-xl border border-dashed border-brand-300 bg-brand-50/60 p-5">
          <div className="flex items-center justify-between gap-3">
            <div>
              <p className="text-sm font-extrabold text-slate-800">{t('account.buy')}</p>
              <p className="mt-0.5 text-xs font-semibold text-slate-500">{t('account.buy.hint')}</p>
            </div>
            <button
              onClick={() => setShowBuy((s) => !s)}
              className="rounded-xl bg-gradient-to-l from-brand-600 to-violet-600 px-4 py-2 text-sm font-extrabold text-white shadow-md shadow-brand-600/20 transition-all hover:shadow-lg active:scale-[.98]"
            >
              {t('account.buy.soon')}
            </button>
          </div>
          {showBuy && (
            <div className="mt-4 rounded-xl bg-white p-4 text-center text-sm font-bold text-slate-500 ring-1 ring-slate-200">
              {t('account.buy.hint')}
            </div>
          )}
        </div>
      )}

      <div className="mt-6 text-center">
        <Link
          to="/admin"
          className="inline-flex items-center gap-2 rounded-xl bg-slate-900 px-5 py-2.5 text-sm font-extrabold text-white transition-colors hover:bg-slate-800"
        >
          {t('account.gotodash')}
        </Link>
      </div>
    </div>
  )
}
