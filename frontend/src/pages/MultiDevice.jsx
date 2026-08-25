import { useEffect, useState } from 'react'
import { Link, useParams } from 'react-router-dom'
import { api } from '../api'
import Card from '../components/Card'
import Spinner from '../components/Spinner'
import EmptyState from '../components/EmptyState'
import RiskBadge from '../components/RiskBadge'
import { Reveal } from '../components/motion'
import { fmtNum, fmtTime } from '../lib/format'
import { riskMeta } from '../lib/risk'

function RiskRing({ score, size = 64 }) {
 const R = size / 2 - 5
 const C = 2 * Math.PI * R
 const s = Math.max(0, Math.min(100, Number(score) || 0))
 const [off, setOff] = useState(C)
 useEffect(() => {
 const t = setTimeout(() => setOff(C - (s / 100) * C), 300)
 return () => clearTimeout(t)
 }, [s, C])
 const meta = riskMeta(s >= 96 ? 'critical' : s >= 80 ? 'high' : s >= 21 ? 'medium' : s >= 5 ? 'low' : 'safe')
 return (
 <div className="relative"style={{ width: size, height: size }}>
 <svg viewBox={`0 0 ${size} ${size}`} className="h-full w-full -rotate-90">
 <circle cx={size / 2} cy={size / 2} r={R} fill="none"stroke="#eef1f6"strokeWidth="5"className=""/>
 <circle
 cx={size / 2}
 cy={size / 2}
 r={R}
 fill="none"
 stroke={meta.dot}
 strokeWidth="5"
 strokeLinecap="round"
 strokeDasharray={C}
 strokeDashoffset={off}
 style={{ transition: 'stroke-dashoffset 1.1s cubic-bezier(.16,1,.3,1)' }}
 />
 </svg>
 <div className="absolute inset-0 flex flex-col items-center justify-center">
 <span className="text-sm font-extrabold tabular-nums text-slate-800">{s}</span>
 <span className="text-[8px] font-bold text-slate-400">من 100</span>
 </div>
 </div>
 )
}

function DeviceCountBadge({ count }) {
 if (count >= 4) {
 return (
 <span className="inline-flex items-center gap-1 rounded-full bg-red-100 px-2.5 py-0.5 text-[11px] font-extrabold text-red-700 ring-1 ring-red-200">
 <svg width="10"height="10"viewBox="0 0 24 24"fill="none"stroke="currentColor"strokeWidth="2.5"strokeLinecap="round">
 <path d="M12 9v4M12 17h.01"strokeLinejoin="round"/>
 </svg>
 {count} أجهزة
 </span>
 )
 }
 if (count >= 3) {
 return (
 <span className="inline-flex items-center gap-1 rounded-full bg-orange-100 px-2.5 py-0.5 text-[11px] font-extrabold text-orange-700 ring-1 ring-orange-200">
 {count} أجهزة
 </span>
 )
 }
 return (
 <span className="inline-flex items-center gap-1 rounded-full bg-amber-100 px-2.5 py-0.5 text-[11px] font-bold text-amber-700 ring-1 ring-amber-200">
 {count} أجهزة
 </span>
 )
}

