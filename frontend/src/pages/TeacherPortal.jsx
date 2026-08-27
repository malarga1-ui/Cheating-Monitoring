import { useCallback, useEffect, useMemo, useState } from 'react'
import { Link, Navigate, Route, Routes, useLocation, useNavigate, useParams } from 'react-router-dom'
import { useAuth } from '../auth'
import { useI18n } from '../i18n'
import { api } from '../api'
import RiskBadge from '../components/RiskBadge'
import TeacherAnalytics from './TeacherAnalytics'
import TeacherNotifications from '../components/TeacherNotifications'
import NetworkAnalysis from './NetworkAnalysis'
import SimilarityDetection from './SimilarityDetection'
import MultiDevice from './MultiDevice'
import AppTour from '../components/AppTour'

function Spinner() {
  return (
    <div className="flex min-h-[30vh] items-center justify-center">
      <span className="h-8 w-8 animate-spin rounded-full border-2 border-brand-500/20 border-t-brand-600" />
    </div>
  )
}

function StatCard({ value, label, tone = 'text-slate-800', icon }) {
  return (
    <div className="rounded-2xl border border-slate-200/70 bg-white p-5 shadow-[0_1px_2px_rgba(15,23,42,.04)] transition-all hover:shadow-md hover:-translate-y-0.5">
      {icon && <div className="mb-2">{icon}</div>}
      <p className={`text-3xl font-extrabold tabular-nums ${tone}`}>{value}</p>
      <p className="mt-1 text-sm font-bold text-slate-500">{label}</p>
    </div>
  )
}

function Empty({ text = 'لا توجد بيانات' }) {
  return (
    <div className="rounded-2xl border border-dashed border-slate-200 bg-slate-50/50 p-8 text-center">
      <p className="text-sm font-bold text-slate-500">{text}</p>
    </div>
  )
}

export function ConfirmModal({ open, title, message, onConfirm, onCancel }) {
  if (!open) return null
  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/40 backdrop-blur-sm" onClick={onCancel}>
      <div className="mx-4 w-full max-w-sm rounded-2xl bg-white p-6 shadow-2xl" onClick={e => e.stopPropagation()}>
        <h3 className="text-lg font-extrabold text-slate-800">{title}</h3>
        <p className="mt-2 text-sm leading-relaxed text-slate-500">{message}</p>
        <div className="mt-6 flex gap-3">
          <button onClick={onCancel} className="flex-1 rounded-xl border border-slate-200 px-4 py-2.5 text-sm font-bold text-slate-600 hover:bg-slate-50">إلغاء</button>
          <button onClick={onConfirm} className="flex-1 rounded-xl bg-emerald-600 px-4 py-2.5 text-sm font-extrabold text-white hover:bg-emerald-700">حسناً</button>
        </div>
      </div>
    </div>
  )
}

export function ActionModal({ open, type, studentName, onConfirm, onCancel }) {
  const [message, setMessage] = useState('')
  const [minutes, setMinutes] = useState(5)
  useEffect(() => { setMessage(''); setMinutes(5) }, [open, type])
  if (!open) return null
  const titles = { message: 'إرسال رسالة تحذيرية', lock: 'قفل الامتحان', 'reduce-time': 'تقليص الوقت' }
  const colors = { message: 'bg-amber-500 hover:bg-amber-600', lock: 'bg-rose-600 hover:bg-rose-700', 'reduce-time': 'bg-violet-600 hover:bg-violet-700' }
  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/40 backdrop-blur-sm" onClick={onCancel}>
      <div className="mx-4 w-full max-w-md rounded-2xl bg-white p-6 shadow-2xl" onClick={e => e.stopPropagation()}>
        <h3 className="text-lg font-extrabold text-slate-800">{titles[type]}</h3>
        <p className="mt-1 text-sm text-slate-500">الطالب: <span className="font-bold text-slate-700">{studentName}</span></p>
        {type === 'message' && (
          <div className="mt-4">
            <textarea value={message} onChange={e => setMessage(e.target.value)} rows={3} maxLength={500} placeholder="اتقِ الله في امتحانك وركّز..." className="w-full rounded-xl border border-slate-200 bg-slate-50 px-4 py-3 text-sm font-semibold text-slate-800 outline-none focus:border-brand-400 focus:ring-4 focus:ring-brand-500/10" />
          </div>
        )}
        {type === 'reduce-time' && (
          <div className="mt-4 flex gap-2">
            {[1, 3, 5, 10, 15].map(m => (
              <button key={m} onClick={() => setMinutes(m)} className={`rounded-xl px-4 py-2 text-sm font-extrabold ${minutes === m ? 'bg-violet-600 text-white' : 'bg-slate-100 text-slate-600'}`}>{m} د</button>
            ))}
          </div>
        )}
        {type === 'lock' && <div className="mt-4 rounded-xl border border-rose-200 bg-rose-50 px-4 py-3"><p className="text-sm font-bold text-rose-700">⚠ هذا الإجراء نهائي!</p></div>}
        <div className="mt-6 flex gap-3">
          <button onClick={onCancel} className="flex-1 rounded-xl border border-slate-200 px-4 py-2.5 text-sm font-bold text-slate-600">إلغاء</button>
          <button onClick={() => { if (type === 'message' && !message.trim()) return; onConfirm(type === 'message' ? { message } : type === 'reduce-time' ? { minutes } : {}) }} disabled={type === 'message' && !message.trim()} className={`flex-1 rounded-xl px-4 py-2.5 text-sm font-extrabold text-white active:scale-[.98] disabled:opacity-50 ${colors[type]}`}>تأكيد</button>
        </div>
      </div>
    </div>
  )
}

/* ─── Header ─────────────────────────────────────────────── */
function Header({ courses = [], activeExamsCount = 0 }) {
  const { user, logout } = useAuth()
  const { t } = useI18n()
  const navigate = useNavigate()
  const loc = useLocation()
  const p = loc.pathname

  const isInsideCourse = p.includes('/teacher/portal/c/')
  const isDashboardActive = p.includes('/teacher/portal/dashboard') || p === '/teacher/portal' || p === '/teacher/portal/'
  const isCoursesActive = p.includes('/teacher/portal/courses')

  async function handleLogout() { await logout(); navigate('/teacher-login', { replace: true }) }

  const [syncing, setSyncing] = useState(false)
  async function handleSync() {
    setSyncing(true)
    try {
      await api.post('/api/sync/trigger').catch(() => {})
      await api.post('/api/teacher/sync-from-events').catch(() => {})
      window.location.reload()
    } catch {
      // ignore
    } finally {
      setSyncing(false)
    }
  }

  return (
    <header className="sticky top-0 z-30 border-b border-slate-200/70 bg-white/70 backdrop-blur-xl">
      <div className="flex items-center gap-3 px-5 py-3.5 lg:px-8">
        <div className="flex items-center gap-2.5">
          <div className="flex h-9 w-9 items-center justify-center rounded-xl bg-gradient-to-br from-brand-500 to-violet-600 text-white shadow-lg shadow-brand-600/20">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round"><path d="M2 3h6a4 4 0 0 1 4 4v14a3 3 0 0 0-3-3H2z" /><path d="M22 3h-6a4 4 0 0 0-4 4v14a3 3 0 0 1 3-3h7z" /></svg>
          </div>
          <div className="leading-tight">
            <p className="text-sm font-extrabold text-slate-800">{t('teacher.title')}</p>
            <p className="text-[11px] font-semibold text-slate-400">{user?.org_name}</p>
          </div>
        </div>
        <div className="ms-auto flex items-center gap-2">
          <button onClick={handleSync} disabled={syncing} className="hidden items-center gap-1.5 rounded-lg bg-slate-100 px-3 py-1.5 text-xs font-extrabold text-slate-700 hover:bg-slate-200 disabled:opacity-50 sm:flex">
            <svg className={syncing ? "animate-spin" : ""} width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2.5" strokeLinecap="round"><path d="M21 12a9 9 0 0 0-9-9 9.75 9.75 0 0 0-6.74 2.74L3 8"/><path d="M3 3v5h5"/><path d="M3 12a9 9 0 0 0 9 9 9.75 9.75 0 0 0 6.74-2.74L21 16"/><path d="M16 21v-5h5"/></svg>
            مزامنة يدوية
          </button>

          <TeacherNotifications />
          <span className="hidden max-w-[160px] truncate text-sm font-bold text-slate-600 sm:block">{user?.teacher?.fullname || ''}</span>
          <button onClick={handleLogout} title="تسجيل الخروج" className="flex h-8 w-8 cursor-pointer items-center justify-center rounded-lg text-slate-400 hover:bg-rose-50 hover:text-rose-600">
            <svg width="17" height="17" viewBox="0 0 24 24" fill="none"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4m7 14 5-5-5-5m5 5H9" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round" /></svg>
          </button>
        </div>
      </div>

      {!isInsideCourse && (
        <nav className="flex gap-2 overflow-x-auto border-t border-slate-100 px-5 py-2 lg:px-8">
          <Link
            to="/teacher/portal/dashboard"
            className={`flex items-center gap-2 rounded-xl px-4 py-2 text-xs font-extrabold transition-all ${
              isDashboardActive
                ? 'bg-brand-600 text-white shadow-md shadow-brand-600/20'
                : 'bg-slate-100 text-slate-600 hover:bg-slate-200'
            }`}
          >
            <span>🎛️</span>
            <span>لوحة تحكم الامتحان المباشر</span>
            {activeExamsCount > 0 && (
              <span className="relative flex h-2 w-2">
                <span className="absolute inline-flex h-full w-full animate-ping rounded-full bg-emerald-400 opacity-75" />
                <span className="relative inline-flex h-2 w-2 rounded-full bg-emerald-500" />
              </span>
            )}
          </Link>

          <Link
            to="/teacher/portal/courses"
            className={`flex items-center gap-2 rounded-xl px-4 py-2 text-xs font-extrabold transition-all ${
              isCoursesActive
                ? 'bg-brand-600 text-white shadow-md shadow-brand-600/20'
                : 'bg-slate-100 text-slate-600 hover:bg-slate-200'
            }`}
          >
            <span>📚</span>
            <span>مساقاتي الدراسية ({courses.length})</span>
          </Link>
        </nav>
      )}
    </header>
  )
}

