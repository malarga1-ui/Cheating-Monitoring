import { useEffect, useState } from 'react'
import { Link, useParams } from 'react-router-dom'
import { api } from '../api'
import Card from '../components/Card'
import Spinner from '../components/Spinner'
import EmptyState from '../components/EmptyState'
import RiskBadge from '../components/RiskBadge'
import { Reveal } from '../components/motion'
import { fmtNum } from '../lib/format'

const RISK_FILTERS = [
 { key: '', label: 'الكل' },
 { key: 'high', label: 'مرتفع' },
 { key: 'medium', label: 'متوسط' },
 { key: 'low', label: 'منخفض' },
 { key: 'safe', label: 'آمن' },
]

function similarityColor(score) {
 if (score > 80) return { bar: 'bg-red-500', text: 'text-red-600', ring: 'ring-red-200', bg: 'bg-red-50', glow: 'shadow-red-500/20' }
 if (score > 60) return { bar: 'bg-orange-500', text: 'text-orange-600', ring: 'ring-orange-200', bg: 'bg-orange-50', glow: 'shadow-orange-500/20' }
 if (score > 40) return { bar: 'bg-amber-500', text: 'text-amber-600', ring: 'ring-amber-200', bg: 'bg-amber-50', glow: 'shadow-amber-500/20' }
 return { bar: 'bg-emerald-500', text: 'text-emerald-600', ring: 'ring-emerald-200', bg: 'bg-emerald-50', glow: 'shadow-emerald-500/20' }
}

function riskLabel(level) {
 const map = { high: 'مرتفع', medium: 'متوسط', low: 'منخفض', safe: 'آمن' }
 return map[level] || level
}

function AnimatedBar({ score, delay = 0 }) {
 const [width, setWidth] = useState(0)
 const c = similarityColor(score)

 useEffect(() => {
 const t = setTimeout(() => setWidth(score), 300 + delay)
 return () => clearTimeout(t)
 }, [score, delay])

 return (
 <div className="h-2.5 w-full overflow-hidden rounded-full bg-slate-100">
 <div
 className={`h-full rounded-full ${c.bar} transition-all duration-1000 ease-out`}
 style={{ width: `${width}%` }}
 />
 </div>
 )
}

function StudentAvatar({ name, username, id }) {
 const initials = (name || '').split(' ').slice(0, 2).map((w) => w[0]).join('') || '؟'
 return (
 <Link
  to={`/admin/students/${id}`}
 className="group/avatar flex items-center gap-2.5 transition-all hover:scale-[1.02]"
 onClick={(e) => e.stopPropagation()}
 >
 <div className="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-gradient-to-br from-brand-500 to-violet-600 text-sm font-extrabold text-white shadow-md transition-shadow group-hover/avatar:shadow-lg">
 {initials}
 </div>
 <div className="min-w-0">
 <p className="truncate text-sm font-bold text-slate-800 group-hover/avatar:text-brand-600:text-brand-400">
 {name}
 </p>
 <p className="truncate text-[11px] text-slate-400">{username}</p>
 </div>
 </Link>
 )
}

