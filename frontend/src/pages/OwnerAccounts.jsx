import { useEffect, useState } from 'react'
import { api } from '../api'
import { useI18n } from '../i18n'
import { fmtTime } from '../lib/format'

const STATUS = {
  trial: 'bg-emerald-50 text-emerald-700 ring-emerald-200',
  active: 'bg-sky-50 text-sky-700 ring-sky-200',
  expired: 'bg-rose-50 text-rose-700 ring-rose-200',
  suspended: 'bg-slate-100 text-slate-600 ring-slate-200',
}

export default function OwnerAccounts() {
  const { t } = useI18n()
  const [rows, setRows] = useState(null)
  const [error, setError] = useState('')

  useEffect(() => {
    api
      .get('/api/accounts')
      .then((d) => setRows(d.accounts || []))
      .catch((e) => setError(e.message || t('err.generic')))
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [])

  const label = (s) => t(`account.status.${s}`)

  return (
    <div className="mx-auto max-w-4xl">
      <div className="mb-6">
        <h1 className="text-2xl font-extrabold text-slate-800">{t('owner.title')}</h1>
      </div>

      {error && (
        <div className="mb-5 rounded-xl border border-rose-200 bg-rose-50 px-4 py-3 text-sm font-bold text-rose-700">
          {error}
        </div>
      )}

      {rows === null ? (
        <p className="text-sm font-semibold text-slate-400">{t('common.loading')}</p>
      ) : rows.length === 0 ? (
        <div className="rounded-2xl border border-dashed border-slate-300 bg-white p-10 text-center text-sm font-bold text-slate-400">
          {t('owner.empty')}
        </div>
      ) : (
        <div className="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
          <div className="overflow-x-auto">
            <table className="w-full text-sm">
              <thead>
                <tr className="border-b border-slate-200 bg-slate-50/70 text-right text-xs font-bold text-slate-400">
                  <th className="px-4 py-3">{t('owner.org')}</th>
                  <th className="px-4 py-3">{t('owner.email')}</th>
                  <th className="px-4 py-3">{t('owner.status')}</th>
                  <th className="px-4 py-3">{t('owner.days')}</th>
                  <th className="px-4 py-3">{t('owner.created')}</th>
                  <th className="px-4 py-3">{t('owner.lastlogin')}</th>
                </tr>
              </thead>
              <tbody>
                {rows.map((r) => (
                  <tr key={r.id} className="border-b border-slate-100 last:border-0 hover:bg-slate-50/50">
                    <td className="px-4 py-3 font-bold text-slate-700">{r.org_name || '—'}</td>
                    <td className="px-4 py-3 text-slate-500" dir="ltr" style={{ textAlign: 'right' }}>
                      {r.email}
                    </td>
                    <td className="px-4 py-3">
                      <span className={`inline-flex rounded-full px-2.5 py-0.5 text-xs font-bold ring-1 ${STATUS[r.status] || STATUS.trial}`}>
                        {label(r.status)}
                      </span>
                    </td>
                    <td className="px-4 py-3 font-bold text-slate-600">
                      {r.status === 'trial' ? r.remaining_days : '—'}
                    </td>
                    <td className="px-4 py-3 text-xs text-slate-400">{r.created_at ? fmtTime(r.created_at) : '—'}</td>
                    <td className="px-4 py-3 text-xs text-slate-400">{r.last_login_at ? fmtTime(r.last_login_at) : '—'}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        </div>
      )}
    </div>
  )
}
