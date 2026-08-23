import { useState } from 'react'
import { Link } from 'react-router-dom'
import { api } from '../api'
import { usePolling } from '../lib/usePolling'
import { RISK } from '../lib/risk'

export default function ThreatAlert() {
  const [alerts, setAlerts] = useState([])
  const [dismissed, setDismissed] = useState(new Set())

  usePolling(() => {
    api.get('/api/dashboard/top-risky').then((data) => {
      if (!Array.isArray(data)) return
      const highRisk = data.filter((s) => s.risk_level === 'critical' || s.risk_level === 'high')
      setAlerts(highRisk)
    }).catch(() => {})
  }, 10000, [])

  const visible = alerts.filter((a) => !dismissed.has(a.session_id))

  if (visible.length === 0) return null

  return (
    <div className="space-y-2">
      {visible.map((a) => {
        const meta = RISK[a.risk_level] || RISK.safe
        const isCrit = a.risk_level === 'critical'
        return (
          <div
            key={a.session_id}
            className={`relative flex items-center gap-3 rounded-xl border px-4 py-3 transition-all ${
              isCrit
                ? 'border-red-200 bg-gradient-to-l from-red-50 to-orange-50/60 shadow-lg shadow-red-500/10'
                : 'border-orange-200 bg-gradient-to-l from-orange-50 to-amber-50/60 shadow-lg shadow-orange-500/10'
            }`}
          >
            <div className={`flex h-9 w-9 shrink-0 items-center justify-center rounded-lg ${meta.solid}`}>
              <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2.5" strokeLinecap="round">
                <path d="M12 9v4m0 4h.01M10.3 3.9 1.8 18a2 2 0 0 0 1.7 3h17a2 2 0 0 0 1.7-3L13.7 3.9a2 2 0 0 0-3.4 0Z" />
              </svg>
            </div>
            <div className="min-w-0 flex-1">
              <div className="flex items-center gap-2">
                <span className={`rounded-full px-2 py-0.5 text-[11px] font-extrabold ${meta.solid}`}>
                  {isCrit ? 'تنبيه عالي' : 'تنبيه مرتفع'}
                </span>
              </div>
              <p className="mt-0.5 text-sm font-bold text-slate-800">
                <Link to={'/admin/students/' + a.student_id} className="hover:text-brand-600">
                  {a.fullname}
                </Link>
                <span className="mx-1 text-slate-300">—</span>
                <Link to={'/admin/exams/' + a.exam_id} className="text-slate-500 hover:text-brand-600">
                  {a.exam_name}
                </Link>
              </p>
              <div className="mt-0.5 flex flex-wrap gap-2 text-[11px] text-slate-500">
                {a.copy_count > 0 && <span>نسخ: {a.copy_count}</span>}
                {a.paste_count > 0 && <span>لصق: {a.paste_count}</span>}
                {a.tab_hidden_count > 0 && <span>إخفاء تبويب: {a.tab_hidden_count}</span>}
                {a.devtools_count > 0 && <span>أدوات مطوّر: {a.devtools_count}</span>}
              </div>
            </div>
            <button
              onClick={() => setDismissed((prev) => new Set([...prev, a.session_id]))}
              className="shrink-0 rounded-lg p-1.5 text-slate-300 transition-colors hover:bg-white/60 hover:text-slate-500"
            >
              <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2.5">
                <path d="M18 6 6 18M6 6l12 12" />
              </svg>
            </button>
          </div>
        )
      })}
    </div>
  )
}
