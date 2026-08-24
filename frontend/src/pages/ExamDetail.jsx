import { useEffect, useState } from 'react'
import { Link, useNavigate, useParams } from 'react-router-dom'
import { api } from '../api'
import Card from '../components/Card'
import Spinner from '../components/Spinner'
import EmptyState from '../components/EmptyState'
import RiskBadge from '../components/RiskBadge'
import AreaChart from '../components/AreaChart'
import BarList from '../components/BarList'
import { Reveal } from '../components/motion'
import { RISK, eventLabel } from '../lib/risk'
import { fmtNum, fmtDuration, fmtTime } from '../lib/format'

const LEVELS = ['critical', 'high', 'medium', 'low', 'safe']

const TABS = [
 { key: 'overview', label: 'نظرة عامة' },
 { key: 'network', label: 'الشبكة' },
 { key: 'similarity', label: 'التشابه' },
 { key: 'devices', label: 'الأجهزة المتعددة' },
]

function SimilarityBar({ score }) {
 const [width, setWidth] = useState(0)
 useEffect(() => {
 const t = setTimeout(() => setWidth(score), 200)
 return () => clearTimeout(t)
 }, [score])
 const color = score >= 80 ? 'bg-rose-500' : score >= 60 ? 'bg-orange-500' : score >= 40 ? 'bg-amber-400' : 'bg-emerald-400'
 return (
 <div className="h-2 w-full overflow-hidden rounded-full bg-slate-100">
 <div
 className={`h-full rounded-full transition-all duration-1000 ease-out ${color}`}
 style={{ width: `${width}%` }}
 />
 </div>
 )
}

function RiskScoreBadge({ score, level }) {
 const colors = {
 critical: 'bg-rose-50 text-rose-700 ring-rose-200',
 high: 'bg-orange-50 text-orange-700 ring-orange-200',
 medium: 'bg-amber-50 text-amber-700 ring-amber-200',
 low: 'bg-emerald-50 text-emerald-700 ring-emerald-200',
 safe: 'bg-slate-50 text-slate-600 ring-slate-200',
 }
 return (
 <span className={`inline-flex items-center gap-1 rounded-full px-2.5 py-0.5 text-xs font-bold ring-1 ${colors[level] || colors.safe}`}>
 <span className="tabular-nums">{fmtNum(score)}</span>
 </span>
 )
}

