import { useEffect, useMemo, useState } from 'react'
import { api } from '../api'
import Card from '../components/Card'
import Spinner from '../components/Spinner'
import EmptyState from '../components/EmptyState'
import { fmtNum, fmtTime } from '../lib/format'
import { usePolling } from '../lib/usePolling'

function JsonBlock({ data }) {
  const [open, setOpen] = useState(false)
  const json = useMemo(() => JSON.stringify(data, null, 2), [data])
  const preview = useMemo(() => JSON.stringify(data), [data])

  return (
    <div className="rounded-xl border border-slate-200 bg-slate-900">
      <button
        onClick={() => setOpen(!open)}
        className="flex w-full items-center justify-between gap-3 px-4 py-2.5 text-left"
      >
        <span className="flex items-center gap-2 text-[11px] font-bold text-slate-400">
          <svg
            width="12"
            height="12"
            viewBox="0 0 24 24"
            fill="none"
            stroke="currentColor"
            strokeWidth="2.5"
            className={`transition-transform ${open ? 'rotate-90' : ''}`}
          >
            <path d="m9 18 6-6-6-6" />
          </svg>
          <span className="text-emerald-400">{open ? 'إخفاء الـ JSON' : 'عرض الـ JSON'}</span>
          <span className="hidden font-normal text-slate-500 sm:inline">· {preview.slice(0, 80)}{preview.length > 80 ? '…' : ''}</span>
        </span>
        <span className="text-[10px] tabular-nums font-bold text-slate-500">{json.length.toLocaleString()} بايت</span>
      </button>
      {open && (
        <pre className="max-h-96 overflow-auto border-t border-slate-800 px-4 py-3 text-[11px] leading-relaxed text-slate-200" dir="ltr">
          {json}
        </pre>
      )}
    </div>
  )
}

