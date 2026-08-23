import { useState } from 'react'
import { Link } from 'react-router-dom'
import { api } from '../api'
import Card from '../components/Card'
import StatCard from '../components/StatCard'
import Spinner from '../components/Spinner'
import AreaChart from '../components/AreaChart'
import RiskBadge from '../components/RiskBadge'
import EmptyState from '../components/EmptyState'
import ThreatAlert from '../components/ThreatAlert'
import AppTour from '../components/AppTour'
import { Reveal } from '../components/motion'
import { eventLabel } from '../lib/risk'
import { fmtNum, fmtTime } from '../lib/format'
import { usePolling } from '../lib/usePolling'

const RANGES = [
  { key: '24h', label: '24 ساعة' },
  { key: '7d', label: '7 أيام' },
  { key: '30d', label: '30 يوم' },
]

const RISK_COLORS = {
  safe:      { bg: 'bg-emerald-50', text: 'text-emerald-700', bar: 'bg-emerald-500' },
  low:       { bg: 'bg-blue-50',    text: 'text-blue-700',    bar: 'bg-blue-500' },
  medium:    { bg: 'bg-amber-50',   text: 'text-amber-700',   bar: 'bg-amber-500' },
  high:      { bg: 'bg-orange-50',  text: 'text-orange-700',  bar: 'bg-orange-500' },
  critical:  { bg: 'bg-rose-50',    text: 'text-rose-700',    bar: 'bg-rose-500' },
}

const THREAT_ICONS = {
  copy: '📋', paste: '📥', tab_hidden: '👁️‍🗨️', tab_visible: '👁️',
  devtools: '🛠️', keydown: '⌨️', screenshot: '📷', tab_switch: '🔄',
  idle: '⏸️', fullscreen_exit: '🖥️', paste_from_menu: '📥',
}

function RiskBar({ level, count, max }) {
  const c = RISK_COLORS[level] || RISK_COLORS.safe
  const pct = max > 0 ? Math.round((count / max) * 100) : 0
  return (
    <div className="flex items-center gap-2">
      <span className={`w-16 text-[11px] font-bold ${c.text}`}>{level}</span>
      <div className="flex-1 h-3 rounded-full bg-slate-100 overflow-hidden">
        <div className={`h-full rounded-full ${c.bar} transition-all duration-700`} style={{ width: `${pct}%` }} />
      </div>
      <span className="w-8 text-center text-[11px] font-bold tabular-nums text-slate-500">{count}</span>
    </div>
  )
}

