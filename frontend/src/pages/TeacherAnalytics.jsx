import { useEffect, useState } from 'react'
import { Link, useParams } from 'react-router-dom'
import { api } from '../api'
import StatCard from '../components/StatCard'
import Spinner from '../components/Spinner'
import AreaChart from '../components/AreaChart'
import { Reveal } from '../components/motion'
import { eventLabel } from '../lib/risk'
import { fmtNum } from '../lib/format'
import { ActionModal, ConfirmModal, StudentTable } from './TeacherPortal'

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
  { key: 'network',    label: 'الشبكة والـ IPs',       icon: '🌐', color: 'from-violet-500 to-violet-600', bg: 'bg-violet-50' },
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

const DEFAULT_THREAT_TYPES = [
  { type: 'tab_hidden', count: 0 },
  { type: 'copy', count: 0 },
  { type: 'paste', count: 0 },
  { type: 'devtools_opened', count: 0 },
  { type: 'rapid_answer_changes', count: 0 },
  { type: 'right_click', count: 0 },
]

const DEFAULT_RISK_DIST = [
  { level: 'critical', count: 0 },
  { level: 'high', count: 0 },
  { level: 'medium', count: 0 },
  { level: 'low', count: 0 },
  { level: 'safe', count: 0 },
]

export default function TeacherAnalytics({ courseId: propCourseId, examId: propExamId, isLiveDashboard = false, hasActiveExam = false }) {
  const params = useParams()
  const examId = propExamId || params.id || params.examId
  const courseId = propCourseId || params.courseId
  const [data, setData] = useState(null)
  const [examStudents, setExamStudents] = useState(null)
  const [err, setErr] = useState('')
  const [q, setQ] = useState('')
  const [sort, setSort] = useState('risk_desc')
  const [risk, setRisk] = useState('all')

  const [actionModal, setActionModal] = useState({ open: false, type: '', student: null })
  const [confirmModal, setConfirmModal] = useState({ open: false, title: '', message: '' })

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

      if (examId) {
        api.get(`/api/teacher/exams/${examId}/students`)
          .then(r => {
            const list = Array.isArray(r?.students) ? r.students : Array.isArray(r) ? r : []
            setExamStudents(list)
          })
          .catch(() => setExamStudents([]))
      } else {
        setExamStudents(null)
      }
    }
    load()
    const timer = setInterval(load, 3000)
    return () => clearInterval(timer)
  }, [examId, courseId])

  async function handleAction(type, actionParams) {
    setActionModal({ open: false, type: '', student: null })
    try {
      const endpoint = type === 'message' ? 'message' : type === 'lock' ? 'lock' : type === 'unlock' ? 'unlock' : 'reduce-time'
      const sid = actionModal.student?.student_id || actionModal.student?.id || actionModal.student?.moodle_user_id || 0
      const ssid = actionModal.student?.session_summary_id || 0
      const targetExamId = examId ? parseInt(examId) : (actionModal.student?.exam_id || 0)

      await api.post(`/api/teacher/actions/${endpoint}`, {
        exam_id: targetExamId,
        session_summary_id: ssid,
        student_id: sid,
        ...actionParams
      })

      setConfirmModal({
        open: true,
        title: 'تم بنجاح',
        message: type === 'message'
          ? 'تم إرسال الرسالة وسيتلقاها الطالب في الامتحان فوراً.'
          : type === 'lock'
          ? 'تم قفل الامتحان عن الطالب فوراً.'
          : type === 'unlock'
          ? 'تم إلغاء قفل الامتحان وسيعود الطالب لاستكمال امتحانه فوراً.'
          : `تم تقليص الوقت بـ ${actionParams.minutes || 5} دقائق.`
      })
    } catch (e) {
      setConfirmModal({ open: true, title: 'تنبيه', message: e.message || 'تعذر إرسال الإجراء' })
    }
  }

  if (err && !data && (examId || courseId)) return <div className="rounded-2xl bg-rose-50 p-6 text-center"><p className="text-sm font-bold text-rose-600">{err}</p></div>
  if (!data && (examId || courseId)) return <Spinner />

  const hasRealData = Boolean((data?.totals?.sessions > 0) || (data?.totals?.students > 0) || (data?.top_risky?.length > 0) || (examStudents && examStudents.length > 0))
  const isIdleDashboard = isLiveDashboard && !hasActiveExam && !hasRealData
  const t = data?.totals || { students: 0, sessions: 0, events: 0, suspicious: 0 }
  const examMeta = data?.exam || null
  const riskDist = (!data?.risk_distribution?.length) ? DEFAULT_RISK_DIST : data.risk_distribution
  const eventTypes = (!data?.event_types?.length) ? DEFAULT_THREAT_TYPES : data.event_types
  const eventsOverTime = data?.events_over_time || []
  const catAvg = data?.category_averages || { risk: 0, network: 0, ai: 0, similarity: 0 }
  const flags = data?.flags || {}
  const topRisky = data?.top_risky || []
  const maxRiskCount = Math.max(...riskDist.map(r => r.count), 1)
  const maxThreat = Math.max(...eventTypes.map(t2 => t2.count), 1)

  // Determine students source: examStudents if exam-scoped, else topRisky
  const rawStudents = (examStudents !== null ? examStudents : topRisky) || []

  const filteredStudents = rawStudents.filter(s => {
    if (q) {
      const matchName = s.fullname?.toLowerCase().includes(q.toLowerCase())
      const matchUser = s.username?.toLowerCase().includes(q.toLowerCase())
      if (!matchName && !matchUser) return false
    }
    if (risk !== 'all' && s.risk_level !== risk) return false
    return true
  }).sort((a, b) => {
    if (sort === 'risk_asc') return (a.risk_score || 0) - (b.risk_score || 0)
    if (sort === 'name') return (a.fullname || '').localeCompare(b.fullname || '', 'ar')
    if (sort === 'events') return (b.event_count || b.total_events || 0) - (a.event_count || a.total_events || 0)
    if (sort === 'ai') return (b.ai_score || b.ai_suspect_score || 0) - (a.ai_score || a.ai_suspect_score || 0)
    return (b.risk_score || 0) - (a.risk_score || 0)
  })

  return (
    <div className="space-y-8">
      {/* Friendly Notice Banner when No Active Exam */}
      {isIdleDashboard && (
        <Reveal>
          <div className="relative overflow-hidden rounded-3xl border border-sky-200/80 bg-gradient-to-r from-sky-50/90 via-white to-indigo-50/70 p-6 shadow-sm ring-1 ring-sky-100/50">
            <div className="flex flex-col sm:flex-row items-start sm:items-center gap-4">
              <div className="flex h-12 w-12 shrink-0 items-center justify-center rounded-2xl bg-gradient-to-br from-sky-500 to-indigo-600 text-white shadow-md shadow-sky-500/20 text-2xl">
                🎓
              </div>
              <div className="flex-1">
                <div className="flex flex-wrap items-center gap-2">
                  <h2 className="text-base font-extrabold text-slate-800">
                    لا توجد امتحانات جارية حالياً للمدرس
                  </h2>
                  <span className="inline-flex items-center gap-1.5 rounded-full bg-sky-100 px-3 py-0.5 text-xs font-extrabold text-sky-700 ring-1 ring-sky-200">
                    <span className="relative flex h-2 w-2">
                      <span className="absolute inline-flex h-full w-full animate-ping rounded-full bg-sky-400 opacity-75" />
                      <span className="relative inline-flex h-2 w-2 rounded-full bg-sky-500" />
                    </span>
                    وضع الجاهزية والاستعداد
                  </span>
                </div>
                <p className="mt-1 text-xs font-bold leading-relaxed text-slate-600">
                  لوحة التحكم جاهزة وفي وضع الاستعداد. فور بدء أي امتحان جديد لمساقاتك، ستتدفق البيانات والطلاب والانتهاكات تلقائياً هنا.
                </p>
                <div className="mt-2.5 flex items-center gap-2 rounded-xl bg-brand-50/80 px-3.5 py-2 text-xs font-extrabold text-brand-700 ring-1 ring-brand-200/60">
                  <span>💡</span>
                  <span>ملاحظة: للاطلاع على تفاصيل وتقارير الطلاب في الامتحانات السابقة التي تم تقديمها، يرجى الذهاب إلى تبويب "مساقاتي الدراسية" واختيار امتحانك المطلوب.</span>
                </div>
              </div>
            </div>
          </div>
        </Reveal>
      )}

      {/* Header */}
      <Reveal>
        {examId ? (
          <header className="flex flex-wrap items-center justify-between gap-3 rounded-2xl border border-brand-200/60 bg-gradient-to-br from-brand-50/50 via-white to-violet-50/40 p-5 shadow-sm">
            <div>
              <Link to={courseId ? `/teacher/portal/c/${courseId}/exams` : '/teacher/portal/exams'} className="text-xs font-extrabold text-brand-600 hover:text-brand-800 transition-colors">
                ← العودة للامتحانات
              </Link>
              <div className="mt-1 flex items-center gap-3">
                <h1 className="text-xl font-extrabold text-slate-800">
                  {examMeta?.name || topRisky[0]?.exam_name || `امتحان #${examId}`}
                </h1>
                <span className="inline-flex items-center gap-1.5 rounded-full bg-emerald-50 px-2.5 py-0.5 text-[11px] font-bold text-emerald-700 ring-1 ring-emerald-200">
                  <span className="relative flex h-2 w-2">
                    <span className="absolute inline-flex h-full w-full animate-ping rounded-full bg-emerald-400 opacity-75"/>
                    <span className="relative inline-flex h-2 w-2 rounded-full bg-emerald-500"/>
                  </span>
                  بث مباشر لحظي
                </span>
              </div>
              <p className="mt-0.5 text-xs text-slate-500 font-semibold">
                {examMeta?.course_name || '—'} · #{examMeta?.moodle_quiz_id || examId}
              </p>
            </div>
            <span className={`rounded-full px-3.5 py-1 text-xs font-extrabold ring-1 ${examMeta?.status === 'active' ? 'bg-emerald-50 text-emerald-700 ring-emerald-200' : 'bg-slate-50 text-slate-500 ring-slate-200'}`}>
              {examMeta?.status === 'active' ? '● نشط' : '○ منتهي'}
            </span>
          </header>
        ) : (
          <header className="flex flex-wrap items-center justify-between gap-3">
            <div>
              <div className="flex items-center gap-3">
                <h1 className="text-2xl font-extrabold text-slate-800">
                  {isLiveDashboard ? 'لوحة تحكم الامتحان المباشر' : 'التحليلات والتقارير المتقدمة'}
                </h1>
                <span className={`inline-flex items-center gap-1.5 rounded-full px-2.5 py-0.5 text-[11px] font-bold ring-1 ${hasActiveExam ? 'bg-emerald-50 text-emerald-700 ring-emerald-200' : 'bg-slate-100 text-slate-600 ring-slate-200'}`}>
                  <span className="relative flex h-2 w-2">
                    <span className={`absolute inline-flex h-full w-full animate-ping rounded-full ${hasActiveExam ? 'bg-emerald-400' : 'bg-slate-400'} opacity-75`}/>
                    <span className={`relative inline-flex h-2 w-2 rounded-full ${hasActiveExam ? 'bg-emerald-500' : 'bg-slate-500'}`}/>
                  </span>
                  {hasActiveExam ? 'بث مباشر نشط' : 'بث مباشر (في الانتظار)'}
                </span>
              </div>
              <p className="mt-1 text-sm text-slate-500">
                {isLiveDashboard
                  ? 'مراقبة فورية ومباشرة للطلاب والانتهاكات للامتحان الشغال في الوقت الحالي'
                  : 'نظرة شاملة على مؤشرات الغش والنزاهة الأكاديمية عبر امتحاناتك ومادتك التعليمية'}
              </p>
            </div>
          </header>
        )}
      </Reveal>

      {/* Summary Stats */}
      <div className="grid grid-cols-2 gap-4 lg:grid-cols-4">
        <StatCard title="الطلاب" value={t.students || 0} accent="cyan"
          icon={<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round"><circle cx="9" cy="8" r="3.5"/><path d="M2.5 20c.8-3 3.4-4.5 6.5-4.5s5.7 1.5 6.5 4.5"/></svg>}
          delay={0} />
        <StatCard title="الجلسات" value={t.sessions || 0} accent="emerald"
          icon={<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 16 14"/></svg>}
          delay={60} />
        <StatCard title="التهديدات" value={t.events || 0} accent="brand"
          icon={<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round"><path d="M22 12h-4l-3 9L9 3l-3 9H2"/></svg>}
          delay={120} />
        <StatCard title="مشبوهون" value={t.suspicious || 0} accent="rose"
          icon={<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/><path d="m9 12 2 2 4-4"/></svg>}
          delay={180} />
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
            <p className="mb-4 text-xs text-slate-400">كل حدث يُمثّل مؤشر غش محتمل — مُحسب عبر امتحاناتك</p>
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

      {/* Interactive Student Table with Teacher Actions */}
      <Reveal delay={300}>
        <div className="space-y-4">
          <div className="flex flex-wrap items-center justify-between gap-3">
            <div>
              <h2 className="text-base font-extrabold text-slate-800">
                الطلاب والمراقبة المباشرة ({filteredStudents.length})
              </h2>
              <p className="text-xs text-slate-400">
                إجراءات المدرس المباشرة (رسالة تحذيرية، تقليص الوقت، قفل الامتحان) والتحليل المفصل
              </p>
            </div>
            <div className="flex items-center gap-2">
              <select value={risk} onChange={e => setRisk(e.target.value)} className="rounded-xl border border-slate-200 bg-white px-3 py-2 text-xs font-bold text-slate-600 outline-none">
                <option value="all">الكل</option>
                <option value="critical">مرتفع جداً</option>
                <option value="high">مرتفع</option>
                <option value="medium">متوسط</option>
                <option value="low">منخفض</option>
                <option value="safe">منخفض جداً</option>
              </select>
              <select value={sort} onChange={e => setSort(e.target.value)} className="rounded-xl border border-slate-200 bg-white px-3 py-2 text-xs font-bold text-slate-600 outline-none">
                <option value="risk_desc">خطورة ↓</option>
                <option value="risk_asc">خطورة ↑</option>
                <option value="name">الاسم</option>
                <option value="events">التهديدات ↓</option>
                <option value="ai">AI ↓</option>
              </select>
              <input value={q} onChange={e => setQ(e.target.value)} placeholder="بحث بالاسم..." className="rounded-xl border border-slate-200 bg-white px-4 py-2 text-sm font-semibold outline-none focus:border-brand-400 focus:ring-4 focus:ring-brand-500/10" />
            </div>
          </div>

          <StudentTable
            students={filteredStudents}
            onAction={(type, student) => setActionModal({ open: true, type, student })}
          />
        </div>
      </Reveal>

      {/* Action and Confirm Modals */}
      <ActionModal
        open={actionModal.open}
        type={actionModal.type}
        studentName={actionModal.student?.fullname || ''}
        onConfirm={p => handleAction(actionModal.type, p)}
        onCancel={() => setActionModal({ open: false, type: '', student: null })}
      />
      <ConfirmModal
        open={confirmModal.open}
        title={confirmModal.title}
        message={confirmModal.message}
        onConfirm={() => setConfirmModal({ open: false })}
        onCancel={() => setConfirmModal({ open: false })}
      />
    </div>
  )
}
