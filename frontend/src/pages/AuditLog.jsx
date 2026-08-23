import { useCallback, useEffect, useState } from 'react'
import { useAuth } from '../auth'
import { api } from '../api'
import { useI18n } from '../i18n'
import Card from '../components/Card'
import Spinner from '../components/Spinner'
import EmptyState from '../components/EmptyState'
import { fmtTime } from '../lib/format'

function actionMeta(action) {
  if (action.startsWith('auth.staff.login_failed')) {
    return { icon: <path d="M12 9v4m0 4h.01M10.3 3.9 1.8 18a2 2 0 0 0 1.7 3h17a2 2 0 0 0 1.7-3L13.7 3.9a2 2 0 0 0-3.4 0Z" />, cls: 'bg-rose-50 text-rose-600' }
  }
  if (action.startsWith('auth.staff.login')) {
    return { icon: <path d="M15 3h4a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-4M10 17l5-5-5-5M15 12H3" />, cls: 'bg-emerald-50 text-emerald-600' }
  }
  if (action.startsWith('account.secret')) {
    return { icon: <path d="M9 3h6a1 1 0 0 1 1 1v2a1 1 0 0 1-1 1H9a1 1 0 0 1-1-1V4a1 1 0 0 1 1-1Zm-2 6h10l.8 8.2a2 2 0 0 1-2 2.3H8.2a2 2 0 0 1-2-2.3L7 9Zm3 4v5m4-5v5" />, cls: 'bg-violet-50 text-violet-600' }
  }
  return { icon: <path d="M12 8a4 4 0 1 0 0-8 4 4 0 0 0 0 8Zm-7 13a7 7 0 0 1 14 0M5 21h14" />, cls: 'bg-slate-100 text-slate-600' }
}

function DetailsText({ details, t }) {
  if (!details || typeof details !== 'object') return null
  const parts = []
  if (details.fullname) parts.push(details.fullname)
  if (details.username) parts.push(details.username)
  if (details.email) parts.push(details.email)
  if (details.role) parts.push(t(`staff.role.${details.role}`))
  if (details.active === true) parts.push(t('staff.active'))
  if (details.active === false) parts.push(t('staff.inactive'))
  if (details.password_set) parts.push(t('audit.field.password_set'))
  if (typeof details.count === 'number') parts.push(t('audit.field.courses', { n: details.count }))
  if (parts.length === 0) return null
  return <p className="mt-0.5 text-[11px] text-slate-400">{parts.join(' · ')}</p>
}

export default function AuditLog() {
  const { t } = useI18n()
  const { user } = useAuth()
  const canManage = (user?.authType === 'account' && user?.role !== 'owner') || user?.staffRole === 'admin'

  const [rows, setRows] = useState([])
  const [busy, setBusy] = useState(true)
  const [err, setErr] = useState('')

  const reload = useCallback(async () => {
    setBusy(true)
    try {
      const r = await api.get('/api/audit?limit=200')
      setRows(r)
      setErr('')
    } catch (e) {
      setErr(e.message)
    } finally {
      setBusy(false)
    }
  }, [])

  useEffect(() => {
    reload()
  }, [reload])

  if (busy) return <Spinner />
  if (!canManage) return <EmptyState icon="🔒" title={t('staff.noPermission')} hint={t('staff.noPermissionHint')} />
  if (err && !rows.length) return <EmptyState icon="⚠️" title={t('audit.loadFailed')} hint={err} />

  return (
    <div className="space-y-6">
      <header className="flex flex-wrap items-end justify-between gap-3 animate-fade-up">
        <div>
          <h1 className="text-2xl font-extrabold text-slate-800">{t('audit.title')}</h1>
          <p className="mt-1 text-sm text-slate-500">{t('audit.subtitle')}</p>
        </div>
        <button
          onClick={reload}
          disabled={busy}
          className="flex cursor-pointer items-center gap-2 rounded-xl border border-slate-200 bg-white px-4 py-2.5 text-sm font-bold text-slate-600 transition-all hover:border-brand-300 hover:text-brand-700 disabled:opacity-60"
        >
          <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round" className={busy ? 'animate-spin' : ''}>
            <path d="M23 4v6h-6M1 20v-6h6M3.5 9a9 9 0 0 1 14.9-3.4L23 10M1 14l4.6 4.4A9 9 0 0 0 20.5 15" />
          </svg>
          {t('audit.refresh')}
        </button>
      </header>

      {err && (
        <div className="rounded-xl bg-rose-50 px-4 py-3 text-sm font-bold text-rose-700 ring-1 ring-rose-200 animate-fade-up">
          {err}
        </div>
      )}

      <Card className="overflow-hidden animate-fade-up" hover>
        {rows.length === 0 ? (
          <p className="px-6 py-14 text-center text-sm text-slate-400">{t('audit.empty')}</p>
        ) : (
          <ul className="divide-y divide-slate-50">
            {rows.map((r) => {
              const meta = actionMeta(r.action)
              return (
                <li key={r.id} className="flex items-start gap-3.5 px-5 py-4">
                  <span className={`mt-0.5 flex h-9 w-9 shrink-0 items-center justify-center rounded-xl ${meta.cls}`}>
                    <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round">
                      {meta.icon}
                    </svg>
                  </span>
                  <div className="min-w-0 flex-1">
                    <div className="flex flex-wrap items-baseline justify-between gap-x-3 gap-y-1">
                      <p className="text-sm font-bold text-slate-700">{t(`audit.action.${r.action}`)}</p>
                      <p className="text-[11px] font-semibold text-slate-400 tabular-nums" dir="ltr" style={{ textAlign: 'right' }}>
                        {fmtTime(r.created_at)}
                      </p>
                    </div>
                    <p className="mt-0.5 text-xs text-slate-500">
                      <span className="font-bold">{r.actor_name || (r.actor_type === 'staff' ? t('audit.actor.staff') : t('audit.actor.account'))}</span>
                      <span className="ms-1.5 inline-flex rounded-full bg-slate-100 px-2 py-0.5 text-[10px] font-extrabold text-slate-500">
                        {r.actor_type === 'staff' ? t('audit.actor.staff') : t('audit.actor.account')}
                      </span>
                    </p>
                    <DetailsText details={r.details} t={t} />
                  </div>
                </li>
              )
            })}
          </ul>
        )}
      </Card>
    </div>
  )
}