export default function Dashboard() {
  const [edu, setEdu] = useState(null)
  const [series, setSeries] = useState(null)
  const [summary, setSummary] = useState(null)
  const [range, setRange] = useState('24h')
  const [err, setErr] = useState('')
  const [expandedCourse, setExpandedCourse] = useState(null)
  const [showTour, setShowTour] = useState(() => !localStorage.getItem('exammonitor_admin_tour'))

  const REFRESH = 15000

  usePolling(() => {
    api.get('/api/dashboard/edu-overview').then(setEdu).catch(e => setErr(e.message))
    api.get('/api/dashboard/summary').then(setSummary).catch(() => {})
  }, REFRESH, [])

  usePolling(() => {
    api.get('/api/dashboard/events-over-time?range=' + range).then(setSeries).catch(() => {})
  }, REFRESH, [range])

  if (err) return <EmptyState icon="warning" title="تعذر تحميل البيانات" hint={err} />

  const courses = edu?.courses || []
  const totalExams = courses.reduce((s, c) => s + c.exam_count, 0)
  const totalSuspicious = courses.reduce((s, c) => s + c.suspicious_count, 0)
  const riskDist = edu?.risk_distribution || []
  const maxRiskCount = Math.max(...riskDist.map(r => r.count), 1)
  const threats = edu?.threats || []
  const maxThreat = Math.max(...threats.map(t => t.count), 1)
  const topSuspicious = edu?.top_suspicious || []

  return (
    <div className="space-y-6">
      {showTour && (
        <AppTour onFinish={() => {
          localStorage.setItem('exammonitor_admin_tour', '1')
          setShowTour(false)
        }} />
      )}
      <ThreatAlert />

      {/* Header */}
      <Reveal>
        <header className="flex flex-wrap items-center justify-between gap-3">
          <div>
            <div className="flex items-center gap-3">
              <h1 className="text-2xl font-extrabold text-slate-800">لوحة التحكم</h1>
              <span className="inline-flex items-center gap-1.5 rounded-full bg-emerald-50 px-2.5 py-0.5 text-[11px] font-bold text-emerald-700 ring-1 ring-emerald-200">
                <span className="relative flex h-1.5 w-1.5">
                  <span className="absolute inline-flex h-full w-full animate-ping rounded-full bg-emerald-400 opacity-75"/>
                  <span className="relative inline-flex h-1.5 w-1.5 rounded-full bg-emerald-500"/>
                </span>
                الرصد نشط
              </span>
            </div>
            <p className="mt-1 text-sm text-slate-500">
              نظرة شاملة على المواد والامتحانات والطلاب ومؤشرات الغش
              {summary?.system?.last_aggregation_at && (
                <span className="mr-2 text-xs text-slate-400">
                  · آخر تحديث: {fmtTime(summary.system.last_aggregation_at)}
                </span>
              )}
            </p>
          </div>
        </header>
      </Reveal>

      {/* Summary Stats */}
      <div className="grid grid-cols-2 gap-4 lg:grid-cols-4">
        <StatCard title="المواد" value={courses.length} accent="brand"
          icon={<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round"><path d="M12 3 3 7l9 4 9-4-9-4Z" strokeLinejoin="round"/><path d="M5 9.5V15c0 1.2 3.1 3 7 3s7-1.8 7-3V9.5M19 13v4"/></svg>}
          delay={0} />
        <StatCard title="الامتحانات" value={totalExams} accent="violet"
          icon={<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8Z"/><path d="M14 2v6h6M16 13H8M16 17H8M10 9H8"/></svg>}
          delay={60} />
        <StatCard title="الطلاب" value={edu?.total_students ?? 0} accent="cyan"
          icon={<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round"><circle cx="9" cy="8" r="3.5"/><path d="M2.5 20c.8-3 3.4-4.5 6.5-4.5s5.7 1.5 6.5 4.5"/><path d="M16 5.2a3.5 3.5 0 0 1 0 5.6M17.5 15.6c2 .8 3.4 2.3 4 4.4" strokeLinejoin="round"/></svg>}
          delay={120} />
        <StatCard title="مشبوهون" value={totalSuspicious} accent="rose"
          icon={<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/><path d="m9 12 2 2 4-4"/></svg>}
          delay={180} />
      </div>

      {/* Courses Grid */}
      {courses.length > 0 && (
        <Reveal delay={100}>
          <div>
            <div className="flex items-center justify-between mb-3">
              <div>
                <h2 className="text-base font-extrabold text-slate-800">المواد الدراسية</h2>
                <p className="text-xs text-slate-400">اضغط على مادة لعرض تفاصيل امتحاناتها</p>
              </div>
            </div>
            <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3">
              {courses.map((course) => {
                const isOpen = expandedCourse === course.id
                return (
                  <div key={course.id}>
                    <Card
                      className={`p-5 transition-all duration-300 cursor-pointer ${isOpen ? 'ring-2 ring-brand-400 shadow-lg' : 'hover:-translate-y-1 hover:shadow-xl'}`}
                      hover glow
                      onClick={() => setExpandedCourse(isOpen ? null : course.id)}
                    >
                      <div className="flex items-start justify-between">
                        <div className="min-w-0 flex-1">
                          <h3 className="font-extrabold text-slate-800 truncate">{course.name}</h3>
                          <div className="mt-2 flex flex-wrap items-center gap-3 text-[11px]">
                            <span className="flex items-center gap-1 text-violet-600 font-bold">
                              <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2.5"><path d="M12 3 3 7l9 4 9-4-9-4Z" strokeLinejoin="round"/></svg>
                              {course.exam_count} امتحان
                            </span>
                            <span className="flex items-center gap-1 text-cyan-600 font-bold">
                              <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2.5"><circle cx="9" cy="8" r="3.5"/><path d="M2.5 20c.8-3 3.4-4.5 6.5-4.5s5.7 1.5 6.5 4.5"/></svg>
                              {course.student_count} طالب
                            </span>
                            {course.suspicious_count > 0 && (
                              <span className="flex items-center gap-1 text-rose-600 font-bold">
                                <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2.5"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
                                {course.suspicious_count} مشبوه
                              </span>
                            )}
                          </div>
                        </div>
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round"
                          className={`shrink-0 text-slate-300 transition-transform duration-200 ${isOpen ? 'rotate-180' : ''}`}>
                          <path d="m6 9 6 6 6-6" strokeLinejoin="round"/>
                        </svg>
                      </div>
                    </Card>
                    {/* Expanded: Exams list */}
                    {isOpen && course.exams.length > 0 && (
                      <div className="mt-2 ml-4 space-y-2 border-r-2 border-brand-200 pr-3 animate-in slide-in-from-top-2 duration-200">
                        {course.exams.map(exam => (
                          <Link
                            key={exam.id}
                            to={`/admin/exams/${exam.id}`}
                            className="flex items-center justify-between rounded-xl bg-slate-50 px-4 py-3 ring-1 ring-slate-200/60 transition-all hover:bg-brand-50 hover:ring-brand-200"
                          >
                            <div className="min-w-0">
                              <p className="text-sm font-bold text-slate-700 truncate">{exam.name}</p>
                              <div className="mt-1 flex items-center gap-3 text-[11px]">
                                <span className={`font-bold ${exam.status === 'active' ? 'text-emerald-600' : 'text-slate-400'}`}>
                                  {exam.status === 'active' ? 'مفعّل' : exam.status === 'closed' ? 'مغلق' : 'مسودة'}
                                </span>
                                <span className="text-slate-500">{exam.student_count} طالب</span>
                                <span className="text-slate-400">{fmtNum(exam.event_count)} حدث</span>
                                {exam.suspicious_count > 0 && (
                                  <span className="text-rose-600 font-bold">{exam.suspicious_count} مشبوه</span>
                                )}
                              </div>
                            </div>
                            <div className="flex items-center gap-2">
                              {exam.avg_risk > 0 && (
                                <span className={`rounded-full px-2 py-0.5 text-[10px] font-bold tabular-nums ${
                                  exam.avg_risk >= 70 ? 'bg-rose-100 text-rose-700' :
                                  exam.avg_risk >= 40 ? 'bg-amber-100 text-amber-700' :
                                  'bg-emerald-100 text-emerald-700'
                                }`}>
                                  متوسط الخطورة {exam.avg_risk}
                                </span>
                              )}
                              <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2.5" className="text-slate-300">
                                <path d="m9 18 6-6-6-6" strokeLinecap="round" strokeLinejoin="round"/>
                              </svg>
                            </div>
                          </Link>
                        ))}
                      </div>
                    )}
                  </div>
                )
              })}
            </div>
          </div>
        </Reveal>
      )}

      {/* Risk Distribution + Threats + Chart */}
      <div className="grid gap-6 lg:grid-cols-3">
        {/* Events chart */}
        <Reveal delay={150} className="lg:col-span-2">
          <Card className="p-5" hover glow>
            <div className="mb-1 flex flex-wrap items-center justify-between gap-3">
              <div>
                <h2 className="text-base font-extrabold text-slate-800">توزيع التهديدات زمنيًا</h2>
                <p className="text-xs text-slate-400">عدد التهديدات المستلمة حسب الوقت</p>
              </div>
              <div className="flex rounded-lg bg-slate-100 p-1">
                {RANGES.map((r) => (
                  <button
                    key={r.key}
                    onClick={() => setRange(r.key)}
                    className={`rounded-md px-3 py-1 text-xs font-bold transition-all ${
                      range === r.key ? 'bg-white text-brand-600 shadow-sm' : 'text-slate-500 hover:text-slate-700'
                    }`}
                  >
                    {r.label}
                  </button>
                ))}
              </div>
            </div>
            <div className="mt-4">
              {series ? <AreaChart points={series.points} /> : <Spinner />}
            </div>
          </Card>
        </Reveal>

        {/* Risk Distribution */}
        <Reveal delay={200}>
          <Card className="p-5" hover glow>
            <h2 className="text-base font-extrabold text-slate-800">توزيع مستويات الخطورة</h2>
            <p className="mb-4 text-xs text-slate-400">عدد الطلاب في كل مستوى خطورة</p>
            {riskDist.length > 0 ? (
              <div className="space-y-2.5">
                {riskDist.map(r => <RiskBar key={r.level} level={r.level} count={r.count} max={maxRiskCount} />)}
              </div>
            ) : (
              <p className="text-center text-sm text-slate-400 py-6">لا توجد بيانات بعد</p>
            )}
          </Card>
        </Reveal>
      </div>

      {/* Threat Types Breakdown */}
      {threats.length > 0 && (
        <Reveal delay={250}>
          <Card className="p-5" hover glow>
            <h2 className="text-base font-extrabold text-slate-800">أنواع التهديدات المكتشفة</h2>
            <p className="mb-4 text-xs text-slate-400">كل تهديد يُمثّل محاولة غش — تُحسب كتهديد (Threat) في نموذج SOAR</p>
            <div className="grid grid-cols-2 gap-3 sm:grid-cols-4 lg:grid-cols-8">
              {threats.map(t => (
                <div key={t.type} className="flex flex-col items-center gap-1.5 rounded-xl bg-slate-50 p-3 ring-1 ring-slate-100">
                  <span className="text-xl">{THREAT_ICONS[t.type] || '⚡'}</span>
                  <span className="text-[10px] font-bold text-slate-500 text-center leading-tight">{eventLabel(t.type)}</span>
                  <span className="text-lg font-extrabold tabular-nums text-slate-800">{fmtNum(t.count)}</span>
                  <div className="w-full h-1.5 rounded-full bg-slate-200 overflow-hidden">
                    <div className="h-full rounded-full bg-brand-500" style={{ width: `${Math.round((t.count / maxThreat) * 100)}%` }} />
                  </div>
                </div>
              ))}
            </div>
          </Card>
        </Reveal>
      )}

      {/* Top Suspicious Students */}
      <Reveal delay={300}>
        <Card className="overflow-hidden" hover glow>
          <div className="flex items-center justify-between px-5 pt-5">
            <div>
              <h2 className="text-base font-extrabold text-slate-800">الطلاب المشبوهون</h2>
              <p className="text-xs text-slate-400">بناءً على مجموع مؤشرات الغش الأربعة — سلوكي، شبكة، ذكاء اصطناعي، تشابه</p>
            </div>
            <Link
              to="/admin/exams"
              className="rounded-lg bg-brand-50 px-3 py-1.5 text-xs font-bold text-brand-600 transition-colors hover:bg-brand-100"
            >
              عرض كل الامتحانات
            </Link>
          </div>
          {topSuspicious && topSuspicious.length > 0 ? (
            <div className="mt-3 overflow-x-auto">
              <table className="w-full min-w-[800px] text-sm">
                <thead>
                  <tr className="border-b border-slate-100 text-right text-[11px] font-bold text-slate-400">
                    <th className="px-5 py-2.5">الطالب</th>
                    <th className="px-5 py-2.5">المادة</th>
                    <th className="px-5 py-2.5">الامتحان</th>
                    <th className="px-5 py-2.5">سلوك</th>
                    <th className="px-5 py-2.5">شبكة</th>
                    <th className="px-5 py-2.5">AI</th>
                    <th className="px-5 py-2.5">تشابه</th>
                    <th className="px-5 py-2.5">درجة الخطورة</th>
                  </tr>
                </thead>
                <tbody>
                  {topSuspicious.map((r) => (
                    <tr key={r.student_id + '-' + r.exam_id} className="group border-b border-slate-50 last:border-0 transition-colors hover:bg-slate-50/70">
                      <td className="px-5 py-3">
                        <Link to={'/admin/students/' + r.student_id} className="font-bold text-slate-700 group-hover:text-brand-600">
                          {r.fullname}
                        </Link>
                        <p className="text-[11px] text-slate-400">{r.username}</p>
                      </td>
                      <td className="px-5 py-3 text-xs font-semibold text-slate-600">{r.course_name}</td>
                      <td className="px-5 py-3">
                        <Link to={'/admin/exams/' + r.exam_id} className="text-xs font-semibold text-slate-600 hover:text-brand-600">
                          {r.exam_name}
                        </Link>
                      </td>
                      <td className="px-5 py-3 tabular-nums text-xs text-slate-600">
                        <span title="نسخ">📋{r.copy_count}</span>{' '}
                        <span title="لصق">📥{r.paste_count}</span>{' '}
                        <span title="إخفاء">👁️‍🗨️{r.tab_hidden_count}</span>
                      </td>
                      <td className="px-5 py-3 tabular-nums text-xs">
                        {r.same_ip_student_count > 0 ? (
                          <span className="text-rose-600 font-bold">{r.same_ip_student_count} طالب بنفس IP</span>
                        ) : (
                          <span className="text-emerald-600">طبيعي</span>
                        )}
                      </td>
                      <td className="px-5 py-3 tabular-nums text-xs">
                        <span className={r.ai_suspect_score >= 50 ? 'text-rose-600 font-bold' : 'text-slate-500'}>
                          {r.ai_suspect_score}
                        </span>
                      </td>
                      <td className="px-5 py-3 tabular-nums text-xs">
                        <span className={r.similarity_max_score >= 50 ? 'text-rose-600 font-bold' : 'text-slate-500'}>
                          {r.similarity_max_score}
                        </span>
                      </td>
                      <td className="px-5 py-3">
                        <RiskBadge level={r.risk_level} score={r.risk_score} />
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          ) : topSuspicious && topSuspicious.length === 0 ? (
            <div className="px-5 py-12 text-center">
              <div className="text-4xl mb-3">🛡️</div>
              <p className="text-sm font-bold text-slate-500">لا يوجد طلاب مشبوهون</p>
              <p className="text-xs text-slate-400 mt-1">كل شيء يبدو طبيعياً — استمر في المراقبة</p>
            </div>
          ) : (
            <Spinner />
          )}
        </Card>
      </Reveal>

      {/* Quick Navigation Cards */}
      <Reveal delay={350}>
        <div className="grid grid-cols-2 gap-4 lg:grid-cols-4">
          {[
            { to: '/admin/exams', label: 'الامتحانات', desc: 'عرض كل الامتحانات', icon: '📝', color: 'from-violet-500/10 to-violet-600/5 ring-violet-200' },
            { to: '/admin/teachers', label: 'المدرّسون', desc: 'إدارة المدرّسين', icon: '👨‍🏫', color: 'from-brand-500/10 to-brand-600/5 ring-brand-200' },
            { to: '/admin/account', label: 'الإعدادات', desc: 'إعدادات الحساب والموقع', icon: '⚙️', color: 'from-slate-500/10 to-slate-600/5 ring-slate-200' },
            { to: '/admin/audit', label: 'سجل التدقيق', desc: 'كل الإجراءات المسجلة', icon: '📋', color: 'from-amber-500/10 to-amber-600/5 ring-amber-200' },
          ].map(card => (
            <Link key={card.to} to={card.to}>
              <div className={`rounded-xl bg-gradient-to-br ${card.color} px-4 py-4 ring-1 transition-all hover:-translate-y-0.5 hover:shadow-lg cursor-pointer`}>
                <span className="text-2xl">{card.icon}</span>
                <p className="mt-2 text-sm font-extrabold text-slate-800">{card.label}</p>
                <p className="text-[11px] text-slate-500">{card.desc}</p>
              </div>
            </Link>
          ))}
        </div>
      </Reveal>
    </div>
  )
}
