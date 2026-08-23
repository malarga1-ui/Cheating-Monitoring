import { useState, useEffect, useCallback, useRef } from 'react'
import { useNavigate } from 'react-router-dom'
import { api } from '../api'
import { usePolling } from '../lib/usePolling'

function Toast({ notification, onDismiss }) {
  const [exiting, setExiting] = useState(false)

  useEffect(() => {
    const t1 = setTimeout(() => setExiting(true), 4500)
    const t2 = setTimeout(() => onDismiss(), 5000)
    return () => { clearTimeout(t1); clearTimeout(t2) }
  }, [onDismiss])

  const isCrit = notification.risk_level === 'critical'
  return (
    <div
      className={`flex items-start gap-3 rounded-2xl border px-4 py-3 shadow-2xl transition-all duration-300 ${
        exiting ? 'translate-x-full opacity-0' : 'translate-x-0 opacity-100'
      } ${
        isCrit
          ? 'border-red-200 bg-gradient-to-l from-red-50 to-white shadow-red-500/15'
          : 'border-orange-200 bg-gradient-to-l from-orange-50 to-white shadow-orange-500/15'
      }`}
      dir="rtl"
    >
      <div className={`mt-0.5 flex h-8 w-8 shrink-0 items-center justify-center rounded-lg ${
        isCrit ? 'bg-red-500 text-white' : 'bg-orange-500 text-white'
      }`}>
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2.5" strokeLinecap="round">
          <path d="M12 9v4m0 4h.01M10.3 3.9 1.8 18a2 2 0 0 0 1.7 3h17a2 2 0 0 0 1.7-3L13.7 3.9a2 2 0 0 0-3.4 0Z" />
        </svg>
      </div>
      <div className="min-w-0 flex-1">
        <p className="text-[11px] font-extrabold text-slate-400">{notification.exam_name}</p>
        <p className="mt-0.5 text-sm font-bold text-slate-800">{notification.fullname}</p>
        <div className="mt-1 flex flex-wrap gap-1.5">
          {notification.copy_count > 0 && (
            <span className="rounded-md bg-blue-50 px-2 py-0.5 text-[10px] font-bold text-blue-600">نسخ: {notification.copy_count}</span>
          )}
          {notification.paste_count > 0 && (
            <span className="rounded-md bg-amber-50 px-2 py-0.5 text-[10px] font-bold text-amber-600">لصق: {notification.paste_count}</span>
          )}
          {notification.tab_hidden_count > 0 && (
            <span className="rounded-md bg-rose-50 px-2 py-0.5 text-[10px] font-bold text-rose-600">إخفاء: {notification.tab_hidden_count}</span>
          )}
          {notification.devtools_count > 0 && (
            <span className="rounded-md bg-violet-50 px-2 py-0.5 text-[10px] font-bold text-violet-600">أدوات: {notification.devtools_count}</span>
          )}
          {notification.screenshot_count > 0 && (
            <span className="rounded-md bg-pink-50 px-2 py-0.5 text-[10px] font-bold text-pink-600">شاشة: {notification.screenshot_count}</span>
          )}
          {notification.ai_score >= 50 && (
            <span className="rounded-md bg-cyan-50 px-2 py-0.5 text-[10px] font-bold text-cyan-600">AI: {notification.ai_score}%</span>
          )}
        </div>
      </div>
      <div className="flex flex-col items-center gap-1">
        <button
          onClick={(e) => { e.stopPropagation(); onDismiss() }}
          className="rounded-lg p-1 text-slate-300 transition-colors hover:bg-white/80 hover:text-slate-500"
        >
          <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="3">
            <path d="M18 6 6 18M6 6l12 12" />
          </svg>
        </button>
        <span className="text-[10px] font-bold tabular-nums text-slate-400">{notification.risk_score}%</span>
      </div>
    </div>
  )
}

