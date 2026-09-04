import { useEffect, useMemo, useState } from 'react'
import { Link, useParams } from 'react-router-dom'
import { api } from '../api'
import Card from '../components/Card'
import Spinner from '../components/Spinner'
import EmptyState from '../components/EmptyState'
import RiskBadge from '../components/RiskBadge'
import { Reveal } from '../components/motion'
import { fmtNum, fmtTime } from '../lib/format'

const RISK_LEVELS = [
 { key: 'all', label: 'الكل' },
 { key: 'critical', label: 'مرتفع جداً' },
 { key: 'high', label: 'مرتفع' },
 { key: 'medium', label: 'متوسط' },
 { key: 'low', label: 'منخفض' },
 { key: 'safe', label: 'منخفض جداً' },
]

function riskGlow(level) {
 switch (level) {
 case 'critical':
 return 'ring-rose-400/40 shadow-rose-500/10'
 case 'high':
 return 'ring-rose-300/30 shadow-rose-400/10'
 case 'medium':
 return 'ring-amber-300/30 shadow-amber-400/10'
 case 'low':
 return 'ring-emerald-300/30 shadow-emerald-400/10'
 case 'safe':
 return 'ring-emerald-200/30 shadow-emerald-300/10'
 default:
 return ''
 }
}

function riskBorderBar(level) {
 switch (level) {
 case 'critical':
 return 'bg-rose-500'
 case 'high':
 return 'bg-rose-400'
 case 'medium':
 return 'bg-amber-400'
 case 'low':
 return 'bg-emerald-400'
 case 'safe':
 return 'bg-emerald-300'
 default:
 return 'bg-slate-300'
 }
}

function SummaryStat({ label, value, accent = 'brand', delay = 0 }) {
 const colors = {
 brand: 'from-brand-500 to-brand-600',
 rose: 'from-rose-500 to-rose-600',
 amber: 'from-amber-500 to-amber-600',
 }
 return (
 <Reveal delay={delay}>
 <div className="relative overflow-hidden rounded-xl bg-white/70 p-4 ring-1 ring-slate-200/70 backdrop-blur-sm">
 <div className={`absolute inset-x-0 bottom-0 h-0.5 bg-gradient-to-r ${colors.accent || colors.brand}`} />
 <p className="text-xs font-semibold text-slate-500">{label}</p>
 <p className="mt-1 text-2xl font-extrabold tabular-nums text-slate-800">{fmtNum(value)}</p>
 </div>
 </Reveal>
 )
}

