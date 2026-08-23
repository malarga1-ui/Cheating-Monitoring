import { useEffect, useState } from 'react'
import { Link, useNavigate } from 'react-router-dom'
import { api } from '../api'
import Card from '../components/Card'
import Spinner from '../components/Spinner'
import EmptyState from '../components/EmptyState'
import { fmtNum, fmtTime } from '../lib/format'
import { usePolling } from '../lib/usePolling'

export default function Exams() {
  const navigate = useNavigate()
  const [exams, setExams] = useState([])
  const [q, setQ] = useState('')
  const [debouncedQ, setDebouncedQ] = useState('')
  const [status, setStatus] = useState('')
  const [busy, setBusy] = useState(true)
  const [err, setErr] = useState('')

  const REFRESH_MS = 20000

  useEffect(() => {
    const t = setTimeout(() => setDebouncedQ(q), 350)
    return () => clearTimeout(t)
  }, [q])

  useEffect(() => {
    setBusy(true)
    setErr('')
  }, [debouncedQ, status])

  usePolling(() => {
    const params = new URLSearchParams()
    if (debouncedQ.trim()) params.set('q', debouncedQ.trim())
    if (status) params.set('status', status)
    return api
      .get(`/api/exams?${params.toString()}`)
      .then((d) => {
        setExams(d)
        setErr('')
      })
      .catch((e) => setErr(e.message))
      .finally(() => setBusy(false))
  }, REFRESH_MS, [debouncedQ, status])

  return (
    <div className="space-y-6">
      <header className="animate-fade-up">
        <h1 className="text-2xl font-extrabold text-slate-800">الامتحانات</h1>
        <p className="mt-1 text-sm text-slate-500">جميع الامتحانات المراقبة وملخص نشاط كل امتحان</p>
      </header>

      <div className="flex flex-wrap items-center gap-3 animate-fade-up" style={{ animationDelay: '80ms' }}>
        <div className="relative min-w-[220px] flex-1">
          <svg
            className="pointer-events-none absolute right-3.5 top-1/2 -translate-y-1/2 text-slate-400"
            width="16"
            height="16"
            viewBox="0 0 24 24"
            fill="none"
            stroke="currentColor"
            strokeWidth="2"
            strokeLinecap="round"
          >
            <circle cx="11" cy="11" r="7" />
            <path d="m20 20-3.2-3.2" />
          </svg>
          <input
            value={q}
            onChange={(e) => setQ(e.target.value)}
            placeholder="ابحث باسم الامتحان أو رقمه…"
            className="w-full rounded-xl border border-slate-200 bg-white py-2.5 pl-4 pr-10 text-sm font-semibold text-slate-800 outline-none transition-all placeholder:font-normal placeholder:text-slate-400 focus:border-brand-500 focus:ring-4 focus:ring-brand-500/10"
          />
        </div>
        <div className="flex rounded-xl bg-white p-1 ring-1 ring-slate-200">
          {[
            { key: '', label: 'الكل' },
            { key: 'active', label: 'نشط' },
            { key: 'ended', label: 'منتهي' },
          ].map((f) => (
            <button
              key={f.key}
              onClick={() => setStatus(f.key)}
              className={`rounded-lg px-4 py-1.5 text-xs font-bold transition-all ${
                status === f.key ? 'bg-brand-600 text-white shadow-sm' : 'text-slate-500 hover:text-slate-700'
              }`}
            >
              {f.label}
            </button>
          ))}
        </div>
      </div>

      <Card className="overflow-hidden animate-fade-up" style={{ animationDelay: '120ms' }} hover>
        {err ? (
          <EmptyState icon="⚠️" title="تعذر تحميل البيانات" hint={err} />
        ) : busy ? (
          <Spinner />
        ) : exams.length === 0 ? (
          <EmptyState
            icon={
              <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.8" strokeLinecap="round">
                <path d="M9 4h9a2 2 0 0 1 2 2v12a2 2 0 0 1-2 2H9m0-16H6a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h3m0-16v16" />
              </svg>
            }
            title="لا توجد امتحانات"
            hint="جرّب تغيير البحث أو الفلاتر"
          />
        ) : (
          <div className="overflow-x-auto">
            <table className="w-full min-w-[760px] text-sm">
              <thead>
                <tr className="border-b border-slate-100 bg-slate-50/60 text-right text-[11px] font-bold text-slate-400">
                  <th className="px-5 py-3">الامتحان</th>
                  <th className="px-5 py-3">الدورة</th>
                  <th className="px-5 py-3">المدرّس</th>
                  <th className="px-5 py-3">الحالة</th>
                  <th className="px-5 py-3">الطلاب</th>
                  <th className="px-5 py-3">الجلسات</th>
                  <th className="px-5 py-3">الأحداث</th>
                  <th className="px-5 py-3">مشبوهون</th>
                  <th className="px-5 py-3">آخر نشاط</th>
                </tr>
              </thead>
              <tbody>
                {exams.map((e, i) => (                  <tr
                    key={e.id}
                    className="group cursor-pointer border-b border-slate-50 transition-colors last:border-0 hover:bg-brand-50/40"
                    onClick={() => navigate(`/admin/exams/${e.id}`)}
                    style={{ animationDelay: `${i * 30}ms` }}
                  >
                    <td className="px-5 py-3.5">
                      <p className="font-bold text-slate-700 group-hover:text-brand-600">{e.name}</p>
                      <p className="text-[11px] tabular-nums text-slate-400">رقم الامتحان: {e.moodle_quiz_id}</p>
                    </td>
                    <td className="px-5 py-3.5">
                      {e.course_id ? (
                        <Link
                           to={`/admin/courses/${e.course_id}`}
                          className="font-semibold text-slate-500 transition-colors hover:text-brand-600"
                          onClick={(ev) => ev.stopPropagation()}
                        >
                          {e.course_name}
                        </Link>
                      ) : (
                        <span className="text-xs text-slate-300">—</span>
                      )}
                    </td>
                    <td className="px-5 py-3.5">
                      {e.teacher_name ? (
                        <span className="font-semibold text-slate-600">{e.teacher_name}</span>
                      ) : (
                        <span className="text-xs text-slate-300">—</span>
                      )}
                    </td>
                    <td className="px-5 py-3.5">
                      <span
                        className={`inline-flex items-center gap-1.5 rounded-full px-2.5 py-0.5 text-xs font-bold ${
                          e.status === 'active'
                            ? 'bg-emerald-50 text-emerald-700 ring-1 ring-emerald-200'
                            : 'bg-slate-100 text-slate-500 ring-1 ring-slate-200'
                        }`}
                      >
                        <span className={`h-1.5 w-1.5 rounded-full ${e.status === 'active' ? 'bg-emerald-500' : 'bg-slate-400'}`} />
                        {e.status === 'active' ? 'نشط' : 'منتهي'}
                      </span>
                    </td>
                    <td className="px-5 py-3.5 tabular-nums text-slate-600">{fmtNum(e.students_count)}</td>
                    <td className="px-5 py-3.5 tabular-nums text-slate-600">{fmtNum(e.sessions_count)}</td>
                    <td className="px-5 py-3.5 tabular-nums text-slate-600">{fmtNum(e.events_count)}</td>
                    <td className="px-5 py-3.5">
                      {e.suspicious_count > 0 ? (
                        <span className="inline-flex items-center gap-1 rounded-full bg-rose-50 px-2.5 py-0.5 text-xs font-bold text-rose-600 ring-1 ring-rose-200">
                          {fmtNum(e.suspicious_count)}
                        </span>
                      ) : (
                        <span className="text-xs text-slate-300">0</span>
                      )}
                    </td>
                    <td className="px-5 py-3.5 text-xs tabular-nums text-slate-500">{fmtTime(e.last_event_at)}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
      </Card>
    </div>
  )
}
