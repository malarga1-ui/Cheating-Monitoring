import { useEffect, useState } from 'react'
import { Link, useNavigate } from 'react-router-dom'
import { useAuth } from '../auth'
import { api } from '../api'
import Card from '../components/Card'
import Spinner from '../components/Spinner'
import EmptyState from '../components/EmptyState'
import SyncButton from '../components/SyncButton'
import { fmtNum } from '../lib/format'

export default function Courses({ manageOnly = false }) {
  const { user } = useAuth()
  const navigate = useNavigate()
  const isAdmin = user?.role === 'owner'

  const [courses, setCourses] = useState([])
  const [supervisors, setSupervisors] = useState([])
  const [busy, setBusy] = useState(true)
  const [err, setErr] = useState('')
  const [msg, setMsg] = useState('')
  const [selected, setSelected] = useState(null)
  const [granted, setGranted] = useState([])
  const [loadingAccess, setLoadingAccess] = useState(false)

  const reload = async () => {
    try {
      const c = await api.get('/api/courses')
      setCourses(c)
      setErr('')
      if (isAdmin) {
        const s = await api.get('/api/access')
        setSupervisors(s)
      }
    } catch (e) {
      setErr(e.message)
    } finally {
      setBusy(false)
    }
  }

  useEffect(() => {
    reload()
  }, [isAdmin])

  const rename = async (course, name) => {
    const next = name.trim()
    if (!next || next === course.name) return
    try {
      await api.post(`/api/courses/${course.id}/name`, { name: next })
      setMsg('تم حفظ الاسم')
      setTimeout(() => setMsg(''), 2500)
      reload()
    } catch (e) {
      setErr(e.message)
    }
  }

  const openAccess = async (sup) => {
    setSelected(sup)
    setLoadingAccess(true)
    try {
      const res = await api.get(`/api/access/${sup.id}`)
      setGranted(res.granted_course_ids)
    } catch (e) {
      setErr(e.message)
    } finally {
      setLoadingAccess(false)
    }
  }

  const toggleCourse = (cid) => {
    setGranted((g) => ((g || []).includes(cid) ? g.filter((x) => x !== cid) : [...(g || []), cid]))
  }

  const saveAccess = async () => {
    try {
      await api.post(`/api/access/${selected.id}`, { course_ids: granted })
      setMsg('تم حفظ الصلاحيات')
      setTimeout(() => setMsg(''), 2500)
      setSelected(null)
      reload()
    } catch (e) {
      setErr(e.message)
    }
  }

  if (busy) return <Spinner />
  if (err) return <EmptyState icon="⚠️" title="تعذر تحميل الدورات" hint={err} />

  return (
    <div className="space-y-6">
      <header className="animate-fade-up">
        <h1 className="text-2xl font-extrabold text-slate-800">{isAdmin && !manageOnly ? 'الدورات' : isAdmin ? 'دوراتي والصلاحيات' : 'دوراتي'}</h1>
        <p className="mt-1 text-sm text-slate-500">
          {isAdmin
            ? 'استعرض جميع الدورات، عدّل الأسماء، وأدر صلاحيات المشرفين'
            : 'استعرض الدورات المخصصة لك وعدّل أسماءها وامتحاناتها'}
        </p>
      </header>

      {msg && (
        <div className="rounded-xl bg-emerald-50 px-4 py-3 text-sm font-bold text-emerald-700 ring-1 ring-emerald-200 animate-fade-up">
          {msg}
        </div>
      )}

      <div className="grid gap-6 lg:grid-cols-2">
        <Card className="animate-fade-up">
          <div className="flex items-center justify-between px-6 pt-5">
            <div>
              <h2 className="text-base font-extrabold text-slate-800">{isAdmin && !manageOnly ? 'جميع الدورات' : 'دوراتي'}</h2>
              <p className="mt-0.5 text-xs text-slate-400">تُسجّل تلقائياً من أحداث الامتحانات</p>
            </div>
            <span className="rounded-full bg-brand-50 px-3 py-1 text-xs font-bold text-brand-700">
              {fmtNum(courses.length)}
            </span>
          </div>

          {courses.length === 0 ? (
            <div className="p-6">
              <SyncButton onSynced={reload} />
            </div>
          ) : (
            <ul className="mt-3 divide-y divide-slate-50">
              {courses.map((c) => (
                <li key={c.id} className="px-6 py-3.5 transition-colors hover:bg-slate-50/60">
                  <div className="flex flex-wrap items-center justify-between gap-3">
                    <Link
                      to={`/admin/courses/${c.id}`}
                      className="group flex min-w-0 flex-1 items-center gap-2 text-right font-bold text-slate-700 transition-colors hover:text-brand-600"
                    >
                      <span className="truncate">{c.name || `دورة #${c.moodle_course_id}`}</span>
                      <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round" className="shrink-0 text-slate-300 transition-all group-hover:translate-x-0.5 group-hover:text-brand-500">
                        <path d="m9 18 6-6-6-6" />
                      </svg>
                    </Link>
                    <div className="flex items-center gap-2">
                      <span className="rounded-full bg-slate-100 px-2.5 py-1 text-[11px] font-bold tabular-nums text-slate-500">
                        {fmtNum(c.exams_count)} امتحان
                      </span>
                      <span className="rounded-full bg-slate-100 px-2.5 py-1 text-[11px] font-bold tabular-nums text-slate-500">
                        {fmtNum(c.students_count)} طالب
                      </span>
                    </div>
                  </div>
                  <div className="mt-2 flex items-center gap-1">
                    <RenameButton course={c} onSave={rename} />
                    <Link
                      to={`/admin/courses/${c.id}`}
                      className="rounded-lg px-2.5 py-1 text-[11px] font-bold text-brand-600 transition-colors hover:bg-brand-50"
                    >
                      عرض الامتحانات
                    </Link>
                  </div>
                </li>
              ))}
            </ul>
          )}
        </Card>

        {isAdmin && (
          <Card className="animate-fade-up" style={{ animationDelay: '80ms' }}>
            <div className="flex items-center justify-between px-6 pt-5">
              <div>
                <h2 className="text-base font-extrabold text-slate-800">المشرفون</h2>
                <p className="mt-0.5 text-xs text-slate-400">حدّد الدورات المسموح لكل مشرف برؤيتها</p>
              </div>
              <span className="rounded-full bg-brand-50 px-3 py-1 text-xs font-bold text-brand-700">
                {fmtNum(supervisors.length)}
              </span>
            </div>

            <div className="mt-3 divide-y divide-slate-50">
              {supervisors.map((s) => (
                <button
                  key={s.id}
                  onClick={() => openAccess(s)}
                  className="flex w-full items-center justify-between px-6 py-3.5 text-right transition-colors hover:bg-brand-50/40"
                >
                  <div className="min-w-0">
                    <p className="truncate font-bold text-slate-700">{s.fullname || s.username}</p>
                    <p className="text-[11px] text-slate-400">{s.username}</p>
                  </div>
                  <div className="flex items-center gap-2">
                    <span className="rounded-full bg-slate-100 px-2.5 py-1 text-[11px] font-bold text-slate-500">
                      {s.courses_count} دورة
                    </span>
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round" className="text-slate-300">
                      <path d="m9 18 6-6-6-6" />
                    </svg>
                  </div>
                </button>
              ))}
              {supervisors.length === 0 && (
                <p className="px-6 py-8 text-center text-sm text-slate-400">لا يوجد مشرفون — أنشئهم من قاعدة البيانات بدور supervisor</p>
              )}
            </div>
          </Card>
        )}
      </div>

      {selected && (
        <div className="fixed inset-0 z-50 flex items-center justify-center p-4">
          <div className="absolute inset-0 bg-slate-900/40 backdrop-blur-sm" onClick={() => setSelected(null)} />
          <div className="relative w-full max-w-lg rounded-2xl bg-white p-6 shadow-2xl animate-pop">
            <div className="flex items-center justify-between">
              <div>
                <h3 className="text-lg font-extrabold text-slate-800">صلاحيات {selected.fullname || selected.username}</h3>
                <p className="mt-0.5 text-xs text-slate-400">اختر الدورات التي يراها هذا المشرف</p>
              </div>
              <button
                onClick={() => setSelected(null)}
                className="flex h-8 w-8 items-center justify-center rounded-lg text-slate-400 hover:bg-slate-100"
              >
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round">
                  <path d="M18 6 6 18M6 6l12 12" />
                </svg>
              </button>
            </div>

            {loadingAccess ? (
              <div className="py-10"><Spinner /></div>
            ) : (
              <>
                <div className="mt-5 max-h-72 space-y-2 overflow-y-auto">
                  {courses.map((c) => {
                    const on = (granted || []).includes(c.moodle_course_id)
                    return (
                      <label
                        key={c.id}
                        className={`flex cursor-pointer items-center justify-between rounded-xl border px-4 py-3 transition-colors ${
                          on ? 'border-brand-300 bg-brand-50' : 'border-slate-200'
                        }`}
                      >
                        <span className="text-sm font-bold text-slate-700">
                          {c.name || `دورة #${c.moodle_course_id}`}
                          <span className="mr-2 text-[11px] font-normal text-slate-400">{c.exams_count} امتحان</span>
                        </span>
                        <input
                          type="checkbox"
                          checked={on}
                          onChange={() => toggleCourse(c.moodle_course_id)}
                          className="h-4 w-4 accent-brand-600"
                        />
                      </label>
                    )
                  })}
                  {courses.length === 0 && (
                    <p className="py-6 text-center text-sm text-slate-400">لا توجد دورات متاحة بعد</p>
                  )}
                </div>

                <div className="mt-5 flex justify-end gap-2">
                  <button
                    onClick={() => setSelected(null)}
                    className="rounded-xl px-4 py-2.5 text-sm font-bold text-slate-500 hover:bg-slate-100"
                  >
                    إلغاء
                  </button>
                  <button
                    onClick={saveAccess}
                    className="rounded-xl bg-brand-600 px-5 py-2.5 text-sm font-bold text-white shadow-lg shadow-brand-600/25 transition-colors hover:bg-brand-700"
                  >
                    حفظ الصلاحيات
                  </button>
                </div>
              </>
            )}
          </div>
        </div>
      )}
    </div>
  )
}