export default function ExamDetail() {
 const { id } = useParams()
 const navigate = useNavigate()
 const [data, setData] = useState(null)
 const [students, setStudents] = useState(null)
 const [pagination, setPagination] = useState(null)
 const [risk, setRisk] = useState('')
 const [q, setQ] = useState('')
 const [sort, setSort] = useState('risk_desc')
 const [page, setPage] = useState(1)
 const [err, setErr] = useState('')
 const [studentsErr, setStudentsErr] = useState('')
 const [editingName, setEditingName] = useState(false)
 const [nameValue, setNameValue] = useState('')
 const [saving, setSaving] = useState(false)
 const [reportOpen, setReportOpen] = useState(false)
 const [report, setReport] = useState(null)
 const [reportLoading, setReportLoading] = useState(false)
 const [reportErr, setReportErr] = useState('')
 const [tab, setTab] = useState('overview')

 const [networkData, setNetworkData] = useState(null)
 const [networkLoading, setNetworkLoading] = useState(false)
 const [networkErr, setNetworkErr] = useState('')

 const [similarityData, setSimilarityData] = useState(null)
 const [similarityLoading, setSimilarityLoading] = useState(false)
 const [similarityErr, setSimilarityErr] = useState('')

 const [devicesData, setDevicesData] = useState(null)
 const [devicesLoading, setDevicesLoading] = useState(false)
 const [devicesErr, setDevicesErr] = useState('')

 async function openReport() {
 setReportOpen(true)
 setReport(null)
 setReportErr('')
 setReportLoading(true)
 try {
 setReport(await api.get(`/api/reports/exam/${id}`))
 } catch (e) {
 setReportErr(e.message)
 } finally {
 setReportLoading(false)
 }
 }

 const load = () =>
 api
 .get(`/api/exams/${id}`)
 .then(setData)
 .catch((e) => setErr(e.message))

 useEffect(() => { load() }, [id])

 useEffect(() => {
 const params = new URLSearchParams()
 if (risk) params.set('risk', risk)
 if (q.trim()) params.set('q', q.trim())
 if (sort) params.set('sort', sort)
 params.set('page', page)
 params.set('limit', '50')
 api
 .get(`/api/exams/${id}/students?${params.toString()}`)
 .then((d) => {
 setStudents(d.students || d)
 setPagination(d.pagination || null)
 setStudentsErr('')
 })
 .catch((e) => setStudentsErr(e.message))
 }, [id, risk, q, sort, page])

 useEffect(() => { setPage(1) }, [risk, q, sort])

 useEffect(() => {
 if (tab !== 'network' || networkData) return
 setNetworkLoading(true)
 setNetworkErr('')
 api.get(`/api/teacher/exams/${id}/network`)
 .then(setNetworkData)
 .catch((e) => setNetworkErr(e.message))
 .finally(() => setNetworkLoading(false))
 }, [tab, id, networkData])

 useEffect(() => {
 if (tab !== 'similarity' || similarityData) return
 setSimilarityLoading(true)
 setSimilarityErr('')
 api.get(`/api/teacher/exams/${id}/similarity`)
 .then(setSimilarityData)
 .catch((e) => setSimilarityErr(e.message))
 .finally(() => setSimilarityLoading(false))
 }, [tab, id, similarityData])

 useEffect(() => {
 if (tab !== 'devices' || devicesData) return
 setDevicesLoading(true)
 setDevicesErr('')
 api.get(`/api/teacher/exams/${id}/devices`)
 .then(setDevicesData)
 .catch((e) => setDevicesErr(e.message))
 .finally(() => setDevicesLoading(false))
 }, [tab, id, devicesData])

 const save = async (body) => {
 setSaving(true)
 try {
 await api.post(`/api/exams/${id}`, body)
 setEditingName(false)
 await load()
 } catch (e) { setErr(e.message) } finally { setSaving(false) }
 }

 if (err) return <EmptyState icon="⚠️"title="تعذر تحميل الامتحان"hint={err} />
 if (!data) return <Spinner />

 const { exam, course, counts = {}, risk_distribution = {}, events_over_time = [], event_types = [] } = data || {}

 return (
 <div className="space-y-6">
 <header className="animate-fade-up">
 <div className="mb-3 flex items-center gap-2 text-xs font-bold text-slate-400">
 <Link to="/admin/exams"className="inline-flex items-center gap-1.5 transition-colors hover:text-brand-600">
 <svg width="14"height="14"viewBox="0 0 24 24"fill="none"stroke="currentColor"strokeWidth="2"strokeLinecap="round"strokeLinejoin="round">
 <path d="M9 5 4 10l5 5M4 10h16"/>
 </svg>
 الامتحانات
 </Link>
 {course && (
 <>
 <span className="text-slate-300">/</span>
 <Link to={`/admin/courses/${course.id}`} className="transition-colors hover:text-brand-600">
 {course.name || `دورة #${course.moodle_course_id}`}
 </Link>
 </>
 )}
 <span className="text-slate-300">/</span>
 <span className="text-slate-600">{exam.name}</span>
 </div>
 <div className="flex flex-wrap items-center gap-3">
 {editingName ? (
 <form onSubmit={(e) => { e.preventDefault(); if (nameValue.trim()) save({ name: nameValue.trim() }) }} className="flex items-center gap-2">
 <input autoFocus value={nameValue} onChange={(e) => setNameValue(e.target.value)}
 className="w-72 rounded-xl border border-brand-300 px-3 py-2 text-lg font-extrabold text-slate-800 outline-none focus:ring-2 focus:ring-brand-200"placeholder="اسم الامتحان"/>
 <button type="submit"disabled={saving} className="rounded-xl bg-brand-600 px-4 py-2 text-sm font-bold text-white shadow-lg shadow-brand-600/25 transition-colors hover:bg-brand-700 disabled:opacity-50">حفظ</button>
 <button type="button"onClick={() => setEditingName(false)} className="rounded-xl px-3 py-2 text-sm font-bold text-slate-400 hover:bg-slate-100">إلغاء</button>
 </form>
 ) : (
 <h1 className="text-2xl font-extrabold text-slate-800">{exam.name}</h1>
 )}
 <div className="flex items-center gap-2">
 <a
 href={`/api/reports/exam/${id}/csv`}
 className="flex h-8 items-center gap-1.5 rounded-lg px-2.5 text-xs font-bold text-slate-500 transition-colors hover:bg-slate-100 hover:text-brand-600"
 title="تنزيل CSV"
 >
 <svg width="15"height="15"viewBox="0 0 24 24"fill="none"stroke="currentColor"strokeWidth="2"strokeLinecap="round"strokeLinejoin="round">
 <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4M7 10l5 5 5-5M12 15V3"/>
 </svg>
 CSV
 </a>
 <button onClick={openReport} title="عرض التقرير"
 className="flex h-8 items-center gap-1.5 rounded-lg px-2.5 text-xs font-bold text-slate-500 transition-colors hover:bg-slate-100 hover:text-brand-600">
 <svg width="15"height="15"viewBox="0 0 24 24"fill="none"stroke="currentColor"strokeWidth="2"strokeLinecap="round"strokeLinejoin="round">
 <path d="M6 9V2h12v7M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2M6 14h12v8H6v-8Z"/>
 </svg>
 تقرير
 </button>
 <button onClick={() => { setNameValue(exam.name); setEditingName(true) }} title="إعادة تسمية"
 className="flex h-8 w-8 items-center justify-center rounded-lg text-slate-400 transition-colors hover:bg-slate-100 hover:text-slate-600">
 <svg width="15"height="15"viewBox="0 0 24 24"fill="none"stroke="currentColor"strokeWidth="2"strokeLinecap="round"strokeLinejoin="round">
 <path d="M17 3a2.8 2.8 0 1 1 4 4L7.5 20.5 2 22l1.5-5.5L17 3Z"/>
 </svg>
 </button>
 <button onClick={() => save({ status: exam.status === 'active' ? 'ended' : 'active' })} disabled={saving} title="تغيير الحالة"
 className="rounded-full px-3 py-1 text-xs font-bold transition-colors disabled:opacity-50">
 <span className={`inline-flex items-center gap-1.5 rounded-full px-2.5 py-0.5 text-xs font-bold ${exam.status === 'active' ? 'bg-emerald-50 text-emerald-700 ring-1 ring-emerald-200' : 'bg-slate-100 text-slate-500 ring-1 ring-slate-200'}`}>
 <span className={`h-1.5 w-1.5 rounded-full ${exam.status === 'active' ? 'bg-emerald-500' : 'bg-slate-400'}`} />
 {exam.status === 'active' ? 'نشط' : 'منتهي'}
 </span>
 </button>
 </div>
 </div>
 <p className="mt-1 text-sm text-slate-500">
 رقم الامتحان <span className="tabular-nums">{exam.moodle_quiz_id}</span>
 {exam.teacher_name && <> · المدرّس <span className="font-bold text-slate-700">{exam.teacher_name}</span></>}
 {' '}· أول نشاط {fmtTime(exam.first_event_at)} · آخر نشاط {fmtTime(exam.last_event_at)}
 </p>
 </header>

 <div className="grid grid-cols-2 gap-4 lg:grid-cols-4 animate-fade-up"style={{ animationDelay: '60ms' }}>
 {[
 { label: 'الطلاب', value: counts.students, cls: 'text-brand-600' },
 { label: 'الجلسات', value: counts.sessions, cls: 'text-violet-600' },
 { label: 'الأحداث', value: counts.events, cls: 'text-cyan-600' },
 { label: 'مشبوهون', value: counts.suspicious, cls: counts.suspicious > 0 ? 'text-rose-600' : 'text-slate-400' },
 ].map((s) => (
 <div key={s.label} className="rounded-xl bg-white px-4 py-3.5 ring-1 ring-slate-200/70 shadow-sm">
 <p className="text-[11px] font-semibold text-slate-400">{s.label}</p>
 <p className={`mt-0.5 text-2xl font-extrabold tabular-nums ${s.cls}`}>{fmtNum(s.value)}</p>
 </div>
 ))}
 </div>

 <div className="grid gap-6 lg:grid-cols-3">
 <Card className="p-5 lg:col-span-2 animate-fade-up"hover glow>
 <h2 className="text-base font-extrabold text-slate-800">الأحداث عبر الزمن</h2>
 <p className="mb-4 text-xs text-slate-400">توزيع الأحداث خلال فترة الامتحان</p>
 {events_over_time && events_over_time.length > 1 ? <AreaChart points={events_over_time} /> : <p className="py-8 text-center text-sm font-semibold text-slate-400">لا توجد بيانات بعد</p>}
 </Card>

 <Card className="p-5 animate-fade-up"hover glow>
 <h2 className="text-base font-extrabold text-slate-800">توزيع الخطورة</h2>
 <p className="mb-4 text-xs text-slate-400">حسب الجلسات</p>
 <div className="mb-4 flex h-3 overflow-hidden rounded-full">
 {LEVELS.map((lv) => {
 const c = (risk_distribution || []).find((r) => r.level === lv)
 if (!c || !counts.sessions) return null
 return <div key={lv} className={`${RISK[lv].bar} transition-all duration-700`} style={{ width: `${(c.cnt / counts.sessions) * 100}%` }} />
 })}
 </div>
 <ul className="space-y-2">
 {LEVELS.map((lv) => {
 const c = (risk_distribution || []).find((r) => r.level === lv)
 return (
 <li key={lv} className="flex items-center justify-between text-sm">
 <span className="flex items-center gap-2 font-semibold text-slate-600">
 <span className={`h-2.5 w-2.5 rounded-full ${RISK[lv].bar}`} />
 {RISK[lv].label}
 </span>
 <span className="tabular-nums font-bold text-slate-800">{fmtNum(c?.cnt ?? 0)}</span>
 </li>
 )
 })}
 </ul>
 <div className="mt-5 border-t border-slate-100 pt-4">
 <h3 className="mb-3 text-sm font-extrabold text-slate-700">أكثر الأحداث</h3>
 {event_types ? (
 <BarList items={event_types.map((t) => ({ label: eventLabel(t.type), count: t.count }))} max={event_types.length ? Math.max(...event_types.map((t) => t.count), 1) : 1} />
 ) : <Spinner />}
 </div>
 </Card>
 </div>

 <div className="flex gap-1 rounded-2xl bg-white p-1.5 ring-1 ring-slate-200/70 animate-fade-up"style={{ animationDelay: '80ms' }}>
 {TABS.map((t) => (
 <button
 key={t.key}
 onClick={() => setTab(t.key)}
 className={`flex-1 rounded-xl px-4 py-2.5 text-sm font-bold transition-all ${
 tab === t.key ? 'bg-brand-600 text-white shadow-sm' : 'text-slate-500 hover:text-slate-700:text-slate-200'
 }`}
 >
 {t.label}
 </button>
 ))}
 </div>

 {tab === 'overview' && (
 <Card className="overflow-hidden animate-fade-up"hover glow>
 <div className="flex flex-wrap items-center justify-between gap-3 px-5 pt-5">
 <div>
 <h2 className="text-base font-extrabold text-slate-800">الطلاب ({fmtNum(pagination?.total ?? students?.length ?? counts.students)})</h2>
 <p className="text-xs text-slate-400">اضغط على الطالب لعرض تفاصيله الكاملة</p>
 </div>
 <div className="flex flex-wrap items-center gap-2">
 <input value={q} onChange={(e) => setQ(e.target.value)} placeholder="بحث بالاسم…"
 className="w-44 rounded-xl border border-slate-200 bg-white py-2 px-3 text-xs font-semibold outline-none transition-all placeholder:font-normal focus:border-brand-500 focus:ring-4 focus:ring-brand-500/10:text-slate-500:border-brand-400"/>
 <select value={sort} onChange={(e) => setSort(e.target.value)}
 className="rounded-xl border border-slate-200 bg-white px-3 py-2 text-xs font-bold text-slate-600 outline-none focus:border-brand-500">
 <option value="risk_desc">الأخطر أولًا</option>
 <option value="risk_asc">الأقل خطورة</option>
 <option value="events_desc">الأكثر أحداثًا</option>
 <option value="name">بالاسم</option>
 </select>
 </div>
 </div>

 <div className="mt-3 flex flex-wrap gap-1.5 px-5 pb-2">
 <button onClick={() => setRisk('')} className={`rounded-full px-3 py-1 text-xs font-bold transition-all ${risk === '' ? 'bg-slate-800 text-white shadow-sm' : 'bg-slate-100 text-slate-500 hover:bg-slate-200:bg-slate-600'}`}>الكل</button>
 {LEVELS.map((lv) => (
 <button key={lv} onClick={() => setRisk(lv)}
 className={`rounded-full px-3 py-1 text-xs font-bold transition-all ${risk === lv ? RISK[lv].solid : RISK[lv].badge} ${risk !== lv ? 'hover:opacity-80' : 'shadow-sm'}`}>
 {RISK[lv].label}
 </button>
 ))}
 </div>

 <div className="mt-2 overflow-x-auto">
 <table className="w-full min-w-[1460px] text-sm">
 <thead>
 <tr className="border-b border-slate-100 bg-slate-50/60 text-right text-[11px] font-bold text-slate-400">
 <th className="px-5 py-3">الطالب</th>
 <th className="px-5 py-3">الجلسات</th>
 <th className="px-5 py-3">الأحداث</th>
 <th className="px-5 py-3">IP</th>
 <th className="px-5 py-3">AI</th>
 <th className="px-5 py-3">إخفاء تبويب</th>
 <th className="px-5 py-3">نسخ / لصق</th>
 <th className="px-5 py-3">أدوات مطوّر</th>
 <th className="px-5 py-3">لقطات شاشة</th>
 <th className="px-5 py-3">مدة الإخفاء</th>
 <th className="px-5 py-3">الخطورة</th>
 </tr>
 </thead>
 <tbody>
 {students?.map((s, i) => (
 <tr key={s.student_id} className="group cursor-pointer border-b border-slate-50 transition-colors last:border-0 hover:bg-brand-50/40"
 style={{ animationDelay: `${i * 20}ms` }} onClick={() => navigate(`/admin/students/${s.student_id}`)}>
 <td className="px-5 py-3.5">
 <p className="font-bold text-slate-700 group-hover:text-brand-600">{s.fullname}</p>
 <p className="text-[11px] text-slate-400">{s.username}</p>
 </td>
 <td className="px-5 py-3.5 tabular-nums text-slate-600">{fmtNum(s.sessions_count)}</td>
 <td className="px-5 py-3.5 tabular-nums text-slate-600">{fmtNum(s.event_count)}</td>
 <td className="px-5 py-3.5">
 <p className="max-w-[120px] truncate text-[11px] font-bold text-slate-500 ltr" title={s.ip_addresses || ''}>{s.ip_addresses || '—'}</p>
 {s.same_ip_student_count > 0 && <p className="text-[10px] text-rose-500">{s.same_ip_student_count} طالب</p>}
 </td>
 <td className="px-5 py-3.5">
 {(s.ai_suspect_score || 0) > 0 ? (
 <span className={`rounded px-1.5 py-0.5 text-[11px] font-bold ${(s.ai_suspect_score || 0) > 50 ? 'bg-rose-100 text-rose-700' : (s.ai_suspect_score || 0) > 20 ? 'bg-amber-100 text-amber-700' : 'bg-slate-100 text-slate-600'}`}>
 {fmtNum(s.ai_suspect_score)}%
 </span>
 ) : <span className="text-slate-400">—</span>}
 </td>
 <td className="px-5 py-3.5 tabular-nums text-slate-600">{fmtNum(s.tab_hidden_count)}</td>
 <td className="px-5 py-3.5 tabular-nums text-slate-600">{fmtNum(s.copy_count)} / {fmtNum(s.paste_count)}</td>
 <td className="px-5 py-3.5 tabular-nums text-slate-600">
 {s.devtools_count > 0 ? <span className="rounded bg-red-50 px-1.5 py-0.5 text-red-600">{fmtNum(s.devtools_count)}</span> : fmtNum(s.devtools_count)}
 </td>
 <td className="px-5 py-3.5 tabular-nums text-slate-600">
 {s.screenshot_count > 0 ? <span className="rounded bg-rose-50 px-1.5 py-0.5 text-rose-600">{fmtNum(s.screenshot_count)}</span> : fmtNum(s.screenshot_count)}
 </td>
 <td className="px-5 py-3.5 tabular-nums text-slate-600">{fmtDuration(s.tab_hidden_duration_ms)}</td>
 <td className="px-5 py-3.5"><RiskBadge level={s.risk_level} score={s.risk_score} /></td>
 </tr>
 ))}
 </tbody>
 </table>
 {studentsErr && <p className="py-8 text-center text-sm font-bold text-rose-600">{studentsErr}</p>}
 {students && students.length === 0 && !studentsErr && <p className="py-8 text-center text-sm text-slate-400">لا يوجد طلاب مطابقون</p>}
 </div>

 {pagination && pagination.pages > 1 && (
 <div className="flex items-center justify-between border-t border-slate-100 px-5 py-3">
 <p className="text-xs text-slate-400">
 صفحة {pagination.page} من {pagination.pages} · إجمالي {fmtNum(pagination.total)} طالب
 </p>
 <div className="flex items-center gap-1">
 <button onClick={() => setPage((p) => Math.max(1, p - 1))} disabled={page <= 1}
 className="rounded-lg px-3 py-1.5 text-xs font-bold text-slate-600 transition-colors hover:bg-slate-100 disabled:opacity-30:bg-slate-700">السابق</button>
 {Array.from({ length: Math.min(5, pagination.pages) }, (_, i) => {
 const start = Math.max(1, Math.min(page - 2, pagination.pages - 4))
 const p = start + i
 if (p > pagination.pages) return null
 return (
 <button key={p} onClick={() => setPage(p)}
 className={`h-7 w-7 rounded-lg text-xs font-bold transition-all ${p === page ? 'bg-brand-600 text-white shadow-sm' : 'text-slate-500 hover:bg-slate-100:bg-slate-700'}`}>{p}</button>
 )
 })}
 <button onClick={() => setPage((p) => Math.min(pagination.pages, p + 1))} disabled={page >= pagination.pages}
 className="rounded-lg px-3 py-1.5 text-xs font-bold text-slate-600 transition-colors hover:bg-slate-100 disabled:opacity-30:bg-slate-700">التالي</button>
 </div>
 </div>
 )}
 </Card>
 )}

 {tab === 'network' && (
 <Reveal>
 <Card className="overflow-hidden animate-fade-up"hover glow>
 <div className="px-5 pt-5">
 <h2 className="text-base font-extrabold text-slate-800">تحليل الشبكة</h2>
 <p className="text-xs text-slate-400">مجموعات الطلاب الذين يشاركون نفس عنوان IP</p>
 </div>
 {networkLoading ? (
 <div className="py-12"><Spinner /></div>
 ) : networkErr ? (
 <p className="py-12 text-center text-sm font-bold text-rose-600">{networkErr}</p>
 ) : !networkData?.groups?.length ? (
 <EmptyState icon="🌐"title="لا توجد تجمعات شبكية"hint="لم يتم اكتشاف أي تجمعات لطلاب يشاركون نفس العنوان"/>
 ) : (
 <div className="mt-4 space-y-3 px-5 pb-5">
 {networkData.groups.map((group, i) => {
 const isDangerous = group.risk_score >= 60
 return (
 <Reveal key={group.ip} delay={i * 60}>
 <div className={`rounded-xl border p-4 transition-all duration-300 hover:-translate-y-0.5 hover:shadow-md ${
 isDangerous
 ? 'border-rose-200 bg-rose-50/50'
 : 'border-slate-200 bg-white'
 }`}>
 <div className="flex items-center justify-between">
 <div className="flex items-center gap-3">
 <div className={`flex h-10 w-10 items-center justify-center rounded-xl text-lg ${
 isDangerous ? 'bg-rose-100' : 'bg-slate-100'
 }`}>
 {isDangerous ? '🚨' : '🌐'}
 </div>
 <div>
 <p className="text-sm font-extrabold text-slate-800 tabular-nums"dir="ltr">{group.ip}</p>
 <p className="text-[11px] text-slate-400">{group.student_count} طلاب يشاركون هذا العنوان</p>
 </div>
 </div>
 <RiskScoreBadge score={group.risk_score} level={group.risk_score >= 96 ? 'critical' : group.risk_score >= 80 ? 'high' : group.risk_score >= 21 ? 'medium' : group.risk_score >= 5 ? 'low' : 'safe'} />
 </div>
 <div className="mt-3 flex flex-wrap gap-2">
 {group.students.map((st) => (
 <button
 key={st.student_id}
 onClick={() => navigate(`/admin/students/${st.student_id}`)}
 className="inline-flex items-center gap-1.5 rounded-lg bg-white/80 px-2.5 py-1.5 text-xs font-bold text-slate-600 ring-1 ring-slate-200/70 transition-all hover:bg-brand-50 hover:text-brand-600 hover:ring-brand-200:bg-brand-900/20:text-brand-400"
 >
 <span className="h-1.5 w-1.5 rounded-full bg-brand-500"/>
 {st.fullname}
 </button>
 ))}
 </div>
 </div>
 </Reveal>
 )
 })}
 </div>
 )}
 </Card>
 </Reveal>
 )}

 {tab === 'similarity' && (
 <Reveal>
 <Card className="overflow-hidden animate-fade-up"hover glow>
 <div className="px-5 pt-5">
 <h2 className="text-base font-extrabold text-slate-800">كشف التشابه</h2>
 <p className="text-xs text-slate-400">أزواج الطلاب ذوو درجات التشابه العالية في الإجابات</p>
 </div>
 {similarityLoading ? (
 <div className="py-12"><Spinner /></div>
 ) : similarityErr ? (
 <p className="py-12 text-center text-sm font-bold text-rose-600">{similarityErr}</p>
 ) : !similarityData?.pairs?.length ? (
 <EmptyState icon="🔗"title="لا يوجد تشابه"hint="لم يتم اكتشاف تشابه ملحوظ بين إجابات الطلاب"/>
 ) : (
 <div className="mt-4 space-y-3 px-5 pb-5">
 {similarityData.pairs.map((pair, i) => (
 <Reveal key={`${pair.student1_id}-${pair.student2_id}`} delay={i * 60}>
 <div className="rounded-xl border border-slate-200 bg-white p-4 transition-all duration-300 hover:-translate-y-0.5 hover:shadow-md">
 <div className="flex items-center gap-4">
 <button
 onClick={() => navigate(`/admin/students/${pair.student1_id}`)}
 className="min-w-0 flex-1 text-right transition-colors hover:text-brand-600"
 >
 <p className="text-sm font-extrabold text-slate-800">{pair.student1_name}</p>
 <p className="text-[11px] text-slate-400">طالب #1</p>
 </button>
 <div className="flex shrink-0 flex-col items-center gap-1">
 <div className={`flex h-10 w-10 items-center justify-center rounded-full text-sm font-extrabold text-white ${
 pair.similarity_score >= 80 ? 'bg-rose-500' : pair.similarity_score >= 60 ? 'bg-orange-500' : 'bg-amber-400'
 }`}>
 {fmtNum(pair.similarity_score)}%
 </div>
 <span className="text-[10px] font-bold text-slate-400">التشابه</span>
 </div>
 <button
 onClick={() => navigate(`/admin/students/${pair.student2_id}`)}
 className="min-w-0 flex-1 text-left transition-colors hover:text-brand-600"
 >
 <p className="text-sm font-extrabold text-slate-800">{pair.student2_name}</p>
 <p className="text-[11px] text-slate-400">طالب #2</p>
 </button>
 </div>
 <div className="mt-3">
 <SimilarityBar score={pair.similarity_score} />
 </div>
 <div className="mt-2 flex items-center justify-center gap-3 text-[11px] text-slate-400">
 <span>{fmtNum(pair.matching_questions)} / {fmtNum(pair.total_questions)} أسئلة متطابقة</span>
 <span>·</span>
 <span>{Math.round((pair.matching_questions / pair.total_questions) * 100)}% من الأسئلة</span>
 </div>
 </div>
 </Reveal>
 ))}
 </div>
 )}
 </Card>
 </Reveal>
 )}

 {tab === 'devices' && (
 <Reveal>
 <Card className="overflow-hidden animate-fade-up"hover glow>
 <div className="px-5 pt-5">
 <h2 className="text-base font-extrabold text-slate-800">الأجهزة المتعددة</h2>
 <p className="text-xs text-slate-400">الطلاب الذين استخدموا أكثر من جهاز أثناء الامتحان</p>
 </div>
 {devicesLoading ? (
 <div className="py-12"><Spinner /></div>
 ) : devicesErr ? (
 <p className="py-12 text-center text-sm font-bold text-rose-600">{devicesErr}</p>
 ) : !devicesData?.suspects?.length ? (
 <EmptyState icon="📱"title="لا توجد أجهزة متعددة"hint="لم يتم اكتشاف أي طلاب يستخدمون أكثر من جهاز"/>
 ) : (
 <div className="mt-4 space-y-3 px-5 pb-5">
 {devicesData.suspects.map((suspect, i) => (
 <Reveal key={suspect.student_id} delay={i * 60}>
 <div className={`rounded-xl border p-4 transition-all duration-300 hover:-translate-y-0.5 hover:shadow-md ${
 suspect.risk_level === 'critical'
 ? 'border-rose-200 bg-rose-50/50'
 : suspect.risk_level === 'high'
 ? 'border-orange-200 bg-orange-50/50'
 : 'border-slate-200 bg-white'
 }`}>
 <div className="flex items-center justify-between">
 <button
 onClick={() => navigate(`/admin/students/${suspect.student_id}`)}
 className="flex items-center gap-3 transition-colors hover:text-brand-600"
 >
 <div className={`flex h-10 w-10 items-center justify-center rounded-xl text-lg ${
 suspect.risk_level === 'critical' ? 'bg-rose-100' : 'bg-slate-100'
 }`}>
 📱
 </div>
 <div className="text-right">
 <p className="text-sm font-extrabold text-slate-800">{suspect.fullname}</p>
 <p className="text-[11px] text-slate-400">{suspect.device_count} أجهزة مختلفة</p>
 </div>
 </button>
 <RiskBadge level={suspect.risk_level} score={suspect.risk_score} />
 </div>
 <div className="mt-3 grid grid-cols-1 gap-2 sm:grid-cols-2 lg:grid-cols-3">
 {suspect.devices.map((device, j) => (
 <div key={j} className="flex items-center gap-2.5 rounded-lg bg-white/80 px-3 py-2.5 ring-1 ring-slate-200/50">
 <span className="text-base">
 {device.browser?.toLowerCase().includes('chrome') ? '🌐' :
 device.browser?.toLowerCase().includes('firefox') ? '🦊' :
 device.browser?.toLowerCase().includes('safari') ? '🧭' : '💻'}
 </span>
 <div className="min-w-0 flex-1">
 <p className="truncate text-xs font-bold text-slate-700">{device.browser || 'غير معروف'}</p>
 <p className="truncate text-[10px] text-slate-400">{device.os || '—'} · <span className="tabular-nums"dir="ltr">{device.ip || '—'}</span></p>
 </div>
 </div>
 ))}
 </div>
 </div>
 </Reveal>
 ))}
 </div>
 )}
 </Card>
 </Reveal>
 )}

 {reportOpen && (
 <div className="fixed inset-0 z-50 flex items-center justify-center p-4">
 <div className="absolute inset-0 bg-slate-900/40 backdrop-blur-sm"onClick={() => setReportOpen(false)} />
 <div className="relative flex max-h-[90vh] w-full max-w-4xl flex-col rounded-2xl bg-white shadow-2xl animate-pop">
 <div className="flex items-center justify-between border-b border-slate-100 px-6 py-4">
 <div>
 <h3 className="text-lg font-extrabold text-slate-800">تقرير الامتحان: {exam.name}</h3>
 <p className="text-xs text-slate-400">
 رقم الامتحان {exam.moodle_quiz_id}
 {report?.summary?.teacher_name && <> · المدرّس {report.summary.teacher_name}</>}
 </p>
 </div>
 <button onClick={() => setReportOpen(false)} className="flex h-8 w-8 cursor-pointer items-center justify-center rounded-lg text-slate-400 hover:bg-slate-100:bg-slate-700">
 <svg width="16"height="16"viewBox="0 0 24 24"fill="none"stroke="currentColor"strokeWidth="2"strokeLinecap="round">
 <path d="M18 6 6 18M6 6l12 12"/>
 </svg>
 </button>
 </div>
 <div className="min-h-0 flex-1 overflow-y-auto p-6">
 {reportLoading ? (
 <div className="flex items-center justify-center py-16">
 <span className="h-8 w-8 animate-spin rounded-full border-2 border-brand-500/20 border-t-brand-600"/>
 </div>
 ) : reportErr ? (
 <p className="py-12 text-center text-sm font-bold text-rose-600">{reportErr}</p>
 ) : report ? (
 <>
 <div className="mb-6 grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-6">
 {[
 { label: 'الطلاب', value: report.summary.total_students, cls: 'text-brand-600' },
 { label: 'الأحداث', value: report.summary.total_events, cls: 'text-cyan-600' },
  { label: 'شديد', value: report.summary.critical, cls: 'text-rose-600' },
  { label: 'عالٍ', value: report.summary.high, cls: 'text-orange-600' },
  { label: 'متوسط', value: report.summary.medium, cls: 'text-amber-600' },
  { label: 'منخفض', value: report.summary.low, cls: 'text-emerald-600' },
  { label: 'منخفض جداً', value: report.summary.safe, cls: 'text-slate-500' },
 ].map((s) => (
 <div key={s.label} className="rounded-xl bg-slate-50 px-4 py-3 ring-1 ring-slate-200/60">
 <p className="text-[11px] font-semibold text-slate-400">{s.label}</p>
 <p className={`mt-0.5 text-xl font-extrabold tabular-nums ${s.cls}`}>{fmtNum(s.value)}</p>
 </div>
 ))}
 </div>
 <div className="overflow-x-auto rounded-xl border border-slate-100">
 <table className="w-full min-w-[640px] text-sm">
 <thead>
 <tr className="border-b border-slate-100 bg-slate-50/60 text-right text-[11px] font-bold text-slate-400">
 <th className="px-4 py-3">الطالب</th>
 <th className="px-4 py-3">الجلسات</th>
 <th className="px-4 py-3">الأحداث</th>
 <th className="px-4 py-3">الخطورة</th>
 <th className="px-4 py-3">الدرجة</th>
 </tr>
 </thead>
 <tbody>
 {report.students.map((s, i) => (
 <tr key={i} className="border-b border-slate-50 last:border-0">
 <td className="px-4 py-2.5">
 <p className="font-bold text-slate-700">{s.fullname}</p>
 <p className="text-[11px] text-slate-400">{s.username}</p>
 </td>
 <td className="px-4 py-2.5 tabular-nums text-slate-600">{fmtNum(s.sessions_count)}</td>
 <td className="px-4 py-2.5 tabular-nums text-slate-600">{fmtNum(s.event_count)}</td>
 <td className="px-4 py-2.5"><RiskBadge level={s.risk_level} score={s.risk_score} /></td>
 <td className="px-4 py-2.5 tabular-nums font-bold text-slate-700">{fmtNum(s.risk_score)}</td>
 </tr>
 ))}
 </tbody>
 </table>
 </div>
 <p className="mt-4 text-center text-[11px] text-slate-400">
 أُنشئ التقرير {fmtTime(report.summary.generated_at)}
 </p>
 </>
 ) : null}
 </div>
 </div>
 </div>
 )}
 </div>
 )
}
