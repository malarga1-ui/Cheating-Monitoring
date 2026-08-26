import { useEffect, useState } from 'react'
import { Link, useParams } from 'react-router-dom'
import { api } from '../api'
import StatCard from '../components/StatCard'
import Spinner from '../components/Spinner'
import AreaChart from '../components/AreaChart'
import RiskBadge from '../components/RiskBadge'
import { Reveal } from '../components/motion'
import { eventLabel } from '../lib/risk'
import { fmtNum, fmtDuration } from '../lib/format'

const RISK_COLORS = {
  safe:     { bg: 'bg-emerald-50', text: 'text-emerald-700', bar: 'bg-emerald-500' },
  low:      { bg: 'bg-sky-50',     text: 'text-sky-700',     bar: 'bg-sky-500' },
  medium:   { bg: 'bg-amber-50',   text: 'text-amber-700',   bar: 'bg-amber-500' },
  high:     { bg: 'bg-orange-50',  text: 'text-orange-700',  bar: 'bg-orange-500' },
  critical: { bg: 'bg-rose-50',    text: 'text-rose-700',    bar: 'bg-rose-500' },
}

const THREAT_ICONS = {
  copy: '📋', paste: '📥', tab_hidden: '👁️‍🗨️', tab_visible: '👁️',
  devtools_opened: '🛠️', keydown: '⌨️', screenshot_attempt: '📷',
  tab_switch: '🔄', idle_detected: '⏸️', fullscreen_exit: '🖥️',
  paste_from_menu: '📥', right_click: '🖱️', page_leave: '🚪',
  window_blur: '🔲', suspicious_key: '⚠️', rapid_answer_changes: '⚡',
  heartbeat: '💓', activity_summary: '📊', network_offline: '📡',
}

const CATEGORY_META = [
  { key: 'risk',       label: 'درجة الخطورة الإجمالية', icon: '🛡️', color: 'from-rose-500 to-rose-600', bg: 'bg-rose-50' },
  { key: 'network',    label: 'الشبكة وال瀏 IPs',       icon: '🌐', color: 'from-violet-500 to-violet-600', bg: 'bg-violet-50' },
  { key: 'ai',         label: 'الذكاء الاصطناعي',        icon: '🤖', color: 'from-cyan-500 to-cyan-600', bg: 'bg-cyan-50' },
  { key: 'similarity', label: 'التشابه',                 icon: '🔗', color: 'from-amber-500 to-amber-600', bg: 'bg-amber-50' },
]

function RiskBar({ level, count, max }) {
  const c = RISK_COLORS[level] || RISK_COLORS.safe
  const pct = max > 0 ? Math.round((count / max) * 100) : 0
  return (
    <div className="flex items-center gap-3">
      <span className={`w-20 text-xs font-bold ${c.text}`}>{level}</span>
      <div className="flex-1 h-3.5 rounded-full bg-slate-100 overflow-hidden">
        <div className={`h-full rounded-full ${c.bar} transition-all duration-700`} style={{ width: `${pct}%` }} />
      </div>
      <span className="w-10 text-center text-xs font-bold tabular-nums text-slate-600">{count}</span>
    </div>
  )
}

function GaugeCircle({ value, max = 100, color, label }) {
  const pct = Math.min(100, Math.round((value / max) * 100))
  const r = 42
  const circ = 2 * Math.PI * r
  const offset = circ - (circ * pct) / 100
  return (
    <div className="flex flex-col items-center gap-2">
      <div className="relative">
        <svg width="100" height="100" viewBox="0 0 100 100">
          <circle cx="50" cy="50" r={r} fill="none" stroke="#f1f5f9" strokeWidth="8" />
          <circle cx="50" cy="50" r={r} fill="none" stroke={color} strokeWidth="8"
            strokeLinecap="round" strokeDasharray={circ} strokeDashoffset={offset}
            transform="rotate(-90 50 50)" className="transition-all duration-1000" />
        </svg>
        <span className="absolute inset-0 flex items-center justify-center text-lg font-extrabold text-slate-800">
          {value}
        </span>
      </div>
      <span className="text-[11px] font-bold text-slate-500 text-center leading-tight">{label}</span>
    </div>
  )
}

