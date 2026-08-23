import { useEffect, useState } from 'react'
import { Link, useNavigate, useParams } from 'react-router-dom'
import { api } from '../api'
import Card from '../components/Card'
import Spinner from '../components/Spinner'
import EmptyState from '../components/EmptyState'
import RiskBadge from '../components/RiskBadge'
import { RISK } from '../lib/risk'
import { fmtNum, fmtTime } from '../lib/format'

const LEVELS = ['critical', 'high', 'medium', 'low', 'safe']

export default function CourseDetail() {
  const { id } = useParams()
  const navigate = useNavigate()
  const [data, setData] = useState(null)
  const [students, setStudents] = useState(null)
  const [err, setErr] = useState('')

  useEffect(() => {
    api
      .get(`/api/courses/${id}`)
      .then(setData)
      .catch((e) => setErr(e.message))
    api
      .get(`/api/courses/${id}/students`)
      .then(setStudents)
      .catch(() => {})
  }, [id])

  if (err) return <EmptyState icon="⚠️" title="تعذر تحميل الدورة" hint={err} />
  if (!data) return <Spinner />

  const { course, counts = {}, risk_distribution = {}, exams = [] } = data || {}

  return (
    <div className="space-y-6">
      <header className="animate-fade-up">
         <Link to="/admin/courses" className="mb-3 inline-flex items-center gap-1.5 text-xs font-bold text-slate-400 transition-colors hover:text-brand-600">
          <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round">
            <path d="M9 5 4 10l5 5M4 10h16" />
          </svg>
          العودة إلى الدورات
        </Link>
        <h1 className="text-2xl font-extrabold text-slate-800">{course.name || `دورة #${course.moodle_course_id}`}</h1>
        <p className="mt-1 text-sm text-slate-500">
          رقم الدورة <span className="tabular-nums">{course.moodle_course_id}</span>
        </p>
      </header>

      <div className="grid grid-cols-2 gap-4 lg:grid-cols-5 animate-fade-up" style={{ animationDelay: '60ms' }}>
        {[
          { label: 'الامتحانات', value: counts.exams, cls: 'text-brand-600' },
          { label: 'الطلاب', value: counts.students, cls: 'text-cyan-600' },
          { label: 'الجلسات', value: counts.sessions, cls: 'text-violet-600' },
          { label: 'الأحداث', value: counts.events, cls: 'text-slate-700' },
          { label: 'مشبوهون', value: counts.suspicious, cls: counts.suspicious > 0 ? 'text-rose-600' : 'text-slate-400' },
        ].map((s) => (
          <div key={s.label} className="rounded-xl bg-white px-4 py-3.5 ring-1 ring-slate-200/70 shadow-sm">
            <p className="text-[11px] font-semibold text-slate-400">{s.label}</p>
            <p className={`mt-0.5 text-2xl font-extrabold tabular-nums ${s.cls}`}>{fmtNum(s.value)}</p>
          </div>
        ))}
      </div>

      <div className="grid gap-6 lg:grid-cols-3">
        <Card className="p-5 lg:col-span-2 animate-fade-up" hover>
          <h2 className="text-base font-extrabold text-slate-800">امتحانات الدورة ({fmtNum(exams.length)})</h2>
          <p className="mb-3 text-xs text-slate-400">اضغط على أي امتحان لعرض تفاصيله</p>

          {exams.length === 0 ? (
            <p className="py-10 text-center text-sm text-slate-400">لا توجد امتحانات لهذه الدورة بعد</p>
          ) : (
            <div className="overflow-x-auto">
              <table className="w-full min-w-[760px] text-sm">
                <thead>
                  <tr className="border-b border-slate-100 bg-slate-50/60 text-right text-[11px] font-bold text-slate-400">
                    <th className="px-4 py-2.5">الامتحان</th>
                    <th className="px-4 py-2.5">الحالة</th>
                    <th className="px-4 py-2.5">الطلاب</th>
                    <th className="px-4 py-2.5">الجلسات</th>
                    <th className="px-4 py-2.5">الأحداث</th>
                    <th className="px-4 py-2.5">مشبوهون</th>
                    <th className="px-4 py-2.5">آخر نشاط</th>
                  </tr>
                </thead>
                <tbody>
                  {exams.map((e) => (
                    <tr
                      key={e.id}
                      className="group cursor-pointer border-b border-slate-50 transition-colors last:border-0 hover:bg-brand-50/40"
                      onClick={() => navigate(`/admin/exams/${e.id}`)}
                    >
                      <td className="px-4 py-3">
                        <p className="font-bold text-slate-700 group-hover:text-brand-600">{e.name}</p>
                        <p className="text-[11px] tabular-nums text-slate-400">رقم الامتحان: {e.moodle_quiz_id}</p>
                      </td>
                      <td className="px-4 py-3">
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
                      <td className="px-4 py-3 tabular-nums text-slate-600">{fmtNum(e.students_count)}</td>
                      <td className="px-4 py-3 tabular-nums text-slate-600">{fmtNum(e.sessions_count)}</td>
                      <td className="px-4 py-3 tabular-nums text-slate-600">{fmtNum(e.events_count)}</td>
                      <td className="px-4 py-3">
                        {e.suspicious_count > 0 ? (
                          <span className="inline-flex items-center gap-1 rounded-full bg-rose-50 px-2.5 py-0.5 text-xs font-bold text-rose-600 ring-1 ring-rose-200">
                            {fmtNum(e.suspicious_count)}
                          </span>
                        ) : (
                          <span className="text-xs text-slate-300">0</span>
                        )}
                      </td>
                      <td className="px-4 py-3 text-xs tabular-nums text-slate-500">{fmtTime(e.last_event_at)}</td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          )}
        </Card>

        <Card className="p-5 animate-fade-up" hover>
          <h2 className="text-base font-extrabold text-slate-800">توزيع الخطورة</h2>
          <p className="mb-4 text-xs text-slate-400">حسب جلسات جميع الامتحانات</p>
          <div className="mb-4 flex h-3 overflow-hidden rounded-full">
            {LEVELS.map((lv) => {
              const c = (risk_distribution || []).find((r) => r.level === lv)
              if (!c || !counts.sessions) return null
              return (
                <div
                  key={lv}
                  className={`${RISK[lv].bar} transition-all duration-700`}
                  style={{ width: `${(c.cnt / counts.sessions) * 100}%` }}
                />
              )
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
        </Card>
      </div>

      <Card className="overflow-hidden animate-fade-up" style={{ animationDelay: '120ms' }} hover>
        <div className="px-5 pt-5">
          <h2 className="text-base font-extrabold text-slate-800">طلاب الدورة ({students === null ? '...' : fmtNum(students.length)})</h2>
          <p className="text-xs text-slate-400">كل طالب شارك في امتحانات هذا الكورس — الخطورة والسلوك</p>
        </div>
        {students === null ? (
          <Spinner />
        ) : students.length === 0 ? (
          <p className="py-8 text-center text-sm text-slate-400">لا يوجد طلاب بعد</p>
        ) : (
          <div className="mt-3 overflow-x-auto">
            <table className="w-full min-w-[960px] text-sm">
              <thead>
                <tr className="border-b border-slate-100 bg-slate-50/60 text-right text-[11px] font-bold text-slate-400">
                  <th className="px-5 py-3">الطالب</th>
                  <th className="px-5 py-3">الجلسات</th>
                  <th className="px-5 py-3">الأحداث</th>
                  <th className="px-5 py-3">IP</th>
                  <th className="px-5 py-3">AI</th>
                  <th className="px-5 py-3">تشابه</th>
                  <th className="px-5 py-3">إخفاء</th>
                  <th className="px-5 py-3">نسخ</th>
                  <th className="px-5 py-3">لصق</th>
                  <th className="px-5 py-3">أدوات</th>
                  <th className="px-5 py-3">الخطورة</th>
                </tr>
              </thead>
              <tbody>
                {students.map((s, i) => (
                  <tr
                    key={s.id}
                    className="group cursor-pointer border-b border-slate-50 transition-colors last:border-0 hover:bg-brand-50/40"
                    onClick={() => navigate(`/admin/students/${s.id}`)}
                    style={{ animationDelay: `${i * 15}ms` }}
                  >
                    <td className="px-5 py-3.5">
                      <p className="font-bold text-slate-700 group-hover:text-brand-600">{s.fullname}</p>
                      <p className="text-[11px] text-slate-400">{s.username}</p>
                    </td>
                    <td className="px-5 py-3.5 tabular-nums text-slate-600">{fmtNum(s.sessions_count)}</td>
                    <td className="px-5 py-3.5 tabular-nums text-slate-600">{fmtNum(s.events_count)}</td>
                    <td className="px-5 py-3.5">
                      <p className="text-[11px] tabular-nums text-slate-500 ltr max-w-[120px] truncate" title={s.ip_addresses}>{s.ip_addresses || '—'}</p>
                      {s.ip_changed_count > 0 && <p className="text-[10px] text-rose-500 font-bold">تغيير {s.ip_changed_count}</p>}
                    </td>
                    <td className="px-5 py-3.5">
                      <span className={`text-xs font-bold ${(s.ai_suspect_score || 0) > 50 ? 'text-rose-600' : 'text-slate-600'}`}>
                        {fmtNum(s.ai_suspect_score || 0)}%
                      </span>
                    </td>
                    <td className="px-5 py-3.5">
                      <span className={`text-xs font-bold ${(s.similarity_max_score || 0) > 50 ? 'text-rose-600' : 'text-slate-600'}`}>
                        {fmtNum(s.similarity_max_score || 0)}%
                      </span>
                    </td>
                    <td className="px-5 py-3.5 tabular-nums text-xs">
                      {s.tab_hidden_count > 0 ? (
                        <span className="font-bold text-amber-600">{fmtNum(s.tab_hidden_count)}</span>
                      ) : (
                        <span className="text-slate-300">0</span>
                      )}
                    </td>
                    <td className="px-5 py-3.5 tabular-nums text-xs">
                      {s.copy_count > 0 ? (
                        <span className="font-bold text-amber-600">{fmtNum(s.copy_count)}</span>
                      ) : (
                        <span className="text-slate-300">0</span>
                      )}
                    </td>
                    <td className="px-5 py-3.5 tabular-nums text-xs">
                      {s.paste_count > 0 ? (
                        <span className="font-bold text-amber-600">{fmtNum(s.paste_count)}</span>
                      ) : (
                        <span className="text-slate-300">0</span>
                      )}
                    </td>
                    <td className="px-5 py-3.5 tabular-nums text-xs">
                      {s.devtools_count > 0 ? (
                        <span className="font-bold text-rose-600">{fmtNum(s.devtools_count)}</span>
                      ) : (
                        <span className="text-slate-300">0</span>
                      )}
                    </td>
                    <td className="px-5 py-3.5">
                      <RiskBadge level={s.risk_level} score={s.risk_score} />
                    </td>
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