export default function NetworkAnalysis({ courseId: propCourseId, examId: propExamId }) {
  const params = useParams()
  const examId = propExamId || params.id || params.examId
  const courseId = propCourseId || params.courseId
  const [groups, setGroups] = useState([])
  const [busy, setBusy] = useState(true)
  const [err, setErr] = useState('')
  const [filter, setFilter] = useState('all')

  useEffect(() => {
    let cancelled = false
    ;(async () => {
      setBusy(true)
      try {
        const url = examId
          ? `/api/teacher/exams/${examId}/network`
          : `/api/teacher/exams/network${courseId ? `?course_id=${courseId}` : ''}`
        const data = await api.get(url)
        if (!cancelled) {
          const list = data?.groups || (Array.isArray(data) ? data : [])
          setGroups([...list].sort((a, b) => (b.risk_score || 0) - (a.risk_score || 0)))
          setErr('')
        }
      } catch (e) {
        if (!cancelled) setErr(e.message)
      } finally {
        if (!cancelled) setBusy(false)
      }
    })()
    return () => { cancelled = true }
  }, [courseId, examId])

 const filtered = useMemo(() => {
 if (filter === 'all') return groups
 return groups.filter((g) => g.risk_level === filter)
 }, [groups, filter])

 const totalStudents = useMemo(() => groups.reduce((s, g) => s + (g.student_count || 0), 0), [groups])
 const highestRisk = groups.length ? groups[0] : null

 if (busy) return <Spinner />

 if (err && !groups.length) {
 return (
 <div className="space-y-4">
 <header className="animate-fade-up">
 <h1 className="text-2xl font-extrabold text-slate-800">تحليل الشبكة</h1>
 <p className="mt-1 text-sm text-slate-500">كشف التجمع على IP واحد والأجهزة المتعددة</p>
 </header>
 <div className="rounded-xl bg-rose-50 px-4 py-3 text-sm font-bold text-rose-700 ring-1 ring-rose-200 animate-fade-up">
 {err}
 </div>
 </div>
 )
 }

 return (
 <div className="space-y-6">
 <header className="flex flex-wrap items-end justify-between gap-3 animate-fade-up">
 <div>
 <h1 className="text-2xl font-extrabold text-slate-800">تحليل الشبكة</h1>
 <p className="mt-1 text-sm text-slate-500">كشف التجمع على IP واحد والأجهزة المتعددة</p>
 </div>
 </header>

 <div className="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-3">
 <SummaryStat label="إجمالي المجموعات"value={groups.length} accent="brand"delay={0} />
 <SummaryStat label="الطلاب في مجموعات"value={totalStudents} accent="amber"delay={80} />
 <SummaryStat label="أعلى مستوى خطورة"value={highestRisk?.risk_score || 0} accent="rose"delay={160} />
 </div>

 <Reveal delay={200}>
 <div className="flex flex-wrap gap-1.5 rounded-xl bg-white/70 p-1.5 ring-1 ring-slate-200/70 backdrop-blur-sm">
 {RISK_LEVELS.map((rl) => (
 <button
 key={rl.key}
 onClick={() => setFilter(rl.key)}
 className={`flex-1 min-w-[60px] cursor-pointer rounded-lg px-3 py-2 text-xs font-bold transition-all ${
 filter === rl.key
 ? 'bg-brand-600 text-white shadow-md shadow-brand-500/20'
 : 'text-slate-500 hover:bg-slate-100:bg-slate-700/50'
 }`}
 >
 {rl.label}
 </button>
 ))}
 </div>
 </Reveal>

 {err && (
 <div className="rounded-xl bg-rose-50 px-4 py-3 text-sm font-bold text-rose-700 ring-1 ring-rose-200 animate-fade-up">
 {err}
 </div>
 )}

 {filtered.length === 0 ? (
 <EmptyState
 icon={
 <svg width="24"height="24"viewBox="0 0 24 24"fill="none"stroke="currentColor"strokeWidth="2"strokeLinecap="round"strokeLinejoin="round">
 <path d="M12 22c5.523 0 10-4.477 10-10S17.523 2 12 2 2 6.477 2 12s4.477 10 10 10Z"/>
 <path d="M8 12h8M12 8v8"/>
 </svg>
 }
 title="لا توجد مجموعات شبكة"
 hint={filter !== 'all' ? 'جرّب تغيير الفلتر لعرض نتائج أخرى' : 'لم يتم رصد أي تجمع على عنوان IP واحد'}
 />
 ) : (
 <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
 {filtered.map((g, i) => (
 <Reveal key={g.ip} delay={i * 60}>
 <Card hover glow className={`h-full ring-1 ${riskGlow(g.risk_level)}`}>
 <div className={`h-1 w-full rounded-t-2xl ${riskBorderBar(g.risk_level)}`} />
 <div className="p-5 space-y-4">
 <div className="flex items-start justify-between gap-2">
 <div>
 <p className="text-xs font-semibold text-slate-400">عنوان IP</p>
 <p className="mt-0.5 text-xl font-extrabold tracking-wide text-slate-800"dir="ltr"style={{ textAlign: 'right' }}>
 {g.ip}
 </p>
 </div>
 <RiskBadge level={g.risk_level} score={g.risk_score} />
 </div>

 <div className="flex items-center gap-4 text-xs text-slate-500">
 <span className="flex items-center gap-1.5">
 <svg width="14"height="14"viewBox="0 0 24 24"fill="none"stroke="currentColor"strokeWidth="2"strokeLinecap="round"strokeLinejoin="round">
 <path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/>
 <circle cx="9"cy="7"r="4"/>
 <path d="M22 21v-2a4 4 0 0 0-3-3.87"/>
 <path d="M16 3.13a4 4 0 0 1 0 7.75"/>
 </svg>
 {fmtNum(g.student_count)} طالب
 </span>
 <span className="flex items-center gap-1.5">
 <svg width="14"height="14"viewBox="0 0 24 24"fill="none"stroke="currentColor"strokeWidth="2"strokeLinecap="round"strokeLinejoin="round">
 <circle cx="12"cy="12"r="10"/>
 <polyline points="12 6 12 12 16 14"/>
 </svg>
 {fmtTime(g.last_seen)}
 </span>
 </div>

 <div className="divide-y divide-slate-100">
 {(g.students || []).map((s) => (
 <Link
 key={s.student_id}
 to={`/admin/students/${s.student_id}`}
 className="group/student flex items-center justify-between gap-2 py-2 transition-colors hover:bg-slate-50:bg-slate-700/30 -mx-2 px-2 rounded-lg"
 >
 <div className="flex items-center gap-2 min-w-0">
 <span className="flex h-7 w-7 shrink-0 items-center justify-center rounded-full bg-slate-100 text-[10px] font-extrabold text-slate-500">
 {(s.fullname || s.username || '?')[0]}
 </span>
 <div className="min-w-0">
 <p className="truncate text-sm font-bold text-slate-700 group-hover/student:text-brand-600:text-brand-400">
 {s.fullname || s.username}
 </p>
 {s.fullname && s.username && (
 <p className="truncate text-[11px] text-slate-400">@{s.username}</p>
 )}
 </div>
 </div>
 <span className="shrink-0 rounded-full bg-slate-100 px-2 py-0.5 text-[10px] font-bold text-slate-500">
 {fmtNum(s.session_count)} جلسة
 </span>
 </Link>
 ))}
 </div>

 {(g.first_seen || g.last_seen) && (
 <p className="text-[11px] text-slate-400"dir="ltr"style={{ textAlign: 'right' }}>
 من {fmtTime(g.first_seen)} — إلى {fmtTime(g.last_seen)}
 </p>
 )}
 </div>
 </Card>
 </Reveal>
 ))}
 </div>
 )}
 </div>
 )
}
