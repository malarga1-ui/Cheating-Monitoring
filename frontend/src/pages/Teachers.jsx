import { useEffect, useState } from 'react'
import { Link, useParams } from 'react-router-dom'
import { api } from '../api'
import Card from '../components/Card'
import Spinner from '../components/Spinner'
import EmptyState from '../components/EmptyState'
import RiskBadge from '../components/RiskBadge'
import SyncButton from '../components/SyncButton'
import { fmtNum, fmtTime } from '../lib/format'

export function TeachersList() {
  const [teachers, setTeachers] = useState(null)
  const [err, setErr] = useState('')

  const load = () => api.get('/api/teachers').then(setTeachers).catch((e) => setErr(e.message))

  useEffect(() => { load() }, [])

  return (
    <div className="space-y-6">
      <header className="animate-fade-up">
        <h1 className="text-2xl font-extrabold text-slate-800">المدرّسون</h1>
        <p className="mt-1 text-sm text-slate-500">
          المدرّسون المتزامنون من مودل ودوراتهم وامتحاناتهم
        </p>
      </header>

      <Card className="overflow-hidden animate-fade-up" style={{ animationDelay: '80ms' }} hover>
        {err ? (
          <EmptyState icon="⚠️" title="تعذر تحميل البيانات" hint={err} />
        ) : teachers === null ? (
          <Spinner />
        ) : teachers.length === 0 ? (
          <div className="p-6">
            <SyncButton onSynced={load} />
          </div>
        ) : (
          <div className="overflow-x-auto">
            <table className="w-full min-w-[720px] text-sm">
              <thead>
                <tr className="border-b border-slate-100 bg-slate-50/60 text-right text-[11px] font-bold text-slate-400">
                  <th className="px-5 py-3">المدرّس</th>
                  <th className="px-5 py-3">الدورات</th>
                  <th className="px-5 py-3">الامتحانات</th>
                  <th className="px-5 py-3">الطلاب</th>
                  <th className="px-5 py-3">اسم المستخدم</th>
                  <th className="px-5 py-3">آخر نشاط</th>
                </tr>
              </thead>
              <tbody>
                {teachers.map((t, i) => (
                  <tr
                    key={t.moodle_teacher_id}
                    className="group cursor-pointer border-b border-slate-50 transition-colors last:border-0 hover:bg-brand-50/40"
                    style={{ animationDelay: `${i * 20}ms` }}
                  >
                    <td className="px-5 py-3.5">
                      <Link to={`/admin/teachers/${t.moodle_teacher_id}`} className="block">
                        <p className="font-bold text-slate-700 group-hover:text-brand-600">{t.fullname}</p>
                      </Link>
                    </td>
                    <td className="px-5 py-3.5 tabular-nums text-slate-600">{fmtNum(t.courses_count)}</td>
                    <td className="px-5 py-3.5 tabular-nums text-slate-600">{fmtNum(t.exams_count)}</td>
                    <td className="px-5 py-3.5 tabular-nums text-slate-600">{fmtNum(t.students_count)}</td>
                    <td className="px-5 py-3.5">
                      <span className="font-mono text-xs text-slate-600 bg-slate-50 rounded px-2 py-0.5">{t.username}</span>
                    </td>
                    <td className="px-5 py-3.5 text-xs tabular-nums text-slate-500">{fmtTime(t.last_seen_at)}</td>
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

export function TeacherDetail() {
  const { id } = useParams()
  const [data, setData] = useState(null)
  const [err, setErr] = useState('')

  useEffect(() => {
    api.get(`/api/teachers/${id}`).then(setData).catch((e) => setErr(e.message))
  }, [id])

  if (err) return <EmptyState icon="⚠️" title="تعذر تحميل المدرّس" hint={err} />
  if (!data) return <Spinner />

  const { teacher, courses = [], exams = [] } = data || {}

  return (
    <div className="space-y-6">
      <header className="animate-fade-up">
        <div className="mb-3 flex items-center gap-2 text-xs font-bold text-slate-400">
          <Link to="/admin/teachers" className="inline-flex items-center gap-1.5 transition-colors hover:text-brand-600">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round">
              <path d="M9 5 4 10l5 5M4 10h16" />
            </svg>
            المدرّسون
          </Link>
          <span className="text-slate-300">/</span>
          <span className="text-slate-600">{teacher.fullname}</span>
        </div>
        <div className="flex items-center gap-3">
          <div className="flex h-14 w-14 items-center justify-center rounded-2xl bg-gradient-to-br from-brand-500 to-violet-600 text-xl font-extrabold text-white shadow-lg shadow-brand-600/25">
            {teacher.fullname?.charAt(0)?.toUpperCase() || 'T'}
          </div>
          <div>
            <h1 className="text-2xl font-extrabold text-slate-800">{teacher.fullname}</h1>
            <p className="text-sm text-slate-500">
              {teacher.username} · رقم المدرّس <span className="tabular-nums">{teacher.moodle_teacher_id}</span>
              {teacher.last_seen_at ? <> · آخر نشاط {fmtTime(teacher.last_seen_at)}</> : null}
            </p>
          </div>
        </div>
      </header>

      <div className="grid grid-cols-3 gap-4 animate-fade-up" style={{ animationDelay: '60ms' }}>
        {[
          { label: 'الدورات', value: courses.length, cls: 'text-brand-600' },
          { label: 'الامتحانات', value: exams.length, cls: 'text-violet-600' },
          { label: 'إجمالي الطلاب', value: courses.reduce((s, c) => s + (c.students_count || 0), 0), cls: 'text-cyan-600' },
        ].map((s) => (
          <div key={s.label} className="rounded-xl bg-white px-4 py-3.5 ring-1 ring-slate-200/70 shadow-sm">
            <p className="text-[11px] font-semibold text-slate-400">{s.label}</p>
            <p className={`mt-0.5 text-2xl font-extrabold tabular-nums ${s.cls}`}>{fmtNum(s.value)}</p>
          </div>
        ))}
      </div>

      <Card className="overflow-hidden animate-fade-up" style={{ animationDelay: '100ms' }} hover>
        <div className="px-5 pt-5">
          <h2 className="text-base font-extrabold text-slate-800">الدورات ({courses.length})</h2>
          <p className="text-xs text-slate-400">الدورات التي يُدرّسها هذا المدرّس</p>
        </div>
        <div className="mt-3 overflow-x-auto">
          <table className="w-full min-w-[600px] text-sm">
            <thead>
              <tr className="border-b border-slate-100 bg-slate-50/60 text-right text-[11px] font-bold text-slate-400">
                <th className="px-5 py-3">الدورة</th>
                <th className="px-5 py-3">رقم الدورة</th>
                <th className="px-5 py-3">الامتحانات</th>
                <th className="px-5 py-3">الطلاب</th>
              </tr>
            </thead>
            <tbody>
              {courses.map((c, i) => (
                <tr
                  key={c.moodle_course_id}
                  className="group cursor-pointer border-b border-slate-50 transition-colors last:border-0 hover:bg-brand-50/40"
                  style={{ animationDelay: `${i * 15}ms` }}
                >
                  <td className="px-5 py-3.5">
                    <Link to={`/admin/courses/${c.id}`} className="block">
                      <p className="font-bold text-slate-700 group-hover:text-brand-600">{c.name || `دورة #${c.moodle_course_id}`}</p>
                    </Link>
                  </td>
                  <td className="px-5 py-3.5 tabular-nums text-slate-500 text-xs">{c.moodle_course_id}</td>
                  <td className="px-5 py-3.5 tabular-nums text-slate-600">{fmtNum(c.exams_count)}</td>
                  <td className="px-5 py-3.5 tabular-nums text-slate-600">{fmtNum(c.students_count)}</td>
                </tr>
              ))}
            </tbody>
          </table>
          {courses.length === 0 && (
            <p className="py-8 text-center text-sm text-slate-400">لا توجد دورات بعد</p>
          )}
        </div>
      </Card>

      <Card className="overflow-hidden animate-fade-up" style={{ animationDelay: '140ms' }} hover>
        <div className="px-5 pt-5">
          <h2 className="text-base font-extrabold text-slate-800">الامتحانات ({exams.length})</h2>
          <p className="text-xs text-slate-400">كل امتحانات دورات هذا المدرّس</p>
        </div>
        <div className="mt-3 overflow-x-auto">
          <table className="w-full min-w-[860px] text-sm">
            <thead>
              <tr className="border-b border-slate-100 bg-slate-50/60 text-right text-[11px] font-bold text-slate-400">
                <th className="px-5 py-3">الامتحان</th>
                <th className="px-5 py-3">الدورة</th>
                <th className="px-5 py-3">الحالة</th>
                <th className="px-5 py-3">الطلاب</th>
                <th className="px-5 py-3">الأحداث</th>
                <th className="px-5 py-3">مشبوهون</th>
                <th className="px-5 py-3">آخر نشاط</th>
              </tr>
            </thead>
            <tbody>
              {exams.map((e, i) => (
                <tr
                  key={e.id}
                  className="group cursor-pointer border-b border-slate-50 transition-colors last:border-0 hover:bg-brand-50/40"
                  style={{ animationDelay: `${i * 15}ms` }}
                >
                  <td className="px-5 py-3.5">
                    <Link to={`/admin/exams/${e.id}`} className="block">
                      <p className="font-bold text-slate-700 group-hover:text-brand-600">{e.name}</p>
                      <p className="text-[11px] tabular-nums text-slate-400">رقم الامتحان: {e.moodle_quiz_id}</p>
                    </Link>
                  </td>
                  <td className="px-5 py-3.5">
                    {e.moodle_course_id ? (
                      <Link to={`/admin/courses/${e.moodle_course_id}`} className="text-xs font-semibold text-slate-500 hover:text-brand-600">
                        دورة #{e.moodle_course_id}
                      </Link>
                    ) : (
                      <span className="text-xs text-slate-300">—</span>
                    )}
                  </td>
                  <td className="px-5 py-3.5">
                    <span
                      className={`inline-flex items-center gap-1.5 rounded-full px-2.5 py-0.5 text-xs font-bold ${
                        e.status === 'active'
                          ? 'bg-emerald-50 text-emerald-700 ring-1 ring-emerald-200'
                          : 'bg-slate-100 text-slate-500 ring-1 ring-slate-200'
                      }`}
                    >
                      {e.status === 'active' ? 'نشط' : 'منتهي'}
                    </span>
                  </td>
                  <td className="px-5 py-3.5 tabular-nums text-slate-600">{fmtNum(e.students_count)}</td>
                  <td className="px-5 py-3.5 tabular-nums text-slate-600">{fmtNum(e.events_count)}</td>
                  <td className="px-5 py-3.5">
                    {e.suspicious_count > 0 ? (
                      <span className="inline-flex items-center gap-1 rounded-full bg-rose-50 px-2.5 py-0.5 text-xs font-bold text-rose-600 ring-1 ring-rose-200">
                        {fmtNum(e.suspicious_count)}
                      </span>
                    ) : (
                      <span className="text-xs text-slate-300">0</span>
                    )}
                  </td>
                  <td className="px-5 py-3.5 text-xs tabular-nums text-slate-500">{fmtTime(e.last_event_at)}</td>
                </tr>
              ))}
            </tbody>
          </table>
          {exams.length === 0 && (
            <p className="py-8 text-center text-sm text-slate-400">لا توجد امتحانات بعد</p>
          )}
        </div>
      </Card>
    </div>
  )
}