function SimilarityPair({ pair, index }) {
 const c = similarityColor(pair.similarity_score)

 return (
 <Reveal delay={index * 60}>
 <Card className="p-5 transition-all duration-300 hover:shadow-lg:shadow-slate-900/40"hover glow>
 <div className="flex flex-col gap-4">
 <div className="flex flex-col items-center gap-4 sm:flex-row sm:justify-between">
 <div className="flex w-full items-center justify-center gap-3 sm:w-auto sm:flex-1">
 <StudentAvatar
 name={pair.student1_name}
 username={pair.student1_username}
 id={pair.student1_id}
 />
 </div>

 <div className="flex flex-col items-center gap-1">
 <div className={`flex h-12 w-12 items-center justify-center rounded-full ring-2 ${c.ring} ${c.bg} shadow-md ${c.glow}`}>
 <svg width="20"height="20"viewBox="0 0 24 24"fill="none"stroke="currentColor"strokeWidth="2.5"strokeLinecap="round"strokeLinejoin="round"className={c.text}>
 <path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"/>
 <path d="M13.73 21a2 2 0 0 1-3.46 0"/>
 </svg>
 </div>
 <span className="text-[11px] font-extrabold text-slate-400">VS</span>
 </div>

 <div className="flex w-full items-center justify-center gap-3 sm:w-auto sm:flex-1">
 <StudentAvatar
 name={pair.student2_name}
 username={pair.student2_username}
 id={pair.student2_id}
 />
 </div>
 </div>

 <div className="space-y-2.5">
 <div className="flex items-center justify-between">
 <span className="text-xs font-bold text-slate-500">نسبة التشابه</span>
 <span className={`text-lg font-extrabold tabular-nums ${c.text}`}>
 {fmtNum(pair.similarity_score)}%
 </span>
 </div>
 <AnimatedBar score={pair.similarity_score} delay={index * 60} />
 </div>

 <div className="flex flex-wrap items-center gap-2">
 <RiskBadge level={pair.risk_level} />
 <span className="rounded-full bg-slate-100 px-2.5 py-0.5 text-[11px] font-bold text-slate-500 ring-1 ring-slate-200">
 {fmtNum(pair.matching_questions)}/{fmtNum(pair.total_questions)} أسئلة متطابقة
 </span>
 {pair.exam_name && (
 <span className="rounded-full bg-brand-50 px-2.5 py-0.5 text-[11px] font-bold text-brand-600 ring-1 ring-brand-200">
 {pair.exam_name}
 </span>
 )}
 </div>
 </div>
 </Card>
 </Reveal>
 )
}