export default function RawEvents() {
  const [events, setEvents] = useState(null)
  const [total, setTotal] = useState(0)
  const [types, setTypes] = useState([])
  const [stats, setStats] = useState(null)
  const [type, setType] = useState('')
  const [q, setQ] = useState('')
  const [debouncedQ, setDebouncedQ] = useState('')
  const [limit, setLimit] = useState(20)
  const [err, setErr] = useState('')

  const REFRESH_MS = 10000

  useEffect(() => {
    const t = setTimeout(() => setDebouncedQ(q), 350)
    return () => clearTimeout(t)
  }, [q])

  useEffect(() => {
    api.get('/api/raw/types').then(setTypes).catch(() => {})
    api.get('/api/raw/stats').then(setStats).catch(() => {})
  }, [])

  usePolling(() => {
    const params = new URLSearchParams()
    params.set('limit', limit)
    if (type) params.set('type', type)
    if (debouncedQ.trim()) params.set('q', debouncedQ.trim())
    return api
      .get(`/api/raw/events?${params.toString()}`)
      .then((d) => {
        setEvents(d.events)
        setTotal(d.total)
        setErr('')
      })
      .catch((e) => setErr(e.message))
  }, REFRESH_MS, [type, debouncedQ, limit])

  const refresh = () => {
    const params = new URLSearchParams()
    params.set('limit', limit)
    if (type) params.set('type', type)
    if (debouncedQ.trim()) params.set('q', debouncedQ.trim())
    return api
      .get(`/api/raw/events?${params.toString()}`)
      .then((d) => {
        setEvents(d.events)
        setTotal(d.total)
        setErr('')
      })
      .catch((e) => setErr(e.message))
  }

  return (
    <div className="space-y-6">
      <header className="flex flex-wrap items-center justify-between gap-3 animate-fade-up">
        <div>
          <h1 className="text-2xl font-extrabold text-slate-800">البيانات الخام</h1>
          <p className="mt-1 text-sm text-slate-500">
            كل JSON يصل من الإضافة — للتحقق من سلامة وصول البيانات
          </p>
        </div>
        <div className="flex flex-wrap items-center gap-3">
          <button
            onClick={refresh}
            className="flex cursor-pointer items-center gap-2 rounded-xl bg-brand-600 px-4 py-2 text-xs font-bold text-white shadow-lg shadow-brand-600/25 transition-colors hover:bg-brand-700"
          >
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2.2" strokeLinecap="round">
              <path d="M21 12a9 9 0 1 1-2.6-6.4M21 3v6h-6" />
            </svg>
            تحديث الآن
          </button>
          <span className="rounded-full bg-slate-100 px-3 py-1.5 text-[11px] font-bold text-slate-500 ring-1 ring-slate-200">
            تحديث تلقائي كل ١٠ ثوانٍ
          </span>
        </div>
      </header>

      <div className="grid grid-cols-2 gap-3 sm:grid-cols-4 animate-fade-up" style={{ animationDelay: '40ms' }}>
        {[
          { label: 'إجمالي الأحداث', value: stats?.total_events, cls: 'text-brand-600' },
          { label: 'آخر معرف حدث', value: stats?.latest_id, cls: 'text-violet-600' },
          { label: 'عدد الأحداث المعروضة', value: total, cls: 'text-cyan-600' },
          { label: 'آخر استقبال', value: stats?.last_event_at, cls: 'text-slate-600', small: true },
        ].map((s) => (
          <div key={s.label} className="rounded-xl bg-white px-4 py-3.5 ring-1 ring-slate-200/70 shadow-sm">
            <p className="text-[11px] font-semibold text-slate-400">{s.label}</p>
            <p className={`mt-0.5 text-xl font-extrabold tabular-nums ${s.cls} ${s.small ? 'text-sm' : ''}`}>
              {s.small ? (s.value ? fmtTime(s.value) : '—') : fmtNum(s.value ?? 0)}
            </p>
          </div>
        ))}
      </div>

      <Card className="overflow-hidden animate-fade-up" style={{ animationDelay: '80ms' }}>
        <div className="flex flex-wrap items-center gap-3 px-5 pt-5">
          <div className="relative min-w-[220px] flex-1">
            <input
              value={q}
              onChange={(e) => setQ(e.target.value)}
              placeholder="ابحث في معرف الجلسة أو الحدث أو الـ JSON…"
              className="w-full rounded-xl border border-slate-200 bg-white py-2.5 pl-4 pr-10 text-sm font-semibold outline-none transition-all placeholder:font-normal focus:border-brand-500 focus:ring-4 focus:ring-brand-500/10"
            />
          </div>
          <select
            value={type}
            onChange={(e) => setType(e.target.value)}
            className="rounded-xl border border-slate-200 bg-white px-3 py-2.5 text-xs font-bold text-slate-600 outline-none focus:border-brand-500"
          >
            <option value="">كل الأنواع</option>
            {types.map((t) => (
              <option key={t.type} value={t.type}>
                {t.type} ({fmtNum(t.cnt)})
              </option>
            ))}
          </select>
          <select
            value={limit}
            onChange={(e) => setLimit(Number(e.target.value))}
            className="rounded-xl border border-slate-200 bg-white px-3 py-2.5 text-xs font-bold text-slate-600 outline-none focus:border-brand-500"
          >
            {[10, 20, 50, 100, 200].map((n) => (
              <option key={n} value={n}>
                {n} حدث
              </option>
            ))}
          </select>
        </div>

        <div className="p-5">
          {err ? (
            <EmptyState icon="⚠️" title="تعذر تحميل البيانات" hint={err} />
          ) : events === null ? (
            <Spinner />
          ) : events.length === 0 ? (
            <EmptyState
              icon={
                <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.8" strokeLinecap="round">
                  <path d="M20 6 9 17l-5-5" />
                </svg>
              }
              title="لا توجد أحداث"
              hint="جرّب تغيير الفلاتر أو انتظر وصول أحداث جديدة من الإضافة"
            />
          ) : (
            <div className="space-y-3">
              {events.map((ev) => (
                <div key={ev.id} className="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
                  <div className="mb-3 flex flex-wrap items-center gap-2">
                    <span className="rounded-md bg-brand-50 px-2 py-0.5 text-[11px] font-bold text-brand-700 ring-1 ring-brand-100">
                      {ev.event_type}
                    </span>
                    <span className="text-[11px] tabular-nums text-slate-400">
                      #{ev.id} · <span dir="ltr">{ev.event_id}</span>
                    </span>
                    <span className="mr-auto text-[11px] tabular-nums text-slate-400">{fmtTime(ev.event_time)}</span>
                  </div>
                  <div className="mb-2 grid grid-cols-2 gap-2 text-[11px] sm:grid-cols-3 lg:grid-cols-5">
                    <div className="rounded-lg bg-slate-50 px-2.5 py-1.5">
                      <span className="text-slate-400">الجلسة</span>
                      <p className="truncate font-bold text-slate-600" dir="ltr">{ev.session_id}</p>
                    </div>
                    <div className="rounded-lg bg-slate-50 px-2.5 py-1.5">
                      <span className="text-slate-400">الطالب</span>
                      <p className="font-bold text-slate-600">{ev.moodle_user_id || '—'}</p>
                    </div>
                    <div className="rounded-lg bg-slate-50 px-2.5 py-1.5">
                      <span className="text-slate-400">الامتحان</span>
                      <p className="font-bold text-slate-600">{ev.moodle_quiz_id || '—'}</p>
                    </div>
                    <div className="rounded-lg bg-slate-50 px-2.5 py-1.5">
                      <span className="text-slate-400">الدورة</span>
                      <p className="font-bold text-slate-600">{ev.moodle_course_id || '—'}</p>
                    </div>
                    <div className="rounded-lg bg-slate-50 px-2.5 py-1.5">
                      <span className="text-slate-400">الترتيب</span>
                      <p className="font-bold text-slate-600">{ev.sequence_number}</p>
                    </div>
                  </div>
                  <JsonBlock data={ev.payload} />
                </div>
              ))}
            </div>
          )}
        </div>
      </Card>
    </div>
  )
}