export default function TeacherNotifications() {
  const navigate = useNavigate()
  const [alerts, setAlerts] = useState([])
  const [toasts, setToasts] = useState([])
  const [open, setOpen] = useState(false)
  const prevIdsRef = useRef(new Set())
  const panelRef = useRef(null)

  const fetchAlerts = useCallback(async () => {
    try {
      const exams = await api.get('/api/teacher/exams')
      if (!Array.isArray(exams) || exams.length === 0) return

      const newAlerts = []
      const examFetches = exams.map(async (exam) => {
        if (!exam.suspicious_count || exam.suspicious_count <= 0) return
        try {
          const res = await api.get(`/api/teacher/exams/${exam.id}/students`)
          const students = res?.students || []
          return students
            .filter((s) => s.risk_level === 'high' || s.risk_level === 'critical')
            .slice(0, 3)
            .map((s) => ({
              student_id: s.student_id,
              fullname: s.fullname || 'طالب',
              exam_id: exam.id,
              exam_name: exam.name || 'امتحان',
              risk_level: s.risk_level,
              risk_score: s.risk_score || 0,
              copy_count: s.copy_count || 0,
              paste_count: s.paste_count || 0,
              tab_hidden_count: s.tab_hidden_count || 0,
              devtools_count: s.devtools_count || 0,
              screenshot_count: s.screenshot_count || 0,
              ai_score: s.ai_suspect_score || 0,
              sim_score: s.similarity_max_score || 0,
              event_count: s.event_count || 0,
            }))
        } catch {
          return []
        }
      })

      const results = await Promise.all(examFetches)
      results.forEach((r) => { if (r) newAlerts.push(...r) })

      newAlerts.sort((a, b) => b.risk_score - a.risk_score)
      setAlerts(newAlerts.slice(0, 20))

      if (prevIdsRef.current.size > 0) {
        const newOnes = newAlerts.filter(
          (a) => !prevIdsRef.current.has(a.student_id + ':' + a.exam_id)
        )
        if (newOnes.length > 0) {
          setToasts((prev) => [...newOnes.slice(0, 3), ...prev].slice(0, 5))
        }
      }
      prevIdsRef.current = new Set(newAlerts.map((a) => a.student_id + ':' + a.exam_id))
    } catch {
      /* silent */
    }
  }, [])

  usePolling(fetchAlerts, 15000, [])

  useEffect(() => {
    const handleClick = (e) => {
      if (panelRef.current && !panelRef.current.contains(e.target)) setOpen(false)
    }
    document.addEventListener('mousedown', handleClick)
    return () => document.removeEventListener('mousedown', handleClick)
  }, [])

  const dismissToast = useCallback((id) => {
    setToasts((prev) => prev.filter((t) => !(t.student_id === id.student_id && t.exam_id === id.exam_id)))
  }, [])

  const dismissAlert = useCallback((a) => {
    setAlerts((prev) => prev.filter((x) => !(x.student_id === a.student_id && x.exam_id === a.exam_id)))
  }, [])

  const goToExam = (examId) => {
    setOpen(false)
    navigate(`/teacher/portal/exams/${examId}`)
  }

  const critCount = alerts.filter((a) => a.risk_level === 'critical').length
  const badgeCount = alerts.length

  return (
    <>
      <div className="relative" ref={panelRef}>
        <button
          onClick={() => setOpen(!open)}
          className="relative flex h-9 w-9 items-center justify-center rounded-xl text-slate-400 transition-all hover:bg-slate-100 hover:text-slate-600"
        >
          <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round">
            <path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9" />
            <path d="M13.73 21a2 2 0 0 1-3.46 0" />
          </svg>
          {badgeCount > 0 && (
            <span className={`absolute -left-0.5 -top-0.5 flex h-4.5 min-w-[18px] items-center justify-center rounded-full px-1 text-[10px] font-extrabold text-white ${
              critCount > 0 ? 'bg-red-500 animate-pulse' : 'bg-orange-500'
            }`}>
              {badgeCount > 99 ? '99+' : badgeCount}
            </span>
          )}
        </button>

        {open && (
          <div className="absolute left-0 top-full z-50 mt-2 w-[380px] max-h-[70vh] overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-2xl" dir="rtl">
            <div className="flex items-center justify-between border-b border-slate-100 px-4 py-3">
              <div className="flex items-center gap-2">
                <h3 className="text-sm font-extrabold text-slate-800">التنبيهات</h3>
                {badgeCount > 0 && (
                  <span className="rounded-full bg-red-100 px-2 py-0.5 text-[10px] font-extrabold text-red-600">{badgeCount}</span>
                )}
              </div>
              {alerts.length > 0 && (
                <button
                  onClick={() => { setAlerts([]); prevIdsRef.current = new Set() }}
                  className="text-[11px] font-bold text-slate-400 hover:text-slate-600"
                >
                  مسح الكل
                </button>
              )}
            </div>

            <div className="overflow-y-auto" style={{ maxHeight: 'calc(70vh - 50px)' }}>
              {alerts.length === 0 ? (
                <div className="px-4 py-10 text-center">
                  <div className="text-3xl mb-2">🛡️</div>
                  <p className="text-sm font-bold text-slate-500">لا توجد تنبيهات</p>
                  <p className="mt-1 text-xs text-slate-400">كل شيء يبدو طبيعياً</p>
                </div>
              ) : (
                <div className="divide-y divide-slate-50">
                  {alerts.map((a) => (
                    <button
                      key={a.student_id + ':' + a.exam_id}
                      onClick={() => goToExam(a.exam_id)}
                      className="flex w-full items-start gap-3 px-4 py-3 text-right transition-colors hover:bg-slate-50"
                    >
                      <div className={`mt-0.5 flex h-8 w-8 shrink-0 items-center justify-center rounded-lg ${
                        a.risk_level === 'critical' ? 'bg-red-500 text-white' : 'bg-orange-500 text-white'
                      }`}>
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2.5" strokeLinecap="round">
                          <path d="M12 9v4m0 4h.01M10.3 3.9 1.8 18a2 2 0 0 0 1.7 3h17a2 2 0 0 0 1.7-3L13.7 3.9a2 2 0 0 0-3.4 0Z" />
                        </svg>
                      </div>
                      <div className="min-w-0 flex-1">
                        <p className="text-[10px] font-bold text-slate-400">{a.exam_name}</p>
                        <p className="mt-0.5 text-sm font-bold text-slate-800">{a.fullname}</p>
                        <div className="mt-1 flex flex-wrap gap-1">
                          {a.copy_count > 0 && <span className="text-[10px] text-blue-600 font-bold">نسخ:{a.copy_count}</span>}
                          {a.paste_count > 0 && <span className="text-[10px] text-amber-600 font-bold">لصق:{a.paste_count}</span>}
                          {a.tab_hidden_count > 0 && <span className="text-[10px] text-rose-600 font-bold">إخفاء:{a.tab_hidden_count}</span>}
                          {a.devtools_count > 0 && <span className="text-[10px] text-violet-600 font-bold">أدوات:{a.devtools_count}</span>}
                          {a.ai_score >= 50 && <span className="text-[10px] text-cyan-600 font-bold">AI:{a.ai_score}%</span>}
                        </div>
                      </div>
                      <div className="flex flex-col items-end gap-1">
                        <span className={`rounded-full px-2 py-0.5 text-[10px] font-extrabold ${
                          a.risk_level === 'critical' ? 'bg-red-100 text-red-700' : 'bg-orange-100 text-orange-700'
                        }`}>
                          {a.risk_level === 'critical' ? 'حرج' : 'مرتفع'}
                        </span>
                        <span className="text-[10px] font-bold tabular-nums text-slate-400">{a.risk_score}%</span>
                      </div>
                    </button>
                  ))}
                </div>
              )}
            </div>
          </div>
        )}
      </div>

      <div className="fixed bottom-6 left-6 z-50 flex flex-col gap-2" dir="rtl" style={{ maxWidth: 380 }}>
        {toasts.map((t, i) => (
          <Toast
            key={`${t.student_id}-${t.exam_id}-${i}`}
            notification={t}
            onDismiss={() => dismissToast(t)}
          />
        ))}
      </div>
    </>
  )
}