export default function SimilarityDetection({ courseId: propCourseId }) {
 const params = useParams()
 const courseId = propCourseId || params.courseId
 const [pairs, setPairs] = useState([])
 const [busy, setBusy] = useState(true)
 const [err, setErr] = useState('')
 const [riskFilter, setRiskFilter] = useState('')

 useEffect(() => {
 let cancelled = false
 setBusy(true)
 const url = '/api/teacher/exams/similarity' + (courseId ? `?course_id=${courseId}` : '')
 api
 .get(url)
 .then((data) => {
 if (cancelled) return
 const list = Array.isArray(data) ? data : data.pairs || []
 setPairs(
 [...list].sort((a, b) => (b.similarity_score || 0) - (a.similarity_score || 0))
 )
 setErr('')
 })
 .catch((e) => {
 if (!cancelled) setErr(e.message)
 })
 .finally(() => {
 if (!cancelled) setBusy(false)
 })
 return () => { cancelled = true }
 }, [courseId])

 const filtered = riskFilter
 ? pairs.filter((p) => p.risk_level === riskFilter)
 : pairs

 const highCount = pairs.filter((p) => p.risk_level === 'high' || p.risk_level === 'critical').length
 const avgScore = pairs.length
 ? Math.round(pairs.reduce((sum, p) => sum + (p.similarity_score || 0), 0) / pairs.length)
 : 0

 if (busy) return <Spinner />
 if (err && !pairs.length) return <EmptyState icon="⚠️"title="تعذر تحميل بيانات التشابه"hint={err} />

 return (
 <div className="space-y-6">
 <header className="animate-fade-up">
 <div className="flex items-center gap-3">
 <div className="flex h-11 w-11 items-center justify-center rounded-xl bg-gradient-to-br from-rose-500 to-orange-500 text-white shadow-lg shadow-rose-500/20">
 <svg width="20"height="20"viewBox="0 0 24 24"fill="none"stroke="currentColor"strokeWidth="2"strokeLinecap="round"strokeLinejoin="round">
 <path d="M8 3H5a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-3"/>
 <path d="M18 3v12"/>
 <path d="m10 14 4-4 4 4"/>
 <circle cx="4"cy="21"r="1"/>
 <path d="m17 17 4 4"/>
 <path d="m21 17-4 4"/>
 </svg>
 </div>
 <div>
 <h1 className="text-2xl font-extrabold text-slate-800">كشف التشابه</h1>
 <p className="mt-0.5 text-sm text-slate-500">مقارنة إجابات الطلاب لاكتشاف النسخ</p>
 </div>
 </div>
 </header>

 <div className="grid grid-cols-1 gap-4 sm:grid-cols-3 animate-fade-up"style={{ animationDelay: '60ms' }}>
 <Card className="p-5"hover glow>
 <div className="flex items-start justify-between">
 <div>
 <p className="text-sm font-semibold text-slate-500">إجمالي الأزواج</p>
 <p className="mt-2 text-3xl font-extrabold tabular-nums text-slate-800">{fmtNum(pairs.length)}</p>
 </div>
 <div className="flex h-11 w-11 shrink-0 items-center justify-center rounded-xl bg-brand-100 text-brand-600">
 <svg width="20"height="20"viewBox="0 0 24 24"fill="none"stroke="currentColor"strokeWidth="2"strokeLinecap="round"strokeLinejoin="round">
 <path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/>
 <circle cx="9"cy="7"r="4"/>
 <path d="M22 21v-2a4 4 0 0 0-3-3.87"/>
 <path d="M16 3.13a4 4 0 0 1 0 7.75"/>
 </svg>
 </div>
 </div>
 </Card>

 <Card className="p-5"hover glow>
 <div className="flex items-start justify-between">
 <div>
 <p className="text-sm font-semibold text-slate-500">أزواج عالية الخطورة</p>
 <p className="mt-2 text-3xl font-extrabold tabular-nums text-rose-600">{fmtNum(highCount)}</p>
 </div>
 <div className="flex h-11 w-11 shrink-0 items-center justify-center rounded-xl bg-rose-100 text-rose-600">
 <svg width="20"height="20"viewBox="0 0 24 24"fill="none"stroke="currentColor"strokeWidth="2"strokeLinecap="round"strokeLinejoin="round">
 <path d="M12 9v4"/>
 <path d="M12 17h.01"/>
 <path d="M10.29 3.86 1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/>
 </svg>
 </div>
 </div>
 </Card>

 <Card className="p-5"hover glow>
 <div className="flex items-start justify-between">
 <div>
 <p className="text-sm font-semibold text-slate-500">متوسط التشابه</p>
 <p className="mt-2 text-3xl font-extrabold tabular-nums text-amber-600">{fmtNum(avgScore)}%</p>
 </div>
 <div className="flex h-11 w-11 shrink-0 items-center justify-center rounded-xl bg-amber-100 text-amber-600">
 <svg width="20"height="20"viewBox="0 0 24 24"fill="none"stroke="currentColor"strokeWidth="2"strokeLinecap="round"strokeLinejoin="round">
 <path d="M12 20V10"/>
 <path d="M18 20V4"/>
 <path d="M6 20v-4"/>
 </svg>
 </div>
 </div>
 </Card>
 </div>

 <div className="flex flex-wrap items-center gap-3 animate-fade-up"style={{ animationDelay: '100ms' }}>
 <div className="flex rounded-xl bg-white p-1 ring-1 ring-slate-200">
 {RISK_FILTERS.map((f) => (
 <button
 key={f.key}
 onClick={() => setRiskFilter(f.key)}
 className={`rounded-lg px-4 py-1.5 text-xs font-bold transition-all ${
 riskFilter === f.key
 ? 'bg-brand-600 text-white shadow-sm'
 : 'text-slate-500 hover:text-slate-700:text-slate-200'
 }`}
 >
 {f.label}
 </button>
 ))}
 </div>
 <span className="text-xs font-bold text-slate-400">
 {fmtNum(filtered.length)} نتيجة
 </span>
 </div>

 {err && (
 <div className="rounded-xl bg-rose-50 px-4 py-3 text-sm font-bold text-rose-700 ring-1 ring-rose-200 animate-fade-up">
 {err}
 </div>
 )}

 {filtered.length === 0 ? (
 <EmptyState
 icon={
 <svg width="24"height="24"viewBox="0 0 24 24"fill="none"stroke="currentColor"strokeWidth="1.8"strokeLinecap="round"strokeLinejoin="round">
 <circle cx="11"cy="11"r="8"/>
 <path d="m21 21-4.3-4.3"/>
 </svg>
 }
 title="لا توجد أزواج تشابه"
 hint={riskFilter ? 'جرّب تغيير الفلتر المحدد' : 'لم يتم رصد أي تشابه بين إجابات الطلاب'}
 />
 ) : (
 <div className="grid grid-cols-1 gap-4 md:grid-cols-2">
 {filtered.map((pair, i) => (
 <SimilarityPair key={`${pair.student1_id}-${pair.student2_id}-${pair.exam_id}`} pair={pair} index={i} />
 ))}
 </div>
 )}
 </div>
 )
}