export default function TeacherAnalytics({ courseId: propCourseId, examId: propExamId }) {
  const params = useParams()
  const examId = propExamId || params.id || params.examId
  const courseId = propCourseId || params.courseId
  const [data, setData] = useState(null)
  const [err, setErr] = useState('')

  useEffect(() => {
    let url = '/api/teacher/analytics'
    if (examId) {
      url += `?exam_id=${examId}`
    } else if (courseId) {
      url += `?course_id=${courseId}`
    }
    function load() {
      api.get(url)
        .then(setData)
        .catch((e) => { if (!data) setErr(e.message || 'تعذر تحميل البيانات') })
    }
    load()
    const timer = setInterval(load, 4000)
    return () => clearInterval(timer)
  }, [examId, courseId])

  if (err && !data) return <div className="rounded-2xl bg-rose-50 p-6 text-center"><p className="text-sm font-bold text-rose-600">{err}</p></div>
  if (!data) return <Spinner />

  const t = data.totals || {}
  const riskDist = data.risk_distribution || []
  const eventTypes = data.event_types || []
  const eventsOverTime = data.events_over_time || []
  const catAvg = data.category_averages || {}
  const flags = data.flags || {}
  const topRisky = data.top_risky || []
  const maxRiskCount = Math.max(...riskDist.map(r => r.count), 1)
  const maxThreat = Math.max(...eventTypes.map(t2 => t2.count), 1)

  return (
    <div className="space-y-8">
      {/* Header */}
      <Reveal>
        <header className="flex flex-wrap items-center justify-between gap-3">
          <div>
            <div className="flex items-center gap-3">
              <h1 className="text-2xl font-extrabold text-slate-800">التحليلات والتقارير المتقدمة</h1>
              <span className="inline-flex items-center gap-1.5 rounded-full bg-emerald-50 px-2.5 py-0.5 text-[11px] font-bold text-emerald-700 ring-1 ring-emerald-200">
                <span className="relative flex h-2 w-2">
                  <span className="absolute inline-flex h-full w-full animate-ping rounded-full bg-emerald-400 opacity-75"/>
                  <span className="relative inline-flex h-2 w-2 rounded-full bg-emerald-500"/>
                </span>
                بث مباشر لحظي
              </span>
            </div>
            <p className="mt-1 text-sm text-slate-500">
              نظرة شاملة على مؤشرات الغش والنزاهة الأكاديمية عبر جميع امتحاناتك ومادتك التعليمية
            </p>
          </div>
        </header>
      </Reveal>

      {/* Summary Stats */}
      <div className="grid grid-cols-2 gap-4 lg:grid-cols-5">
        <StatCard title="الامتحانات" value={t.total_exams || 0} accent="violet"
          icon={<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8Z"/><path d="M14 2v6h6"/></svg>}
          delay={0} />
        <StatCard title="الطلاب" value={t.students || 0} accent="cyan"
          icon={<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round"><circle cx="9" cy="8" r="3.5"/><path d="M2.5 20c.8-3 3.4-4.5 6.5-4.5s5.7 1.5 6.5 4.5"/></svg>}
          delay={60} />
        <StatCard title="التهديدات" value={t.events || 0} accent="brand"
          icon={<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round"><path d="M22 12h-4l-3 9L9 3l-3 9H2"/></svg>}
          delay={120} />
        <StatCard title="الجلسات" value={t.sessions || 0} accent="emerald"
          icon={<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>}
          delay={180} />
        <StatCard title="مشبوهون" value={t.suspicious || 0} accent="rose"
          icon={<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/><path d="m9 12 2 2 4-4"/></svg>}
          delay={240} />
      </div>

      {/* Category Averages + Flags */}
      <Reveal delay={100}>
        <div className="rounded-2xl border border-slate-200/70 bg-white p-6">
          <h2 className="mb-2 text-base font-extrabold text-slate-800">متوسط درجات المخاطر</h2>
          <p className="mb-5 text-xs text-slate-400">المتوسط الحسابي لكل مُحدِّد غش عبر كل جلسات امتحاناتك</p>
          <div className="flex flex-wrap items-center justify-around gap-6">
            {CATEGORY_META.map((c) => (
              <GaugeCircle key={c.key} value={catAvg[c.key] || 0} color={
                c.key === 'risk' ? '#ef4444' : c.key === 'network' ? '#8b5cf6' : c.key === 'ai' ? '#06b6d4' : '#f59e0b'
              } label={c.label} />
            ))}
          </div>
          {(flags.ip_group > 0 || flags.ai_flagged > 0 || flags.sim_flagged > 0) && (
            <div className="mt-5 flex flex-wrap gap-3 border-t border-slate-100 pt-4">
              {flags.ip_group > 0 && (
                <span className="inline-flex items-center gap-1.5 rounded-full bg-violet-50 px-3 py-1 text-xs font-bold text-violet-700 ring-1 ring-violet-200">
                  🌐 {flags.ip_group} جلسة بتقاسم IP
                </span>
              )}
              {flags.ai_flagged > 0 && (
                <span className="inline-flex items-center gap-1.5 rounded-full bg-cyan-50 px-3 py-1 text-xs font-bold text-cyan-700 ring-1 ring-cyan-200">
                  🤖 {flags.ai_flagged} جلسة مشبوهة بالذكاء الاصطناعي
                </span>
              )}
              {flags.sim_flagged > 0 && (
                <span className="inline-flex items-center gap-1.5 rounded-full bg-amber-50 px-3 py-1 text-xs font-bold text-amber-700 ring-1 ring-amber-200">
                  🔗 {flags.sim_flagged} جلسة تشابه مرتفع
                </span>
              )}
            </div>
          )}
        </div>
      </Reveal>

      {/* Risk Distribution + Events Chart */}
      <div className="grid gap-6 lg:grid-cols-3">
        <Reveal delay={150} className="lg:col-span-2">
          <div className="rounded-2xl border border-slate-200/70 bg-white p-5">
            <div className="mb-1">
              <h2 className="text-base font-extrabold text-slate-800">توزيع التهديدات خلال 30 يوم</h2>
              <p className="text-xs text-slate-400">عدد التهديدات المستلمة يوميًا عبر امتحاناتك</p>
            </div>
            <div className="mt-4">
              {eventsOverTime.length > 0 ? (
                <AreaChart points={eventsOverTime.map(e => ({ time: e.date, events: e.events }))} height={240} />
              ) : (
                <p className="flex h-44 items-center justify-center text-sm text-slate-400">لا توجد بيانات بعد</p>
              )}
            </div>
          </div>
        </Reveal>

        <Reveal delay={200}>
          <div className="rounded-2xl border border-slate-200/70 bg-white p-5">
            <h2 className="text-base font-extrabold text-slate-800">توزيع مستويات الخطورة</h2>
            <p className="mb-4 text-xs text-slate-400">عدد الجلسات في كل مستوى خطورة</p>
            {riskDist.length > 0 ? (
              <div className="space-y-3">
                {riskDist.map(r => <RiskBar key={r.level} level={r.level} count={r.count} max={maxRiskCount} />)}
              </div>
            ) : (
              <p className="py-6 text-center text-sm text-slate-400">لا توجد بيانات بعد</p>
            )}
          </div>
        </Reveal>
      </div>

      {/* Threat Types Breakdown */}
      {eventTypes.length > 0 && (
        <Reveal delay={250}>
          <div className="rounded-2xl border border-slate-200/70 bg-white p-5">
            <h2 className="text-base font-extrabold text-slate-800">أنواع التهديدات المكتشفة</h2>
            <p className="mb-4 text-xs text-slate-400">كل حدث يُمثّل مؤشر غش محتمل — مُحسب عبر جميع امتحاناتك</p>
            <div className="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-6">
              {eventTypes.map(ev => (
                <div key={ev.type} className="flex flex-col items-center gap-1.5 rounded-xl bg-slate-50 p-4 ring-1 ring-slate-100 transition-all hover:-translate-y-0.5 hover:shadow-md">
                  <span className="text-2xl">{THREAT_ICONS[ev.type] || '⚡'}</span>
                  <span className="text-[10px] font-bold text-slate-500 text-center leading-tight">{eventLabel(ev.type)}</span>
                  <span className="text-lg font-extrabold tabular-nums text-slate-800">{fmtNum(ev.count)}</span>
                  <div className="h-1.5 w-full rounded-full bg-slate-200 overflow-hidden">
                    <div className="h-full rounded-full bg-brand-500" style={{ width: `${Math.round((ev.count / maxThreat) * 100)}%` }} />
                  </div>
                </div>
              ))}
            </div>
          </div>
        </Reveal>
      )}

      {/* Top Risky Students Table */}
      <Reveal delay={300}>
        <div className="overflow-hidden rounded-2xl border border-slate-200/70 bg-white">
          <div className="px-5 pt-5">
            <h2 className="text-base font-extrabold text-slate-800">الطلاب الأعلى خطورة</h2>
            <p className="text-xs text-slate-400">بناءً على مجموع مؤشرات الغش الأربعة — سلوكي، شبكة، ذكاء اصطناعي، تشابه</p>
          </div>
          {topRisky.length > 0 ? (
            <div className="mt-3 overflow-x-auto">
              <table className="w-full min-w-[900px] text-sm">
                <thead>
                  <tr className="border-b border-slate-100 text-right text-[11px] font-bold text-slate-400">
                    <th className="px-5 py-2.5">الطالب</th>
                    <th className="px-5 py-2.5">الامتحان</th>
                    <th className="px-5 py-2.5">سلوك</th>
                    <th className="px-5 py-2.5">شبكة</th>
                    <th className="px-5 py-2.5">AI</th>
                    <th className="px-5 py-2.5">تشابه</th>
                    <th className="px-5 py-2.5">التهديدات</th>
                    <th className="px-5 py-2.5">درجة الخطورة</th>
                  </tr>
                </thead>
                <tbody>
                  {topRisky.map((r) => (
                    <tr key={r.student_id} className="group border-b border-slate-50 last:border-0 transition-colors hover:bg-slate-50/70">
                      <td className="px-5 py-3">
                        <p className="font-bold text-slate-700">{r.fullname}</p>
                        <p className="text-[11px] text-slate-400">{r.username}</p>
                      </td>
                      <td className="px-5 py-3 text-xs font-semibold text-slate-600">{r.exam_name}</td>
                      <td className="px-5 py-3 tabular-nums text-xs">
                        <span title="نسخ">📋{r.copy_count}</span>{' '}
                        <span title="لصق">📥{r.paste_count}</span>{' '}
                        <span title="إخفاء">👁️‍🗨️{r.tab_hidden}</span>{' '}
                        <span title="أدوات المطور">🛠️{r.devtools_count}</span>
                      </td>
                      <td className="px-5 py-3 tabular-nums text-xs">
                        {r.same_ip > 0 ? (
                          <span className="text-rose-600 font-bold">{r.same_ip} طالب بنفس IP</span>
                        ) : (
                          <span className="text-emerald-600">طبيعي</span>
                        )}
                      </td>
                      <td className="px-5 py-3 tabular-nums text-xs">
                        <span className={r.ai_score >= 50 ? 'font-bold text-rose-600' : 'text-slate-500'}>
                          {r.ai_score}
                        </span>
                      </td>
                      <td className="px-5 py-3 tabular-nums text-xs">
                        <span className={r.sim_score >= 50 ? 'font-bold text-rose-600' : 'text-slate-500'}>
                          {r.sim_score}
                        </span>
                      </td>
                      <td className="px-5 py-3 tabular-nums text-xs text-slate-600">{fmtNum(r.event_count)}</td>
                      <td className="px-5 py-3">
                        <RiskBadge level={r.risk_level} score={r.risk_score} />
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          ) : (
            <div className="px-5 py-12 text-center">
              <div className="text-4xl mb-3">🛡️</div>
              <p className="text-sm font-bold text-slate-500">لا يوجد طلاب مشبوهون</p>
              <p className="text-xs text-slate-400 mt-1">كل شيء يبدو طبيعياً — استمر في المراقبة</p>
            </div>
          )}
        </div>
      </Reveal>
    </div>
  )
}