/* ─── Shared: Exam Table ─────────────────────────────────── */
function ExamTable({ exams, onRowClick }) {
  if (!Array.isArray(exams) || exams.length === 0) return <Empty text="لا توجد امتحانات بعد" />
  return (
    <div className="overflow-hidden rounded-2xl border border-slate-200/70 bg-white">
      <div className="overflow-x-auto">
        <table className="w-full text-start text-sm">
          <thead>
            <tr className="border-b border-slate-100 bg-slate-50/70 text-xs font-extrabold text-slate-500">
              <th className="px-4 py-3 text-start">الامتحان</th>
              <th className="px-4 py-3 text-start">المساق</th>
              <th className="px-4 py-3 text-start">الحالة</th>
              <th className="px-4 py-3 text-center">الطلاب</th>
              <th className="px-4 py-3 text-center">مشبوهون</th>
              <th className="px-4 py-3 text-center">التهديدات</th>
              <th className="px-4 py-3 text-start">آخر نشاط</th>
            </tr>
          </thead>
          <tbody className="divide-y divide-slate-50">
            {exams.map(e => (
              <tr key={e.id} onClick={() => onRowClick?.(e.id)} className="cursor-pointer transition-colors hover:bg-slate-50/50">
                <td className="px-4 py-3">
                  <p className="font-bold text-slate-700">{e.name}</p>
                  <p className="text-[11px] text-slate-400" dir="ltr">#{e.moodle_quiz_id}</p>
                </td>
                <td className="px-4 py-3 text-slate-600">{e.course_name || '—'}</td>
                <td className="px-4 py-3">
                  <span className={`rounded-full px-2.5 py-0.5 text-xs font-extrabold ring-1 ${e.status === 'active' ? 'bg-emerald-50 text-emerald-700 ring-emerald-200' : 'bg-slate-50 text-slate-500 ring-slate-200'}`}>
                    {e.status === 'active' ? '● نشط' : '○ منتهي'}
                  </span>
                </td>
                <td className="px-4 py-3 text-center font-bold text-slate-700">{e.students_count || 0}</td>
                <td className="px-4 py-3 text-center">
                  {(e.suspicious_count || 0) > 0 ? (
                    <span className="inline-flex items-center gap-1 rounded-full bg-red-50 px-2.5 py-0.5 text-xs font-extrabold text-red-600">
                      <span className="h-1.5 w-1.5 rounded-full bg-red-500 animate-pulse" />{e.suspicious_count}
                    </span>
                  ) : <span className="font-bold text-emerald-600">0</span>}
                </td>
                <td className="px-4 py-3 text-center text-xs font-bold text-slate-600">{e.events_count || 0}</td>
                <td className="px-4 py-3 text-xs text-slate-500">{e.last_event_at || '—'}</td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>
    </div>
  )
}

/* ─── Shared: Student Table ──────────────────────────────── */
export function StudentTable({ students, compact = false, onAction = null }) {
  const navigate = useNavigate()
  if (!Array.isArray(students) || students.length === 0) return <Empty />
  return (
    <div className="overflow-hidden rounded-2xl border border-slate-200/70 bg-white shadow-sm">
      <div className="overflow-x-auto">
        <table className="w-full text-sm">
          <thead>
            <tr className="border-b border-slate-100 bg-slate-50/70 text-xs font-extrabold text-slate-500">
              <th className="px-4 py-3 text-start">الطالب</th>
              {!compact && <th className="px-3 py-3 text-center">الامتحانات</th>}
              <th className="px-3 py-3 text-center">الدرجة</th>
              <th className="px-3 py-3 text-center" title="نسخ/لصق/إخفاء/أدوات">
                <span className="inline-flex items-center gap-1 rounded-md bg-rose-50 px-2 py-1 text-[10px] font-bold text-rose-700 ring-1 ring-rose-200/50">🛡️ سلوكي</span>
              </th>
              <th className="px-3 py-3 text-center" title="مشاركة IP">
                <span className="inline-flex items-center gap-1 rounded-md bg-violet-50 px-2 py-1 text-[10px] font-bold text-violet-700 ring-1 ring-violet-200/50">🌐 شبكة</span>
              </th>
              <th className="px-3 py-3 text-center" title="ذكاء اصطناعي">
                <span className="inline-flex items-center gap-1 rounded-md bg-cyan-50 px-2 py-1 text-[10px] font-bold text-cyan-700 ring-1 ring-cyan-200/50">🤖 AI</span>
              </th>
              <th className="px-3 py-3 text-center" title="تشابه">
                <span className="inline-flex items-center gap-1 rounded-md bg-amber-50 px-2 py-1 text-[10px] font-bold text-amber-700 ring-1 ring-amber-200/50">🔗 تشابه</span>
              </th>
              {!compact && <th className="px-3 py-3 text-center">📋 نسخ</th>}
              {!compact && <th className="px-3 py-3 text-center">📥 لصق</th>}
              {!compact && <th className="px-3 py-3 text-center">👁️ إخفاء</th>}
              {onAction && <th className="px-4 py-3 text-center">إجراءات المدرس</th>}
            </tr>
          </thead>
          <tbody className="divide-y divide-slate-50">
            {students.map(s => (
              <tr key={s.student_id || s.id} onClick={() => navigate(`/teacher/portal/students/${s.student_id || s.id}`)}
                className={`cursor-pointer transition-colors hover:bg-brand-50/30 ${s.risk_level === 'critical' ? 'bg-red-50/30' : s.risk_level === 'high' ? 'bg-orange-50/20' : ''}`}>
                <td className="px-4 py-3">
                  <p className="font-bold text-slate-700">{s.fullname}</p>
                  <p className="text-[11px] text-slate-400">{s.username}</p>
                </td>
                {!compact && <td className="px-3 py-3 text-center text-xs font-bold text-slate-600">{s.exams_count || 0}</td>}
                <td className="px-3 py-3 text-center"><RiskBadge level={s.risk_level} score={s.risk_score || s.max_risk_score} /></td>
                <td className="px-3 py-3 text-center">
                  <span className={`text-xs font-extrabold tabular-nums ${(s.risk_score || 0) >= 60 ? 'text-rose-600' : (s.risk_score || 0) >= 30 ? 'text-amber-600' : 'text-emerald-600'}`}>{s.risk_score || 0}</span>
                </td>
                <td className="px-3 py-3 text-center">
                  {(s.same_ip_student_count > 0 || (s.same_ip > 0)) ? (
                    <span className="inline-flex rounded-full bg-violet-50 px-2 py-0.5 text-[10px] font-bold text-violet-700">
                      {(s.same_ip_student_count || s.same_ip)} طالب
                    </span>
                  ) : <span className="text-[10px] font-bold text-slate-300">—</span>}
                </td>
                <td className="px-3 py-3 text-center">
                  {(s.ai_suspect_score || s.ai_score || 0) >= 50 ? (
                    <span className="inline-flex rounded-full bg-cyan-50 px-2 py-0.5 text-[10px] font-bold text-cyan-700">
                      {s.ai_suspect_score || s.ai_score}%
                    </span>
                  ) : <span className="text-[10px] font-bold text-slate-400">{s.ai_suspect_score || s.ai_score || 0}%</span>}
                </td>
                <td className="px-3 py-3 text-center">
                  {(s.similarity_max_score || s.sim_score || 0) >= 50 ? (
                    <span className="inline-flex rounded-full bg-amber-50 px-2 py-0.5 text-[10px] font-bold text-amber-700">
                      {s.similarity_max_score || s.sim_score}%
                    </span>
                  ) : <span className="text-[10px] font-bold text-slate-400">{s.similarity_max_score || s.sim_score || 0}%</span>}
                </td>
                {!compact && <td className="px-3 py-3 text-center"><span className={`text-xs font-bold ${(s.copy_count || 0) > 0 ? 'text-blue-600' : 'text-slate-300'}`}>{s.copy_count || 0}</span></td>}
                {!compact && <td className="px-3 py-3 text-center"><span className={`text-xs font-bold ${(s.paste_count || 0) > 0 ? 'text-amber-600' : 'text-slate-300'}`}>{s.paste_count || 0}</span></td>}
                {!compact && <td className="px-3 py-3 text-center"><span className={`text-xs font-bold ${(s.tab_hidden_count || 0) > 0 ? 'text-rose-600' : 'text-slate-300'}`}>{s.tab_hidden_count || 0}</span></td>}
                {onAction && (
                  <td className="px-3 py-3 text-center" onClick={e => e.stopPropagation()}>
                    <div className="flex items-center justify-center gap-1.5">
                      <button
                        title="إرسال رسالة تحذيرية تظهر للطالب في الامتحان"
                        onClick={() => onAction('message', s)}
                        className="rounded-lg bg-amber-50 p-1.5 text-xs font-bold text-amber-700 ring-1 ring-amber-200 transition hover:bg-amber-100"
                      >
                        💬 رسالة
                      </button>
                      <button
                        title="تقليص وقت الامتحان للطالب"
                        onClick={() => onAction('reduce-time', s)}
                        className="rounded-lg bg-violet-50 p-1.5 text-xs font-bold text-violet-700 ring-1 ring-violet-200 transition hover:bg-violet-100"
                      >
                        ⏱️ تقليص
                      </button>
                      <button
                        title="قفل الامتحان عن الطالب فوراً"
                        onClick={() => onAction('lock', s)}
                        className="rounded-lg bg-rose-50 p-1.5 text-xs font-bold text-rose-700 ring-1 ring-rose-200 transition hover:bg-rose-100"
                      >
                        🔒 قفل
                      </button>
                    </div>
                  </td>
                )}
              </tr>
            ))}
          </tbody>
        </table>
      </div>
    </div>
  )
}

/* ─── PAGE 2: Exams List ─────────────────────────────────── */
function ExamsList({ courseId: propCourseId }) {
  const params = useParams()
  const courseId = propCourseId || params.courseId
  const [exams, setExams] = useState(null)
  const [q, setQ] = useState('')
  const [filter, setFilter] = useState('all')
  const navigate = useNavigate()

  useEffect(() => {
    const url = '/api/teacher/exams' + (courseId ? `?course_id=${courseId}` : '')
    function load() {
      api.get(url).then(d => setExams(Array.isArray(d) ? d : [])).catch(() => setExams([]))
    }
    load()
    const timer = setInterval(load, 3000)
    return () => clearInterval(timer)
  }, [courseId])

  const filtered = Array.isArray(exams) ? exams.filter(e => {
    if (q && !e.name?.toLowerCase().includes(q.toLowerCase()) && !e.course_name?.toLowerCase().includes(q.toLowerCase())) return false
    if (filter === 'active' && e.status !== 'active') return false
    if (filter === 'ended' && e.status !== 'ended') return false
    return true
  }) : []

  return (
    <div className="space-y-4">
      <div className="flex flex-wrap items-center justify-between gap-3">
        <h2 className="text-lg font-extrabold text-slate-800">الامتحانات ({filtered.length})</h2>
        <div className="flex items-center gap-2">
          <select value={filter} onChange={e => setFilter(e.target.value)} className="rounded-xl border border-slate-200 bg-white px-3 py-2 text-xs font-bold text-slate-600 outline-none">
            <option value="all">الكل</option>
            <option value="active">نشط فقط</option>
            <option value="ended">منتهي فقط</option>
          </select>
          <input value={q} onChange={e => setQ(e.target.value)} placeholder="بحث..." className="rounded-xl border border-slate-200 bg-white px-4 py-2 text-sm font-semibold outline-none focus:border-brand-400 focus:ring-4 focus:ring-brand-500/10" />
        </div>
      </div>
      {exams === null ? <Spinner /> : <ExamTable exams={filtered} onRowClick={(id) => navigate(courseId ? `/teacher/portal/c/${courseId}/exams/${id}` : `/teacher/portal/exams/${id}`)} />}
    </div>
  )
}

/* ─── PAGE 3: Courses List (6-Card Grid + Pagination + Pastel Palettes) ─── */
const COURSE_CARD_PALETTES = [
  {
    bg: 'bg-gradient-to-br from-indigo-500/10 via-white to-violet-500/10 border-indigo-200/80 hover:border-indigo-400',
    headerBadge: 'bg-indigo-600 text-white',
    studentBadge: 'bg-indigo-50 text-indigo-700 ring-indigo-200',
    examBadge: 'bg-violet-50 text-violet-700 ring-violet-200',
    btn: 'bg-indigo-600 hover:bg-indigo-700 text-white shadow-indigo-600/20',
    icon: '📚',
    accentLine: 'bg-gradient-to-r from-indigo-500 to-violet-600'
  },
  {
    bg: 'bg-gradient-to-br from-emerald-500/10 via-white to-teal-500/10 border-emerald-200/80 hover:border-emerald-400',
    headerBadge: 'bg-emerald-600 text-white',
    studentBadge: 'bg-emerald-50 text-emerald-700 ring-emerald-200',
    examBadge: 'bg-teal-50 text-teal-700 ring-teal-200',
    btn: 'bg-emerald-600 hover:bg-emerald-700 text-white shadow-emerald-600/20',
    icon: '🧬',
    accentLine: 'bg-gradient-to-r from-emerald-500 to-teal-600'
  },
  {
    bg: 'bg-gradient-to-br from-amber-500/10 via-white to-orange-500/10 border-amber-200/80 hover:border-amber-400',
    headerBadge: 'bg-amber-600 text-white',
    studentBadge: 'bg-amber-50 text-amber-700 ring-amber-200',
    examBadge: 'bg-orange-50 text-orange-700 ring-orange-200',
    btn: 'bg-amber-600 hover:bg-amber-700 text-white shadow-amber-600/20',
    icon: '💡',
    accentLine: 'bg-gradient-to-r from-amber-500 to-orange-600'
  },
  {
    bg: 'bg-gradient-to-br from-rose-500/10 via-white to-pink-500/10 border-rose-200/80 hover:border-rose-400',
    headerBadge: 'bg-rose-600 text-white',
    studentBadge: 'bg-rose-50 text-rose-700 ring-rose-200',
    examBadge: 'bg-pink-50 text-pink-700 ring-pink-200',
    btn: 'bg-rose-600 hover:bg-rose-700 text-white shadow-rose-600/20',
    icon: '⚙️',
    accentLine: 'bg-gradient-to-r from-rose-500 to-pink-600'
  },
  {
    bg: 'bg-gradient-to-br from-sky-500/10 via-white to-blue-500/10 border-sky-200/80 hover:border-sky-400',
    headerBadge: 'bg-sky-600 text-white',
    studentBadge: 'bg-sky-50 text-sky-700 ring-sky-200',
    examBadge: 'bg-blue-50 text-blue-700 ring-blue-200',
    btn: 'bg-sky-600 hover:bg-sky-700 text-white shadow-sky-600/20',
    icon: '🌐',
    accentLine: 'bg-gradient-to-r from-sky-500 to-blue-600'
  },
  {
    bg: 'bg-gradient-to-br from-purple-500/10 via-white to-fuchsia-500/10 border-purple-200/80 hover:border-purple-400',
    headerBadge: 'bg-purple-600 text-white',
    studentBadge: 'bg-purple-50 text-purple-700 ring-purple-200',
    examBadge: 'bg-fuchsia-50 text-fuchsia-700 ring-fuchsia-200',
    btn: 'bg-purple-600 hover:bg-purple-700 text-white shadow-purple-600/20',
    icon: '🎓',
    accentLine: 'bg-gradient-to-r from-purple-500 to-fuchsia-600'
  },
]

function CoursesList({ courses: propCourses }) {
  const [fetchedCourses, setFetchedCourses] = useState(null)
  const [page, setPage] = useState(1)
  const navigate = useNavigate()
  const { user } = useAuth()

  useEffect(() => {
    if (!propCourses) {
      function load() {
        api.get('/api/teacher/courses').then(d => setFetchedCourses(Array.isArray(d) ? d : [])).catch(() => setFetchedCourses([]))
      }
      load()
      const timer = setInterval(load, 3000)
      return () => clearInterval(timer)
    }
  }, [propCourses])

  const courses = propCourses || fetchedCourses

  const PAGE_SIZE = 6
  const totalCourses = courses?.length || 0
  const totalPages = Math.max(1, Math.ceil(totalCourses / PAGE_SIZE))
  const paginatedCourses = courses ? courses.slice((page - 1) * PAGE_SIZE, page * PAGE_SIZE) : []

  return (
    <div className="space-y-6">
      {/* Header Banner */}
      <div className="flex flex-wrap items-center justify-between gap-3 rounded-3xl border border-brand-200/60 bg-gradient-to-br from-brand-50/60 via-white to-violet-50/40 p-6 shadow-sm">
        <div>
          <div className="flex items-center gap-2">
            <span className="text-2xl">👋</span>
            <h1 className="text-xl font-black text-slate-800">
              مرحباً د. {user?.teacher?.fullname || 'المعلم'} — مساقاتي الدراسية
            </h1>
          </div>
          <p className="mt-1 text-xs font-semibold text-slate-500">
            اختر المساق لعرض الامتحانات والطلاب والتحليلات وتقارير الغش الخاصة به.
          </p>
        </div>
        <div className="flex items-center gap-2 rounded-2xl bg-white px-4 py-2.5 text-xs font-extrabold text-slate-700 shadow-sm ring-1 ring-slate-200">
          <span>إجمالي المساقات:</span>
          <span className="rounded-full bg-brand-600 px-2.5 py-0.5 text-white">{totalCourses}</span>
        </div>
      </div>

      {courses === null ? <Spinner /> : courses.length === 0 ? <Empty text="لا توجد مساقات مرتبطة بعد" /> : (
        <div className="space-y-6">
          {/* Grid of 6 Cards (3x2 responsive) */}
          <div className="grid gap-5 grid-cols-1 md:grid-cols-2 lg:grid-cols-3">
            {paginatedCourses.map((c, idx) => {
              const paletteIndex = ((page - 1) * PAGE_SIZE + idx) % COURSE_CARD_PALETTES.length
              const palette = COURSE_CARD_PALETTES[paletteIndex]
              const courseTargetId = c.moodle_course_id || c.id

              return (
                <div
                  key={c.id}
                  onClick={() => navigate(`/teacher/portal/c/${courseTargetId}`)}
                  className={`group relative flex flex-col justify-between overflow-hidden rounded-3xl border ${palette.border} ${palette.bg} p-6 shadow-sm transition-all duration-300 hover:-translate-y-1 hover:shadow-xl cursor-pointer`}
                >
                  <div className={`absolute inset-x-0 top-0 h-1.5 ${palette.accentLine}`} />
                  
                  <div>
                    {/* Top Row: Icon + Course Code Badge */}
                    <div className="flex items-center justify-between">
                      <span className="flex h-11 w-11 items-center justify-center rounded-2xl bg-white text-xl shadow-sm ring-1 ring-slate-100">
                        {palette.icon}
                      </span>
                      <span className={`rounded-full px-3 py-1 text-[11px] font-extrabold shadow-sm ${palette.headerBadge}`}>
                        #{courseTargetId}
                      </span>
                    </div>

                    {/* Course Title */}
                    <h3 className="mt-4 text-lg font-extrabold leading-snug text-slate-800 transition-colors group-hover:text-brand-600">
                      {c.name}
                    </h3>

                    {/* Stats Badges */}
                    <div className="mt-4 flex flex-wrap gap-2">
                      <span className={`inline-flex items-center gap-1 rounded-xl px-3 py-1.5 text-xs font-extrabold ring-1 ${palette.examBadge}`}>
                        <span>📝</span>
                        <span>{c.exams_count || 0} امتحانات</span>
                      </span>
                      <span className={`inline-flex items-center gap-1 rounded-xl px-3 py-1.5 text-xs font-extrabold ring-1 ${palette.studentBadge}`}>
                        <span>👥</span>
                        <span>{c.students_count || 0} طالب</span>
                      </span>
                    </div>

                    {/* Teachers List */}
                    {c.teachers?.length > 0 && (
                      <div className="mt-4 flex flex-wrap gap-1.5 border-t border-slate-100 pt-3">
                        {c.teachers.map(tt => (
                          <span key={tt.teacher_id} className={`rounded-full px-2.5 py-0.5 text-[10px] font-bold ring-1 ${tt.is_me ? 'bg-brand-50 text-brand-700 ring-brand-200' : 'bg-slate-100 text-slate-600 ring-slate-200'}`}>
                            {tt.fullname} {tt.is_me ? '(أنت)' : ''}
                          </span>
                        ))}
                      </div>
                    )}
                  </div>

                  {/* Primary CTA */}
                  <div className="mt-6 pt-2">
                    <button className={`w-full rounded-2xl py-3 text-xs font-extrabold transition-all duration-200 shadow-md flex items-center justify-center gap-2 group-hover:scale-[1.02] ${palette.btn}`}>
                      <span>دخول المساق</span>
                      <span className="transition-transform group-hover:translate-x-[-4px]">➔</span>
                    </button>
                  </div>
                </div>
              )
            })}
          </div>

          {/* Pagination Controls */}
          {totalPages > 1 && (
            <div className="flex items-center justify-between rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
              <button
                disabled={page <= 1}
                onClick={() => setPage(p => Math.max(1, p - 1))}
                className="rounded-xl border border-slate-200 px-4 py-2 text-xs font-extrabold text-slate-600 hover:bg-slate-50 disabled:opacity-40"
              >
                السابق
              </button>
              <span className="text-xs font-extrabold text-slate-600">
                صفحة {page} من {totalPages}
              </span>
              <button
                disabled={page >= totalPages}
                onClick={() => setPage(p => Math.min(totalPages, p + 1))}
                className="rounded-xl border border-slate-200 px-4 py-2 text-xs font-extrabold text-slate-600 hover:bg-slate-50 disabled:opacity-40"
              >
                التالي
              </button>
            </div>
          )}
        </div>
      )}
    </div>
  )
}

/* ─── PAGE 4: Students List ──────────────────────────────── */
function StudentsList({ courseId: propCourseId }) {
  const params = useParams()
  const courseId = propCourseId || params.courseId
  const [data, setData] = useState(null)
  const [q, setQ] = useState('')
  const [sort, setSort] = useState('risk_desc')
  const [risk, setRisk] = useState('all')

  useEffect(() => {
    const url = '/api/teacher/students' + (courseId ? `?course_id=${courseId}` : '')
    function load() {
      api.get(url).then(setData).catch(() => { if (!data) setData({ students: [], totals: {} }) })
    }
    load()
    const timer = setInterval(load, 4000)
    return () => clearInterval(timer)
  }, [courseId])

  const students = useMemo(() => {
    let list = data?.students || []
    if (q) list = list.filter(s => s.fullname?.toLowerCase().includes(q.toLowerCase()) || s.username?.toLowerCase().includes(q.toLowerCase()))
    if (risk !== 'all') list = list.filter(s => s.risk_level === risk)
    list = [...list].sort((a, b) => {
      if (sort === 'risk_asc') return (a.risk_score || 0) - (b.risk_score || 0)
      if (sort === 'name') return (a.fullname || '').localeCompare(b.fullname || '', 'ar')
      if (sort === 'events') return (b.total_events || 0) - (a.total_events || 0)
      if (sort === 'ai') return (b.ai_suspect_score || 0) - (a.ai_suspect_score || 0)
      return (b.risk_score || 0) - (a.risk_score || 0)
    })
    return list
  }, [data, q, sort, risk])

  const totals = data?.totals || {}

  return (
    <div className="space-y-6">
      <div className="grid grid-cols-2 gap-4 lg:grid-cols-5">
        <StatCard value={totals.total_students || 0} label="إجمالي الطلاب" tone="text-blue-600" />
        <StatCard value={totals.high_risk || 0} label="عالي الخطورة" tone={totals.high_risk > 0 ? 'text-rose-600' : 'text-emerald-600'} />
        <StatCard value={totals.ai_flagged || 0} label="مشبوه AI" tone={totals.ai_flagged > 0 ? 'text-cyan-600' : 'text-slate-600'} />
        <StatCard value={totals.network_flagged || 0} label="تشارك IP" tone={totals.network_flagged > 0 ? 'text-violet-600' : 'text-slate-600'} />
        <StatCard value={totals.sim_flagged || 0} label="تشابه عالي" tone={totals.sim_flagged > 0 ? 'text-amber-600' : 'text-slate-600'} />
      </div>

      <div className="flex flex-wrap items-center justify-between gap-3">
        <h2 className="text-lg font-extrabold text-slate-800">الطلاب ({students.length})</h2>
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

      {data === null ? <Spinner /> : <StudentTable students={students} />}
    </div>
  )
}

/* ─── SUB-PAGE: Exam Detail ──────────────────────────────── */
function ExamDetail() {
  const { id } = useParams()
  const [data, setData] = useState(null)
  const [students, setStudents] = useState(null)
  const [sortBy, setSortBy] = useState('risk_desc')
  const [actionModal, setActionModal] = useState({ open: false, type: '', student: null })
  const [confirmModal, setConfirmModal] = useState({ open: false, title: '', message: '' })

  useEffect(() => {
    function load() {
      api.get(`/api/teacher/exams/${id}`).then(setData).catch(() => {})
      api.get(`/api/teacher/exams/${id}/students`).then(r => {
        const list = Array.isArray(r?.students) ? r.students : Array.isArray(r) ? r : []
        setStudents(list)
      }).catch(() => setStudents([]))
    }
    load()
    const timer = setInterval(load, 3000)
    return () => clearInterval(timer)
  }, [id])

  async function handleAction(type, params) {
    setActionModal({ open: false, type: '', student: null })
    try {
      const endpoint = type === 'message' ? 'message' : type === 'lock' ? 'lock' : 'reduce-time'
      const sid = actionModal.student?.student_id || actionModal.student?.id || actionModal.student?.moodle_user_id || 0
      const ssid = actionModal.student?.session_summary_id || 0
      await api.post(`/api/teacher/actions/${endpoint}`, { exam_id: parseInt(id), session_summary_id: ssid, student_id: sid, ...params })
      setConfirmModal({ open: true, title: 'تم بنجاح', message: type === 'message' ? 'تم إرسال الرسالة وسيتلقاها الطالب في الامتحان فوراً.' : type === 'lock' ? 'تم قفل الامتحان عن الطالب فوراً.' : `تم تقليص الوقت بـ ${params.minutes || 5} دقائق.` })
    } catch (e) {
      setConfirmModal({ open: true, title: 'تنبيه', message: e.message || 'تعذر إرسال الإجراء' })
    }
  }

  const sorted = useMemo(() => {
    let list = [...(students || [])]
    list.sort((a, b) => {
      if (sortBy === 'risk_asc') return (a.risk_score || 0) - (b.risk_score || 0)
      if (sortBy === 'name') return (a.fullname || '').localeCompare(b.fullname || '', 'ar')
      return (b.risk_score || 0) - (a.risk_score || 0)
    })
    return list
  }, [students, sortBy])

  if (!data) return <Spinner />
  const counts = data.counts || {}

  return (
    <div className="space-y-6">
      <div className="flex flex-wrap items-center justify-between gap-3">
        <div>
          <Link to=".." className="text-xs font-extrabold text-slate-400 hover:text-slate-600">← العودة للامتحانات</Link>
          <h2 className="mt-1 text-xl font-extrabold text-slate-800">{data.exam?.name}</h2>
          <p className="text-xs text-slate-400">{data.course?.name || '—'} · #{data.exam?.moodle_quiz_id}</p>
        </div>
        <span className={`rounded-full px-3 py-1 text-xs font-extrabold ring-1 ${data.exam?.status === 'active' ? 'bg-emerald-50 text-emerald-700 ring-emerald-200' : 'bg-slate-50 text-slate-500 ring-slate-200'}`}>
          {data.exam?.status === 'active' ? '● نشط' : '○ منتهي'}
        </span>
      </div>

      <div className="grid grid-cols-2 gap-4 lg:grid-cols-4">
        <StatCard value={counts.students || 0} label="الطلاب" />
        <StatCard value={counts.sessions || 0} label="الجلسات" tone="text-brand-600" />
        <StatCard value={counts.events || 0} label="التهديدات" tone="text-violet-600" />
        <StatCard value={counts.suspicious || 0} label="مشبوهون" tone={counts.suspicious > 0 ? 'text-rose-600' : 'text-emerald-600'} />
      </div>

      <section>
        <div className="mb-3 flex flex-wrap items-center justify-between gap-3">
          <h3 className="text-sm font-extrabold text-slate-700">الطلاب ({sorted.length})</h3>
          <select value={sortBy} onChange={e => setSortBy(e.target.value)} className="rounded-xl border border-slate-200 bg-white px-3 py-1.5 text-xs font-bold text-slate-600 outline-none">
            <option value="risk_desc">خطورة ↓</option>
            <option value="risk_asc">خطورة ↑</option>
            <option value="name">الاسم</option>
          </select>
        </div>
        {students === null ? <Spinner /> : <StudentTable students={sorted} onAction={(type, student) => setActionModal({ open: true, type, student })} />}
      </section>

      <ActionModal open={actionModal.open} type={actionModal.type} studentName={actionModal.student?.fullname || ''} onConfirm={p => handleAction(actionModal.type, p)} onCancel={() => setActionModal({ open: false, type: '', student: null })} />
      <ConfirmModal open={confirmModal.open} title={confirmModal.title} message={confirmModal.message} onConfirm={() => setConfirmModal({ open: false })} onCancel={() => setConfirmModal({ open: false })} />
    </div>
  )
}

/* ─── Shared: Course Student Table ────────────────────────── */
function CourseStudentTable({ students }) {
  const navigate = useNavigate()
  if (!Array.isArray(students) || students.length === 0) return <Empty text="لا يوجد طلاب مسجلين في هذا المساق" />
  return (
    <div className="overflow-hidden rounded-2xl border border-slate-200/70 bg-white">
      <div className="overflow-x-auto">
        <table className="w-full text-sm">
          <thead>
            <tr className="border-b border-slate-100 bg-slate-50/70 text-xs font-extrabold text-slate-500">
              <th className="px-4 py-3 text-start">الطالب</th>
              <th className="px-3 py-3 text-center">الامتحانات المنجزة</th>
              <th className="px-3 py-3 text-center">أعلى خطورة</th>
              <th className="px-3 py-3 text-start">الحالة</th>
            </tr>
          </thead>
          <tbody className="divide-y divide-slate-50">
            {students.map(s => {
              const participated = s.exams_count > 0
              return (
                <tr key={s.student_id || s.id} 
                  onClick={() => navigate(`/teacher/portal/students/${s.student_id || s.id}`)}
                  className={`cursor-pointer transition-colors hover:bg-brand-50/30 ${participated ? '' : 'bg-slate-50/50'}`}>
                  <td className="px-4 py-3">
                    <p className="font-bold text-slate-700">{s.fullname}</p>
                    <p className="text-[11px] text-slate-400">{s.username}</p>
                  </td>
                  <td className="px-3 py-3 text-center font-bold text-slate-600">{s.exams_count}</td>
                  <td className="px-3 py-3 text-center">
                    {participated ? <RiskBadge level={s.risk_level} score={s.risk_score} /> : <span className="text-slate-300">—</span>}
                  </td>
                  <td className="px-3 py-3 text-start">
                    {participated ? (
                      <span className="inline-flex rounded-full bg-emerald-50 px-2 py-0.5 text-[10px] font-bold text-emerald-700">مشارك</span>
                    ) : (
                      <span className="inline-flex rounded-full bg-slate-200/50 px-2 py-0.5 text-[10px] font-bold text-slate-500">لم يقدم اختبار بعد</span>
                    )}
                  </td>
                </tr>
              )
            })}
          </tbody>
        </table>
      </div>
    </div>
  )
}

/* ─── SUB-PAGE: Course Detail ────────────────────────────── */
function CourseDetail() {
  const { id } = useParams()
  const [data, setData] = useState(null)
  const navigate = useNavigate()

  useEffect(() => {
    function load() {
      api.get(`/api/teacher/courses/${id}`).then(setData).catch(() => {})
    }
    load()
    const timer = setInterval(load, 4000)
    return () => clearInterval(timer)
  }, [id])

  if (!data) return <Spinner />

  return (
    <div className="space-y-6">
      <div>
        <Link to="/teacher/portal/courses" className="text-xs font-extrabold text-slate-400 hover:text-slate-600">← العودة للمساقات</Link>
        <h2 className="mt-1 text-xl font-extrabold text-slate-800">{data.course?.name}</h2>
      </div>

      {data.teachers?.length > 0 && (
        <div className="flex flex-wrap gap-2">
          {data.teachers.map(t => (
            <span key={t.teacher_id} className="rounded-full bg-slate-100 px-3 py-1 text-xs font-bold text-slate-600">{t.fullname}</span>
          ))}
        </div>
      )}

      <section>
        <h3 className="mb-3 text-sm font-extrabold text-slate-700">امتحانات المساق ({data.exams?.length || 0})</h3>
        <ExamTable exams={data.exams || []} onRowClick={(eid) => navigate(`/teacher/portal/exams/${eid}`)} />
      </section>

      <section>
        <h3 className="mb-3 text-sm font-extrabold text-slate-700">طلاب المساق ({data.students?.length || 0})</h3>
        <CourseStudentTable students={data.students || []} />
      </section>
    </div>
  )
}

/* ─── SUB-PAGE: Student Detail ───────────────────────────── */
function StudentDetail() {
  const { id } = useParams()
  const [data, setData] = useState(null)
  const navigate = useNavigate()

  useEffect(() => {
    function load() {
      api.get(`/api/teacher/students/${id}`).then(setData).catch(() => {})
    }
    load()
    const timer = setInterval(load, 3000)
    return () => clearInterval(timer)
  }, [id])

  if (!data) return <Spinner />

  const s = data.student || {}
  const agg = data.aggregates || {}
  const sessions = data.sessions || []
  const answers = data.answers || []

  return (
    <div className="space-y-6">
      <div className="flex flex-wrap items-center justify-between gap-3">
        <div>
          <Link to=".." className="text-xs font-extrabold text-slate-400 hover:text-slate-600">← العودة للطلاب</Link>
          <h2 className="mt-1 text-xl font-extrabold text-slate-800">{s.fullname}</h2>
          <p className="text-xs text-slate-400">{s.username} · #{s.moodle_user_id}</p>
        </div>
        <button
          onClick={async () => {
            if (window.confirm(`هل أنت متأكد من حذف بيانات الطالب "${s.fullname}" نهائياً من لوحة التحكم؟`)) {
              try {
                await api.post(`/api/teacher/students/${id}/delete`, {})
                navigate('..')
              } catch (e) {
                alert('تعذر حذف بيانات الطالب')
              }
            }
          }}
          className="rounded-xl border border-rose-200 bg-rose-50 px-3 py-2 text-xs font-extrabold text-rose-700 hover:bg-rose-100 transition-colors"
        >
          🗑️ حذف بيانات الطالب من المنصة
        </button>
      </div>

      <div className="grid grid-cols-1 gap-6 md:grid-cols-2 lg:grid-cols-4">
        <div className="rounded-3xl border border-rose-200/50 bg-gradient-to-br from-rose-50 to-white p-5 shadow-sm ring-1 ring-rose-100/50 relative overflow-hidden group">
          <div className="absolute -right-4 -top-4 h-24 w-24 rounded-full bg-rose-500/5 transition-transform duration-500 group-hover:scale-150" />
          <div className="relative mb-4 flex items-center justify-between">
            <h3 className="text-sm font-extrabold text-rose-800">السلوكي (Behavioral)</h3>
            <span className="flex h-8 w-8 items-center justify-center rounded-xl bg-rose-100/80 text-rose-600 shadow-sm">🛡️</span>
          </div>
          <div className="relative space-y-3">
            <div className="flex justify-between items-center text-sm"><span className="font-bold text-slate-600">تهديدات إجمالية</span><span className="font-extrabold text-rose-600">{agg.total_events || 0}</span></div>
            <div className="flex justify-between items-center text-sm"><span className="text-slate-500">إخفاء الشاشة</span><span className="font-bold text-slate-700">{agg.total_tab_hidden || 0}</span></div>
            <div className="flex justify-between items-center text-sm"><span className="text-slate-500">نسخ / لصق</span><span className="font-bold text-slate-700">{(agg.total_copy || 0) + (agg.total_paste || 0)}</span></div>
            <div className="flex justify-between items-center text-sm"><span className="text-slate-500">أدوات التطوير</span><span className="font-bold text-slate-700">{agg.total_devtools || 0}</span></div>
          </div>
        </div>

        <div className="rounded-3xl border border-cyan-200/50 bg-gradient-to-br from-cyan-50 to-white p-5 shadow-sm ring-1 ring-cyan-100/50 relative overflow-hidden group">
          <div className="absolute -right-4 -top-4 h-24 w-24 rounded-full bg-cyan-500/5 transition-transform duration-500 group-hover:scale-150" />
          <div className="relative mb-4 flex items-center justify-between">
            <h3 className="text-sm font-extrabold text-cyan-800">الذكاء الاصطناعي (AI)</h3>
            <span className="flex h-8 w-8 items-center justify-center rounded-xl bg-cyan-100/80 text-cyan-600 shadow-sm">🤖</span>
          </div>
          <div className="relative flex flex-col items-center justify-center py-2">
            <span className={`text-5xl font-black tracking-tight ${(agg.max_ai || 0) >= 50 ? 'text-cyan-600' : 'text-slate-400'}`}>
              {agg.max_ai || 0}<span className="text-2xl">%</span>
            </span>
            <span className="mt-3 text-xs font-bold text-slate-500 text-center">أعلى نسبة احتمالية استخدام أدوات الذكاء الاصطناعي في الإجابات</span>
          </div>
        </div>

        <div className="rounded-3xl border border-amber-200/50 bg-gradient-to-br from-amber-50 to-white p-5 shadow-sm ring-1 ring-amber-100/50 relative overflow-hidden group">
          <div className="absolute -right-4 -top-4 h-24 w-24 rounded-full bg-amber-500/5 transition-transform duration-500 group-hover:scale-150" />
          <div className="relative mb-4 flex items-center justify-between">
            <h3 className="text-sm font-extrabold text-amber-800">التشابه (Similarity)</h3>
            <span className="flex h-8 w-8 items-center justify-center rounded-xl bg-amber-100/80 text-amber-600 shadow-sm">🔗</span>
          </div>
          <div className="relative flex flex-col items-center justify-center py-2">
            <span className={`text-5xl font-black tracking-tight ${(agg.max_similarity || 0) >= 50 ? 'text-amber-600' : 'text-slate-400'}`}>
              {agg.max_similarity || 0}<span className="text-2xl">%</span>
            </span>
            <span className="mt-3 text-xs font-bold text-slate-500 text-center">أعلى نسبة تطابق إجابات مع إجابات الزملاء</span>
          </div>
        </div>

        <div className="rounded-3xl border border-violet-200/50 bg-gradient-to-br from-violet-50 to-white p-5 shadow-sm ring-1 ring-violet-100/50 relative overflow-hidden group">
          <div className="absolute -right-4 -top-4 h-24 w-24 rounded-full bg-violet-500/5 transition-transform duration-500 group-hover:scale-150" />
          <div className="relative mb-4 flex items-center justify-between">
            <h3 className="text-sm font-extrabold text-violet-800">الشبكة والأجهزة (Network)</h3>
            <span className="flex h-8 w-8 items-center justify-center rounded-xl bg-violet-100/80 text-violet-600 shadow-sm">🌐</span>
          </div>
          <div className="relative space-y-4">
            <div className="flex flex-col text-sm pt-1">
              <span className="text-xs text-slate-500 font-bold mb-1">آخر IP مستخدم:</span>
              <span className="font-mono text-sm font-extrabold text-slate-700" dir="ltr">{s.last_ip || (sessions[0]?.ip_address || 'غير متوفر')}</span>
            </div>
            <div className="flex justify-between items-center text-sm pt-3 border-t border-violet-200/60">
              <span className="text-slate-600 text-xs font-extrabold">مشاركة الـ IP مع طلاب آخرين:</span>
              <span className={`px-2 py-1 rounded-md font-extrabold text-xs ${agg.max_ip_group > 0 ? 'bg-violet-100 text-violet-700' : 'bg-emerald-50 text-emerald-600'}`}>
                {agg.max_ip_group > 0 ? `${agg.max_ip_group} طلاب` : 'لا يوجد'}
              </span>
            </div>
          </div>
        </div>
      </div>

      {sessions.length > 0 && (
        <section>
          <h3 className="mb-3 text-sm font-extrabold text-slate-700">الجلسات ({sessions.length})</h3>
          <div className="overflow-hidden rounded-2xl border border-slate-200/70 bg-white">
            <table className="w-full text-sm">
              <thead>
                <tr className="border-b border-slate-100 bg-slate-50/70 text-xs font-extrabold text-slate-500">
                  <th className="px-4 py-3 text-start">الامتحان</th>
                  <th className="px-4 py-3 text-start">المساق</th>
                  <th className="px-4 py-3 text-center">التهديدات</th>
                  <th className="px-3 py-3 text-center">الدرجة</th>
                  <th className="px-3 py-3 text-center">🤖</th>
                  <th className="px-3 py-3 text-center">🌐</th>
                  <th className="px-3 py-3 text-center">🔗</th>
                  <th className="px-4 py-3 text-start">الوقت</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-slate-50">
                {sessions.map(ss => (
                  <tr key={ss.session_id} className="hover:bg-slate-50/50">
                    <td className="px-4 py-3 font-bold text-slate-700">{ss.exam_name}</td>
                    <td className="px-4 py-3 text-slate-500 text-xs">{ss.course_name || '—'}</td>
                    <td className="px-4 py-3 text-center font-bold">{ss.event_count}</td>
                    <td className="px-3 py-3 text-center"><RiskBadge level={ss.risk_level} score={ss.risk_score} /></td>
                    <td className="px-3 py-3 text-center text-xs font-bold">{ss.ai_suspect_score}%</td>
                    <td className="px-3 py-3 text-center text-xs font-bold">{ss.same_ip_student_count > 0 ? `${ss.same_ip_student_count} ط` : '—'}</td>
                    <td className="px-3 py-3 text-center text-xs font-bold">{ss.similarity_max_score}%</td>
                    <td className="px-4 py-3 text-xs text-slate-500">{ss.started_at || '—'}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        </section>
      )}

      {answers.length > 0 && (
        <section>
          <h3 className="mb-3 text-sm font-extrabold text-slate-700">آخر الإجابات ({answers.length})</h3>
          <div className="overflow-hidden rounded-2xl border border-slate-200/70 bg-white">
            <table className="w-full text-sm">
              <thead>
                <tr className="border-b border-slate-100 bg-slate-50/70 text-xs font-extrabold text-slate-500">
                  <th className="px-4 py-3 text-start">السؤال</th>
                  <th className="px-4 py-3 text-start">الامتحان</th>
                  <th className="px-3 py-3 text-center">الطول</th>
                  <th className="px-3 py-3 text-center">🤖 AI</th>
                  <th className="px-4 py-3 text-start">التاريخ</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-slate-50">
                {answers.map((a, i) => (
                  <tr key={i} className="hover:bg-slate-50/50">
                    <td className="px-4 py-3">
                      <p className="font-bold text-slate-700 text-xs">{a.question_id}</p>
                      {a.answer_text && <p className="mt-0.5 text-[11px] text-slate-400 truncate max-w-xs">{a.answer_text.slice(0, 80)}</p>}
                    </td>
                    <td className="px-4 py-3 text-xs text-slate-500">{a.exam_name}</td>
                    <td className="px-3 py-3 text-center text-xs font-bold">{a.word_count || 0} كلمة</td>
                    <td className="px-3 py-3 text-center">
                      {(a.ai_score || 0) >= 50 ? <span className="inline-flex rounded-full bg-cyan-50 px-2 py-0.5 text-[10px] font-bold text-cyan-700">{a.ai_score}%</span> : <span className="text-[10px] font-bold text-slate-400">{a.ai_score || 0}%</span>}
                    </td>
                    <td className="px-4 py-3 text-xs text-slate-500">{a.created_at || '—'}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        </section>
      )}
    </div>
  )
}

/* ─── Course Workspace Header & Container (No "المساقات" Tab) ──── */
function CourseWorkspaceHeader({ courses, activeCourseId }) {
  const navigate = useNavigate()
  const location = useLocation()
  const activeCourse = courses?.find(c => String(c.moodle_course_id) === String(activeCourseId) || String(c.id) === String(activeCourseId))

  const path = location.pathname
  const examMatch = path.match(/\/exams\/(\d+)/)
  const examId = examMatch ? examMatch[1] : null

  const currentSubTab = 
    path.includes('/network') ? 'network'
    : path.includes('/devices') ? 'devices'
    : path.includes('/similarity') ? 'similarity'
    : path.includes('/students') ? 'students'
    : path.includes('/exams') ? 'exams'
    : 'dashboard'

  // If inside an exam, show exam-specific sub-tabs. If on course level, show course tabs (Exams & Course Students).
  const tabs = examId ? [
    { key: 'dashboard', label: 'لوحة التحكم', to: `/teacher/portal/c/${activeCourseId}/exams/${examId}` },
    { key: 'students', label: 'طلاب الامتحان', to: `/teacher/portal/c/${activeCourseId}/exams/${examId}/students` },
    { key: 'network', label: 'الشبكات', to: `/teacher/portal/c/${activeCourseId}/exams/${examId}/network` },
    { key: 'devices', label: 'الأجهزة', to: `/teacher/portal/c/${activeCourseId}/exams/${examId}/devices` },
    { key: 'similarity', label: 'التشابه', to: `/teacher/portal/c/${activeCourseId}/exams/${examId}/similarity` },
  ] : [
    { key: 'exams', label: 'الامتحانات', to: `/teacher/portal/c/${activeCourseId}/exams` },
    { key: 'students', label: 'طلاب المساق', to: `/teacher/portal/c/${activeCourseId}/students` },
  ]

  return (
    <div className="mb-6 space-y-4">
      <div className="flex flex-wrap items-center justify-between gap-3 rounded-2xl border border-brand-200/70 bg-gradient-to-r from-brand-50/80 via-white to-violet-50/50 p-4 shadow-sm">
        <div className="flex items-center gap-3">
          <Link to="/teacher/portal/courses" className="flex items-center gap-1.5 rounded-xl border border-slate-200 bg-white px-3.5 py-2 text-xs font-extrabold text-slate-700 shadow-sm transition-all hover:bg-slate-50 hover:text-brand-600 active:scale-[0.98]">
            <span>←</span>
            <span>مساقاتي</span>
          </Link>
          <div className="h-6 w-px bg-slate-200" />
          <div className="flex items-center gap-2.5">
            <span className="flex h-9 w-9 items-center justify-center rounded-xl bg-brand-600 text-base font-extrabold text-white shadow-md">📚</span>
            <div>
              <p className="text-[11px] font-extrabold text-brand-600">المساق النشط الحالي</p>
              <h2 className="text-base font-extrabold text-slate-800">{activeCourse?.name || `مساق #${activeCourseId}`}</h2>
            </div>
          </div>
        </div>

        {courses?.length > 1 && (
          <div className="flex items-center gap-2">
            <span className="text-xs font-bold text-slate-500">تبديل المساق:</span>
            <select
              value={activeCourseId}
              onChange={(e) => {
                const newCourseId = e.target.value
                const subPath = currentSubTab === 'dashboard' ? '' : `/${currentSubTab}`
                navigate(`/teacher/portal/c/${newCourseId}${subPath}`)
              }}
              className="rounded-xl border border-slate-200 bg-white px-3 py-2 text-xs font-extrabold text-slate-700 outline-none focus:border-brand-500 shadow-sm"
            >
              {courses.map(c => (
                <option key={c.id} value={c.moodle_course_id || c.id}>
                  {c.name}
                </option>
              ))}
            </select>
          </div>
        )}
      </div>

      <nav className="flex gap-1.5 overflow-x-auto border-b border-slate-200 pb-2">
        {tabs.map(tab => {
          const isActive = currentSubTab === tab.key
          return (
            <Link
              key={tab.key}
              to={tab.to}
              className={`whitespace-nowrap rounded-xl px-4 py-2.5 text-xs font-extrabold transition-all ${
                isActive
                  ? 'bg-brand-600 text-white shadow-md shadow-brand-600/20'
                  : 'bg-white text-slate-600 hover:bg-slate-100 hover:text-slate-800'
              }`}
            >
              {tab.label}
            </Link>
          )
        })}
      </nav>
    </div>
  )
}

function CourseWorkspace({ courses }) {
  const { courseId } = useParams()

  return (
    <div>
      <CourseWorkspaceHeader courses={courses} activeCourseId={courseId} />
      <Routes>
        <Route index element={<Navigate to="exams" replace />} />
        <Route path="exams" element={<ExamsList courseId={courseId} />} />
        <Route path="exams/:id" element={<TeacherAnalytics courseId={courseId} />} />
        <Route path="exams/:id/students" element={<ExamDetail />} />
        <Route path="exams/:id/network" element={<NetworkAnalysis courseId={courseId} />} />
        <Route path="exams/:id/devices" element={<MultiDevice courseId={courseId} />} />
        <Route path="exams/:id/similarity" element={<SimilarityDetection courseId={courseId} />} />
        <Route path="students" element={<StudentsList courseId={courseId} />} />
        <Route path="students/:id" element={<StudentDetail />} />
        <Route path="network" element={<NetworkAnalysis courseId={courseId} />} />
        <Route path="devices" element={<MultiDevice courseId={courseId} />} />
        <Route path="similarity" element={<SimilarityDetection courseId={courseId} />} />
      </Routes>
    </div>
  )
}

/* ─── LIVE EXAM DASHBOARD TAB ────────────────────────────── */
function LiveExamDashboard({ activeExams = [] }) {
  const [selectedExamId, setSelectedExamId] = useState(null)

  useEffect(() => {
    if (activeExams.length > 0) {
      if (!selectedExamId || !activeExams.some(e => String(e.id) === String(selectedExamId))) {
        setSelectedExamId(activeExams[0].id)
      }
    } else {
      setSelectedExamId(null)
    }
  }, [activeExams])

  return (
    <div className="space-y-6">
      {activeExams.length > 1 && (
        <div className="flex flex-wrap items-center justify-between gap-3 rounded-2xl border border-emerald-200 bg-emerald-50/80 p-4 shadow-sm">
          <div className="flex items-center gap-2">
            <span className="relative flex h-3 w-3">
              <span className="absolute inline-flex h-full w-full animate-ping rounded-full bg-emerald-400 opacity-75" />
              <span className="relative inline-flex h-3 w-3 rounded-full bg-emerald-500" />
            </span>
            <span className="text-xs font-extrabold text-emerald-800">
              يوجد ({activeExams.length}) امتحانات نشطة حالياً للمدرس — اختر الامتحان للمراقبة:
            </span>
          </div>
          <select
            value={selectedExamId || ''}
            onChange={(e) => setSelectedExamId(e.target.value)}
            className="rounded-xl border border-emerald-300 bg-white px-3.5 py-2 text-xs font-extrabold text-slate-700 outline-none shadow-sm"
          >
            {activeExams.map(ex => (
              <option key={ex.id} value={ex.id}>
                {ex.name} ({ex.course_name || 'مساق'})
              </option>
            ))}
          </select>
        </div>
      )}

      <TeacherAnalytics examId={selectedExamId} isLiveDashboard={true} hasActiveExam={activeExams.length > 0} />
    </div>
  )
}

/* ─── Main Export ────────────────────────────────────────── */
export default function TeacherPortal() {
  const { user } = useAuth()
  const [courses, setCourses] = useState([])
  const [activeExams, setActiveExams] = useState([])
  const [showTour, setShowTour] = useState(() => !localStorage.getItem('exammonitor_teacher_tour'))

  useEffect(() => {
    if (user && user.authType === 'teacher') {
      function load() {
        api.get('/api/teacher/courses').then(d => setCourses(Array.isArray(d) ? d : [])).catch(() => setCourses([]))
        api.get('/api/teacher/exams?status=active').then(d => setActiveExams(Array.isArray(d) ? d : [])).catch(() => setActiveExams([]))
      }
      load()
      const timer = setInterval(load, 3000)
      return () => clearInterval(timer)
    }
  }, [user])

  if (!user) return <Navigate to="/teacher-login" replace />
  if (user.authType !== 'teacher') return <Navigate to="/admin" replace />

  return (
    <div className="relative min-h-screen">
      <div className="pointer-events-none fixed inset-0 -z-10 overflow-hidden">
        <div className="absolute -right-32 -top-32 h-96 w-96 rounded-full bg-brand-200/40 blur-3xl" />
        <div className="absolute -left-24 top-1/3 h-80 w-80 rounded-full bg-violet-200/40 blur-3xl" />
      </div>
      {showTour && (
        <AppTour onFinish={() => {
          localStorage.setItem('exammonitor_teacher_tour', '1')
          setShowTour(false)
        }} />
      )}
      <Header courses={courses} activeExamsCount={activeExams.length} />
      <main className="mx-auto max-w-6xl px-5 py-8 lg:px-8">
        <Routes>
          <Route index element={<Navigate to="dashboard" replace />} />
          <Route path="dashboard" element={<LiveExamDashboard activeExams={activeExams} />} />
          <Route path="courses" element={<CoursesList courses={courses} />} />
          <Route path="c/:courseId/*" element={<CourseWorkspace courses={courses} />} />
          
          {/* Fallbacks */}
          <Route path="exams" element={<ExamsList />} />
          <Route path="exams/:id" element={<ExamDetail />} />
          <Route path="courses/:id" element={<CourseDetail />} />
          <Route path="students" element={<StudentsList />} />
          <Route path="students/:id" element={<StudentDetail />} />
          <Route path="network" element={<NetworkAnalysis />} />
          <Route path="devices" element={<MultiDevice />} />
          <Route path="similarity" element={<SimilarityDetection />} />
          <Route path="*" element={<Navigate to="dashboard" replace />} />
        </Routes>
      </main>
    </div>
  )
}