function DeviceCard({ suspect, index }) {
 const [expanded, setExpanded] = useState(false)
 const initials = (suspect.fullname || '').split(' ').slice(0, 2).map((w) => w[0]).join('') || '؟'
 const dangerLevel = suspect.device_count >= 4 ? 'critical' : suspect.device_count >= 3 ? 'high' : 'medium'
 const borderMap = {
 critical: 'ring-red-300',
 high: 'ring-orange-300',
 medium: 'ring-amber-200',
 }
 const glowMap = {
 critical: 'from-red-500/10 to-red-600/5',
 high: 'from-orange-500/10 to-orange-600/5',
 medium: 'from-amber-500/10 to-amber-600/5',
 }
 const stripeMap = {
 critical: 'bg-red-500',
 high: 'bg-orange-500',
 medium: 'bg-amber-500',
 }

 return (
 <Reveal delay={index * 60}>
 <div
 className={`relative overflow-hidden rounded-2xl bg-white ring-1 shadow-[0_1px_2px_rgba(16,24,40,.04),0_8px_24px_-12px_rgba(16,24,40,.08)] transition-all duration-300 hover:shadow-[0_12px_32px_-12px_rgba(16,24,40,.16)]:shadow-[0_12px_32px_-12px_rgba(0,0,0,.4)] hover:-translate-y-0.5 ${borderMap[dangerLevel]}`}
 >
 <div className={`absolute inset-x-0 top-0 h-1 ${stripeMap[dangerLevel]}`} />
 <div className={`absolute inset-x-0 bottom-0 h-24 bg-gradient-to-b ${glowMap[dangerLevel]} pointer-events-none`} />

 <div className="relative p-5">
 <div className="flex items-start gap-4">
 <div className="relative">
 <div className="flex h-12 w-12 items-center justify-center rounded-xl bg-brand-50 text-base font-extrabold text-brand-700 ring-1 ring-brand-100">
 {initials}
 </div>
 <div className={`absolute -bottom-1 -left-1 flex h-5 w-5 items-center justify-center rounded-full text-[9px] font-extrabold text-white ${stripeMap[dangerLevel]}`}>
 {suspect.device_count}
 </div>
 </div>

 <div className="min-w-0 flex-1">
 <div className="flex items-center gap-2">
 <Link
 to={`/admin/students/${suspect.student_id}`}
 className="text-sm font-extrabold text-slate-800 transition-colors hover:text-brand-600:text-brand-400 truncate"
 >
 {suspect.fullname}
 </Link>
 <DeviceCountBadge count={suspect.device_count} />
 </div>
 <p className="mt-0.5 text-[11px] text-slate-400"dir="ltr">{suspect.username}</p>
 <p className="mt-1 text-[11px] text-slate-400">
 <Link
  to={`/admin/exams/${suspect.exam_id}`}
 className="transition-colors hover:text-brand-600:text-brand-400"
 >
 {suspect.exam_name}
 </Link>
 </p>
 </div>

 <div className="shrink-0">
 <RiskRing score={suspect.risk_score} size={56} />
 </div>
 </div>

 <div className="mt-3 flex items-center justify-between">
 <RiskBadge level={suspect.risk_level} score={suspect.risk_score} />
 <button
 onClick={() => setExpanded(!expanded)}
 className="flex items-center gap-1.5 rounded-lg bg-slate-100 px-3 py-1.5 text-[11px] font-bold text-slate-500 transition-colors hover:bg-slate-200:bg-slate-600"
 >
 <svg
 width="10"
 height="10"
 viewBox="0 0 24 24"
 fill="none"
 stroke="currentColor"
 strokeWidth="2.5"
 className={`transition-transform duration-200 ${expanded ? 'rotate-180' : ''}`}
 >
 <path d="m6 9 6 6 6-6"strokeLinecap="round"strokeLinejoin="round"/>
 </svg>
 {expanded ? 'إخفاء' : 'تفاصيل الأجهزة'}
 </button>
 </div>
 </div>

 {expanded && (
 <div className="relative border-t border-slate-100 px-5 py-4">
 <p className="mb-3 text-[11px] font-bold text-slate-400">الأجهزة المكتشفة ({fmtNum(suspect.devices?.length || 0)})</p>
 <div className="space-y-2.5">
 {(suspect.devices || []).map((dev, i) => (
 <div
 key={i}
 className="flex flex-wrap items-center gap-3 rounded-xl bg-slate-50 px-3.5 py-3 ring-1 ring-slate-100"
 >
 <div className="flex h-8 w-8 items-center justify-center rounded-lg bg-slate-200 text-slate-500">
 <svg width="14"height="14"viewBox="0 0 24 24"fill="none"stroke="currentColor"strokeWidth="2"strokeLinecap="round">
 {dev.os?.toLowerCase().includes('android') || dev.os?.toLowerCase().includes('ios') ? (
 <path d="M12 18h.01M8 21h8M15 2H9a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h6a2 2 0 0 0 2-2V4a2 2 0 0 0-2-2Z"strokeLinejoin="round"/>
 ) : (
 <>
 <rect x="2"y="3"width="20"height="14"rx="2"/>
 <path d="M8 21h8M12 17v4"strokeLinejoin="round"/>
 </>
 )}
 </svg>
 </div>
 <div className="min-w-0 flex-1">
 <div className="flex flex-wrap items-center gap-2">
 <span className="text-xs font-bold text-slate-600">
 {dev.browser || 'غير معروف'}
 </span>
 <span className="rounded bg-slate-200 px-1.5 py-0.5 text-[10px] font-semibold text-slate-500">
 {dev.os || 'غير معروف'}
 </span>
 </div>
 <div className="mt-1 flex flex-wrap items-center gap-3 text-[11px] text-slate-400">
 <span dir="ltr"className="font-mono">{dev.fingerprint || '—'}</span>
 {dev.ip && (
 <span dir="ltr"className="font-mono text-slate-500">{dev.ip}</span>
 )}
 </div>
 {dev.first_seen && (
 <p className="mt-0.5 text-[10px] text-slate-300">
 أول ظهور: {fmtTime(dev.first_seen)}
 </p>
 )}
 </div>
 </div>
 ))}
 </div>
 </div>
 )}
 </div>
 </Reveal>
 )
}