function RenameButton({ course, onSave }) {
  const [editing, setEditing] = useState(false)
  const [value, setValue] = useState(course.name)

  if (!editing) {
    return (
      <button
        onClick={() => {
          setValue(course.name)
          setEditing(true)
        }}
        className="flex items-center gap-1.5 rounded-lg px-2.5 py-1 text-[11px] font-bold text-slate-500 transition-colors hover:bg-slate-100 hover:text-slate-700"
        title="تعديل الاسم"
      >
        <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round">
          <path d="M17 3a2.8 2.8 0 1 1 4 4L7.5 20.5 2 22l1.5-5.5L17 3Z" />
        </svg>
        تعديل الاسم
      </button>
    )
  }

  return (
    <form
      onSubmit={(e) => {
        e.preventDefault()
        onSave(course, value)
        setEditing(false)
      }}
      className="flex items-center gap-1.5"
    >
      <input
        autoFocus
        value={value}
        onChange={(e) => setValue(e.target.value)}
        className="w-44 rounded-lg border border-brand-300 px-2 py-1 text-sm outline-none focus:ring-2 focus:ring-brand-200"
        placeholder="اسم الدورة"
      />
      <button type="submit" className="rounded-lg bg-brand-600 px-2.5 py-1 text-xs font-bold text-white hover:bg-brand-700">
        حفظ
      </button>
      <button
        type="button"
        onClick={() => setEditing(false)}
        className="rounded-lg px-2 py-1 text-xs font-bold text-slate-400 hover:bg-slate-100"
      >
        إلغاء
      </button>
    </form>
  )
}