export default function MultiDevice({ courseId: propCourseId }) {
 const params = useParams()
 const courseId = propCourseId || params.courseId
 const [suspects, setSuspects] = useState(null)
 const [err, setErr] = useState('')

 useEffect(() => {
 const url = '/api/teacher/exams/devices' + (courseId ? `?course_id=${courseId}` : '')
 api
 .get(url)
 .then((d) => {
 const sorted = (d.suspects || (Array.isArray(d) ? d : [])).sort((a, b) => (b.device_count || 0) - (a.device_count || 0))
 setSuspects(sorted)
 })
 .catch((e) => setErr(e.message))
 }, [courseId])

 const totalSuspects = suspects?.length ?? 0
 const totalDevices = suspects?.reduce((sum, s) => sum + (s.device_count || 0), 0) ?? 0
 const highestRisk = suspects?.reduce((max, s) => Math.max(max, s.risk_score || 0), 0) ?? 0

 return (
 <div className="space-y-6">
 <Reveal>
 <header>
 <h1 className="text-2xl font-extrabold text-slate-800">الأجهزة المتعددة</h1>
 <p className="mt-1 text-sm text-slate-500">
 كشف الطلاب الذين يستخدمون أكثر من جهاز أثناء الامتحان
 </p>
 </header>
 </Reveal>

 <div className="grid grid-cols-1 gap-4 sm:grid-cols-3">
 <Reveal delay={0}>
 <div className="relative overflow-hidden rounded-2xl bg-white p-5 ring-1 ring-slate-200/70 shadow-[0_1px_2px_rgba(16,24,40,.04),0_8px_24px_-12px_rgba(16,24,40,.08)]">
 <div className="pointer-events-none absolute -right-6 -top-6 h-24 w-24 rounded-full bg-rose-400 opacity-[.08] blur-2xl"/>
 <p className="text-sm font-semibold text-slate-500">إجمالي المشتبه بهم</p>
 <p className="mt-2 text-3xl font-extrabold tabular-nums tracking-tight text-rose-600">
 {suspects === null ? '—' : fmtNum(totalSuspects)}
 </p>
 </div>
 </Reveal>
 <Reveal delay={60}>
 <div className="relative overflow-hidden rounded-2xl bg-white p-5 ring-1 ring-slate-200/70 shadow-[0_1px_2px_rgba(16,24,40,.04),0_8px_24px_-12px_rgba(16,24,40,.08)]">
 <div className="pointer-events-none absolute -right-6 -top-6 h-24 w-24 rounded-full bg-orange-400 opacity-[.08] blur-2xl"/>
 <p className="text-sm font-semibold text-slate-500">إجمالي الأجهزة المكتشفة</p>
 <p className="mt-2 text-3xl font-extrabold tabular-nums tracking-tight text-orange-600">
 {suspects === null ? '—' : fmtNum(totalDevices)}
 </p>
 </div>
 </Reveal>
 <Reveal delay={120}>
 <div className="relative overflow-hidden rounded-2xl bg-white p-5 ring-1 ring-slate-200/70 shadow-[0_1px_2px_rgba(16,24,40,.04),0_8px_24px_-12px_rgba(16,24,40,.08)]">
 <div className="pointer-events-none absolute -right-6 -top-6 h-24 w-24 rounded-full bg-red-400 opacity-[.08] blur-2xl"/>
 <p className="text-sm font-semibold text-slate-500">أعلى درجة خطورة</p>
 <p className="mt-2 text-3xl font-extrabold tabular-nums tracking-tight text-red-600">
 {suspects === null ? '—' : `${fmtNum(highestRisk)}%`}
 </p>
 </div>
 </Reveal>
 </div>

 {err ? (
 <EmptyState icon="⚠️"title="تعذر تحميل البيانات"hint={err} />
 ) : suspects === null ? (
 <Spinner />
 ) : suspects.length === 0 ? (
 <EmptyState
 icon={
 <svg width="24"height="24"viewBox="0 0 24 24"fill="none"stroke="currentColor"strokeWidth="1.8"strokeLinecap="round">
 <path d="M20 6 9 17l-5-5"/>
 </svg>
 }
 title="لا يوجد طلاب بأجهزة متعددة"
 hint="لم يتم كشف أي طالب يستخدم أكثر من جهاز واحد حتى الآن"
 />
 ) : (
 <div className="grid grid-cols-1 gap-4 md:grid-cols-2 xl:grid-cols-3">
 {suspects.map((s, i) => (
 <DeviceCard key={s.student_id} suspect={s} index={i} />
 ))}
 </div>
 )}
 </div>
 )
}
