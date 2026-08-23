import { useEffect, useState } from 'react'
import { Link, useParams } from 'react-router-dom'
import { api } from '../api'
import Card from '../components/Card'
import Spinner from '../components/Spinner'
import EmptyState from '../components/EmptyState'
import RiskBadge from '../components/RiskBadge'
import { riskMeta, eventLabel } from '../lib/risk'
import { fmtNum, fmtDuration, fmtTime } from '../lib/format'
import { Reveal, Tilt } from '../components/motion'

function RiskRing({ score }) {
 const R = 30
 const C = 2 * Math.PI * R
 const s = Math.max(0, Math.min(100, Number(score) || 0))
 const [off, setOff] = useState(C)
 useEffect(() => {
  const t = setTimeout(() => setOff(C - (s / 100) * C), 250)
  return () => clearTimeout(t)
 }, [s, C])
 const meta = riskMeta(s >= 80 ? 'critical' : s >= 60 ? 'high' : s >= 40 ? 'medium' : s >= 20 ? 'low' : 'safe')
 return (
  <div className="relative h-24 w-24">
   <div
    className="absolute inset-0 rounded-full blur-xl transition-opacity duration-700"
    style={{ background: meta.dot, opacity: s > 0 ? 0.35 + (s / 100) * 0.35 : 0 }}
   />
   <svg viewBox="0 0 72 72" className="relative h-full w-full -rotate-90">
    <circle cx="36" cy="36" r={R} fill="none" stroke="#1e293b" strokeWidth="7" />
    <circle cx="36" cy="36" r={R} fill="none" stroke={meta.dot} strokeWidth="7" strokeLinecap="round" strokeDasharray={C} strokeDashoffset={off} className="drop-shadow-[0_0_6px_var(--tw-shadow-color)]" style={{ transition: 'stroke-dashoffset 1.1s cubic-bezier(.16,1,.3,1)', filter: `drop-shadow(0 0 ${4 + (s / 100) * 8}px ${meta.dot})` }} />
   </svg>
   <div className="absolute inset-0 flex flex-col items-center justify-center">
    <span className="text-lg font-extrabold tabular-nums text-slate-800">{s}</span>
    <span className="text-[9px] font-bold text-slate-400">من 100</span>
   </div>
  </div>
 )
}

const SIGNALS = [
 { key: 'copy_count', label: 'نسخ', icon: '📋' },
 { key: 'paste_count', label: 'لصق', icon: '📎' },
 { key: 'right_click_count', label: 'نقر أيمن', icon: '🖱️' },
 { key: 'tab_hidden_count', label: 'إخفاء تبويب', icon: '🔒' },
 { key: 'tab_hidden_duration_ms', label: 'مدة الإخفاء', icon: '⏱️' },
 { key: 'blur_count', label: 'مغادرة النافذة', icon: '🪟' },
 { key: 'page_leave_count', label: 'مغادرة الصفحة', icon: '🚪' },
 { key: 'offline_count', label: 'انقطاع الشبكة', icon: '📵' },
 { key: 'devtools_count', label: 'أدوات مطوّر', icon: '🛠️' },
 { key: 'screenshot_count', label: 'لقطات شاشة', icon: '📸' },
 { key: 'rapid_answer_changes', label: 'تغييرات سريعة', icon: '⚡' },
 { key: 'fullscreen_exit_count', label: 'خروج ملء الشاشة', icon: '🖥️' },
 { key: 'idle_count', label: 'فترات خمول', icon: '😴' },
 { key: 'idle_duration_ms', label: 'مدة الخمول', icon: '⏸️' },
 { key: 'answer_changed_count', label: 'تغيير إجابة', icon: '✏️' },
]

function kv(label, value, isDur = false) {
 if (value === undefined || value === null || value === '' || value === false) return null
 return { label, value, isDur }
}

function metadataChips(ev) {
 const chips = []
 const push = (x) => x && chips.push(x)
 const m = ev.metadata
 if (m) {
  push(kv('السؤال', m.question_id ?? m.question_number))
  push(kv('نوع السؤال', m.question_type))
  push(kv('المفاتيح', Array.isArray(m.keys) ? m.keys.join(', ') : m.keys))
  push(kv('مدة الخمول', m.idle_duration_ms, true))
  push(kv('مدة الإخفاء', m.hidden_duration_ms, true))
  push(kv('عدد الكلمات', m.word_count))
  if (m.method) push(kv('طريقة اللقطة', { print_screen_key: 'PrintScreen', windows_snipping_shortcut: 'Win+Shift+S', macos_screenshot_shortcut: 'Cmd+Shift+3/4/5' }[m.method] ?? m.method))
 }
 if (ev.moodle?.quiz) {
  push(kv('الامتحان', ev.moodle.quiz.name))
  push(kv('المحاولة', ev.moodle.quiz.attempt_id))
 }
 if (ev.browser?.platform) push(kv('المنصة', ev.browser.platform))
 return chips.length ? chips : null
}

function ExamSection({ exam: e, studentId }) {
 const [open, setOpen] = useState(false)
 const [answers, setAnswers] = useState(null)
 const loadAnswers = () => {
  if (open) { setOpen(false); return }
  setOpen(true)
  if (answers) return
  api.get(`/api/students/${studentId}/answers/${e.exam_id}`).then(setAnswers).catch(() => {})
 }
 const cats = e.categories || {}
 const catList = [
  { key: 'behavioral', label: 'سلوكي', color: 'text-blue-600', bg: 'bg-blue-50', barBg: 'bg-blue-500' },
  { key: 'network', label: 'شبكة', color: 'text-violet-600', bg: 'bg-violet-50', barBg: 'bg-violet-500' },
  { key: 'ai', label: 'ذكاء اصطناعي', color: 'text-purple-600', bg: 'bg-purple-50', barBg: 'bg-purple-500' },
  { key: 'similarity', label: 'تشابه', color: 'text-rose-600', bg: 'bg-rose-50', barBg: 'bg-rose-500' },
 ]

 return (
  <div className="rounded-xl border border-slate-200 bg-white transition-all hover:shadow-md">
   <div className="flex items-center gap-3 px-4 py-3">
    <Link to={`/admin/exams/${e.exam_id}`} className="min-w-0 flex-1 text-right">
     <p className="text-sm font-extrabold text-slate-700 hover:text-brand-600">{e.exam_name}</p>
     <p className="text-[11px] text-slate-400">
      #{e.moodle_quiz_id} · {fmtNum(e.event_count)} حدث · {e.sessions_count} جلسة · {fmtTime(e.last_event_at)}
     </p>
    </Link>
    <div className="flex items-center gap-2">
     <RiskBadge level={e.risk_level} score={e.risk_score} />
     <button onClick={loadAnswers} className="rounded-lg px-2.5 py-1 text-[11px] font-bold text-brand-600 transition-colors hover:bg-brand-50">
      {open ? 'إخفاء' : 'الإجابات'}
     </button>
    </div>
   </div>

   <div className="grid grid-cols-2 gap-2 border-t border-slate-100 px-4 py-2.5 sm:grid-cols-4 lg:grid-cols-7">
    <div className="text-center">
     <p className="text-xs text-slate-400">IP</p>
     <p className="text-[11px] font-bold text-slate-700 ltr">{e.ip_addresses || '—'}</p>
    </div>
    <div className="text-center">
     <p className="text-xs text-slate-400">AI</p>
     <p className={`text-[11px] font-bold ${(e.ai_suspect_score || 0) > 50 ? 'text-rose-600' : 'text-slate-700'}`}>{fmtNum(e.ai_suspect_score || 0)}%</p>
    </div>
    <div className="text-center">
     <p className="text-xs text-slate-400">الإجابات</p>
     <p className="text-[11px] font-bold text-slate-700">{fmtNum(e.answer_count || 0)}</p>
    </div>
    <div className="text-center">
     <p className="text-xs text-slate-400">تشابه</p>
     <p className={`text-[11px] font-bold ${(e.similarity_max_score || 0) > 50 ? 'text-rose-600' : 'text-slate-700'}`}>{fmtNum(e.similarity_max_score || 0)}%</p>
    </div>
    <div className="text-center">
     <p className="text-xs text-slate-400">تجمع IP</p>
     <p className={`text-[11px] font-bold ${(e.same_ip_student_count || 0) > 0 ? 'text-rose-600' : 'text-slate-700'}`}>{fmtNum(e.same_ip_student_count || 0)}</p>
    </div>
    {e.duration_minutes > 0 && (
     <div className="text-center">
      <p className="text-xs text-slate-400">المدة</p>
      <p className="text-[11px] font-bold text-slate-700">{e.duration_minutes} دقيقة</p>
     </div>
    )}
   </div>

   {catList.length > 0 && (
    <div className="flex gap-1.5 border-t border-slate-100 px-4 py-2">
     {catList.map((c) => {
      const val = cats[c.key] || { score: 0, max: 1 }
      const pct = Math.min(100, Math.round((val.score / Math.max(1, val.max)) * 100))
      return (
       <div key={c.key} className="flex-1">
        <div className="flex items-center justify-between">
         <span className={`text-[10px] font-bold ${c.color}`}>{c.label}</span>
         <span className={`text-[10px] tabular-nums ${pct > 70 ? 'text-rose-600' : 'text-slate-500'}`}>{val.score}/{val.max}</span>
        </div>
        <div className="mt-1 h-1 overflow-hidden rounded-full bg-slate-100">
         <div className={`h-full rounded-full transition-all duration-700 ${c.barBg}`} style={{ width: `${pct}%` }} />
        </div>
       </div>
      )
     })}
    </div>
   )}

   {open && answers && (
    <div className="border-t border-slate-100 px-4 py-3">
     <div className="mb-2 flex items-center gap-3 text-[11px] text-slate-400">
      <span>متوسط AI: <b className={answers.stats.avg_ai_score > 50 ? 'text-rose-600' : 'text-slate-600'}>{answers.stats.avg_ai_score}%</b></span>
      <span>إجابات: <b className="text-slate-600">{answers.stats.total_questions}</b></span>
     </div>
     {answers.answers.length === 0 ? (
      <p className="py-4 text-center text-xs text-slate-400">لا توجد إجابات مسجلة</p>
     ) : (
      <div className="space-y-2 max-h-80 overflow-y-auto">
       {answers.answers.map((a) => (
        <div key={a.id} className={`rounded-lg border px-3 py-2 ${a.ai_score > 50 ? 'border-rose-200 bg-rose-50/50' : a.ai_score > 20 ? 'border-amber-200 bg-amber-50/30' : 'border-slate-100 bg-slate-50/30'}`}>
         <div className="flex items-center justify-between">
          <span className="text-[11px] font-bold text-slate-600">سؤال {a.question_id}</span>
          <div className="flex items-center gap-2">
           {a.ai_score > 0 && (
            <span className={`rounded px-1.5 py-0.5 text-[10px] font-bold ${a.ai_score > 50 ? 'bg-rose-100 text-rose-700' : a.ai_score > 20 ? 'bg-amber-100 text-amber-700' : 'bg-slate-100 text-slate-600'}`}>
             AI: {a.ai_score}%
            </span>
           )}
           <span className="text-[10px] text-slate-400">{fmtTime(a.created_at)}</span>
          </div>
         </div>
         <p className="mt-1 text-xs text-slate-600 line-clamp-2">{a.answer_text || '—'}</p>
         <div className="mt-1 flex flex-wrap gap-3 text-[10px] text-slate-400">
          <span>{a.word_count} كلمة</span>
          <span>{a.change_count} تعديل</span>
          {a.typing_duration_ms > 0 && <span>{fmtDuration(a.typing_duration_ms)} كتابة</span>}
          {(a.paste_length > 0 || a.copy_count_from_question > 0) && (
           <span className="font-semibold text-indigo-600">
            {a.paste_length > 0 && `لصق ${a.paste_length} حرف`}
            {a.paste_length > 0 && a.copy_count_from_question > 0 && ' + '}
            {a.copy_count_from_question > 0 && `نسخ ${a.copy_count_from_question} مرة`}
           </span>
          )}
         </div>
         {a.paste_text && a.paste_length > 0 && (
          <div className="mt-1.5 rounded border border-indigo-200 bg-indigo-50/50 px-2 py-1.5">
           <p className="mb-0.5 text-[10px] font-bold text-indigo-600">النص الملصق:</p>
           <p className="text-[11px] text-slate-700 leading-relaxed break-words whitespace-pre-wrap max-h-24 overflow-y-auto">{a.paste_text}</p>
          </div>
         )}
         {a.copy_text && (
          <div className="mt-1.5 rounded border border-violet-200 bg-violet-50/50 px-2 py-1.5">
           <p className="mb-0.5 text-[10px] font-bold text-violet-600">النص المنسوخ:</p>
           <p className="text-[11px] text-slate-700 leading-relaxed break-words whitespace-pre-wrap max-h-24 overflow-y-auto">{a.copy_text}</p>
          </div>
         )}
        </div>
       ))}
      </div>
     )}
    </div>
   )}
  </div>
 )
}

const SESSION_COLUMNS = [
 { key: 'copy_count', label: 'نسخ' },
 { key: 'paste_count', label: 'لصق' },
 { key: 'right_click_count', label: 'نقر أيمن' },
 { key: 'copy_selection_chars', label: 'حروف منسوخة' },
 { key: 'tab_hidden_count', label: 'إخفاء تبويب' },
 { key: 'tab_hidden_duration_ms', label: 'مدة الإخفاء', dur: true },
 { key: 'tab_visible_count', label: 'إظهار تبويب' },
 { key: 'blur_count', label: 'مغادرة النافذة' },
 { key: 'page_leave_count', label: 'مغادرة الصفحة' },
 { key: 'offline_count', label: 'انقطاع الشبكة' },
 { key: 'devtools_count', label: 'أدوات مطوّر' },
 { key: 'suspicious_key_count', label: 'مفاتيح مشبوهة' },
 { key: 'screenshot_count', label: 'لقطات شاشة' },
 { key: 'rapid_answer_changes', label: 'تغييرات سريعة' },
 { key: 'idle_count', label: 'فترات خمول' },
 { key: 'idle_duration_ms', label: 'مدة الخمول', dur: true },
 { key: 'fullscreen_exit_count', label: 'خروج ملء الشاشة' },
 { key: 'ai_suspect_score', label: 'AI', v9: true },
 { key: 'similarity_max_score', label: 'تشابه', v9: true },
]

const TABS = [
 { key: 'overview', label: 'الامتحانات' },
 { key: 'sessions', label: 'الجلسات التفصيلية' },
 { key: 'events', label: 'سجل الأحداث' },
]

export default function StudentProfile() {
 const { id } = useParams()
 const [tab, setTab] = useState('overview')
 const [profile, setProfile] = useState(null)
 const [sessions, setSessions] = useState(null)
 const [events, setEvents] = useState(null)
 const [filterExam, setFilterExam] = useState('')
 const [err, setErr] = useState('')

 useEffect(() => {
  api.get(`/api/students/${id}`).then(setProfile).catch((e) => setErr(e.message))
 }, [id])

 useEffect(() => {
  api.get(`/api/students/${id}/sessions`).then(setSessions).catch(() => {})
 }, [id])

 useEffect(() => {
  const params = new URLSearchParams({ limit: '300' })
  if (filterExam) params.set('exam_id', filterExam)
  api.get(`/api/students/${id}/events?${params.toString()}`).then(setEvents).catch(() => {})
 }, [id, filterExam])

 if (err) return <EmptyState icon="⚠️" title="تعذر تحميل الطالب" hint={err} />
 if (!profile) return <Spinner />

 const { student = {}, exams = [] } = profile || {}
 const initials = (student.fullname || '').split(' ').slice(0, 2).map((w) => w[0]).join('') || '؟'

 return (
  <div className="space-y-6 min-h-screen px-4 py-6 sm:px-6">
   <Reveal>
    <Link to="/admin/exams" className="inline-flex items-center gap-1.5 text-xs font-bold text-slate-400 transition-colors hover:text-brand-600">
     <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round">
      <path d="M9 5 4 10l5 5M4 10h16"/>
     </svg>
     العودة إلى الامتحانات
    </Link>
   </Reveal>

   <Reveal delay={50}>
    <Card className="overflow-hidden">
     <div className="relative px-6 pb-6 pt-14">
      <div className="absolute inset-x-0 top-0 h-24 bg-gradient-to-l from-brand-600 via-brand-500 to-violet-600"/>
      <div className="absolute inset-x-0 top-0 h-24 opacity-20 [background-image:radial-gradient(circle_at_20%_120%,white_1px,transparent_1px)] [background-size:22px_22px]"/>
      <div className="relative flex flex-wrap items-center gap-5">
       <div className="flex h-20 w-20 items-center justify-center rounded-2xl bg-white text-2xl font-extrabold text-brand-700 shadow-lg ring-4 ring-white">
        {initials}
       </div>
       <div className="min-w-0 flex-1">
        <h1 className="text-2xl font-extrabold text-slate-800">{student.fullname}</h1>
        <p className="mt-0.5 text-sm text-slate-500">{student.username}</p>
        <p className="mt-1 text-[11px] text-slate-400">
         أول ظهور {fmtTime(student.first_seen_at)} · آخر ظهور {fmtTime(student.last_seen_at)}
        </p>
       </div>
       <div className="rounded-2xl bg-white/80 px-5 py-3 ring-1 ring-slate-200/70 backdrop-blur text-center">
        <p className="text-2xl font-extrabold text-brand-600">{exams.length}</p>
        <p className="text-[11px] font-bold text-slate-400">امتحان</p>
       </div>
      </div>
     </div>
    </Card>
   </Reveal>

   <Reveal delay={80}>
    <div className="flex gap-1 rounded-2xl bg-white p-1.5 ring-1 ring-slate-200/70">
     {TABS.map((t) => (
      <button key={t.key} onClick={() => setTab(t.key)}
       className={`flex-1 rounded-xl px-4 py-2.5 text-sm font-bold transition-all ${tab === t.key ? 'bg-brand-600 text-white shadow-sm' : 'text-slate-500 hover:text-slate-700'}`}>
       {t.label}
      </button>
     ))}
    </div>
   </Reveal>

   {tab === 'overview' && (
    <div className="space-y-4">
     {exams.length === 0 ? (
      <Card className="p-10 text-center">
       <p className="text-sm text-slate-400">لا توجد امتحانات مسجلة لهذا الطالب</p>
      </Card>
     ) : (
      exams.map((e) => (
       <Reveal key={e.exam_id}>
        <ExamSection exam={e} studentId={id} />
       </Reveal>
      ))
     )}
    </div>
   )}

   {tab === 'sessions' && (
    <Reveal>
     <Card className="overflow-hidden">
      <div className="px-5 pt-5">
       <h2 className="text-base font-extrabold text-slate-800">الجلسات التفصيلية ({fmtNum(sessions?.length ?? 0)})</h2>
       <p className="text-xs text-slate-400">كل جلسة بقيمها الكاملة — كل مؤشر غش مفصل</p>
      </div>
      {sessions === null ? (
       <Spinner />
      ) : sessions.length === 0 ? (
       <p className="px-5 py-10 text-center text-sm text-slate-400">لا توجد جلسات</p>
      ) : (
       <div className="mt-3 overflow-x-auto">
        <table className="w-full min-w-[1400px] text-sm">
         <thead>
          <tr className="border-b border-slate-100 bg-slate-50/60 text-right text-[11px] font-bold text-slate-400">
           <th className="px-4 py-3">الامتحان</th>
           <th className="px-4 py-3">الأحداث</th>
           {SESSION_COLUMNS.map((c) => (
            <th key={c.key} className={`px-3 py-3 ${c.v9 ? 'text-brand-500' : ''}`}>{c.label}</th>
           ))}
           <th className="px-4 py-3">الخطورة</th>
          </tr>
         </thead>
         <tbody>
          {sessions.map((s, i) => (
           <tr key={s.session_id} className="border-b border-slate-50 transition-colors last:border-0 hover:bg-slate-50/60">
            <td className="px-4 py-3">
             <p className="font-bold text-slate-700">{s.exam_name}</p>
             <p className="text-[11px] tabular-nums text-slate-400">{fmtTime(s.last_event_at)}</p>
            </td>
            <td className="px-4 py-3 tabular-nums text-slate-600">{fmtNum(s.event_count)}</td>
            {SESSION_COLUMNS.map((c) => {
             const v = (s.counters || {})[c.key] || 0
             const hot = v > 0
             const isV9 = c.v9
             return (
              <td key={c.key} className={`px-3 py-3 tabular-nums transition-colors ${hot ? (isV9 ? 'font-bold text-rose-600 bg-rose-50/60' : 'font-bold text-amber-600') : (isV9 ? 'text-slate-400 bg-slate-50/30' : 'text-slate-400')}`}>
               {c.dur ? fmtDuration(v) : (c.key === 'ai_suspect_score' || c.key === 'similarity_max_score' ? `${fmtNum(v)}%` : fmtNum(v))}
              </td>
             )
            })}
            <td className="px-4 py-3">
             <RiskBadge level={s.risk_level} score={s.risk_score} />
            </td>
           </tr>
          ))}
         </tbody>
        </table>
       </div>
      )}
     </Card>
    </Reveal>
   )}

   {tab === 'events' && (
    <Reveal>
     <Card className="p-5">
      <div className="mb-3 flex flex-wrap items-center justify-between gap-3">
       <div>
        <h2 className="text-base font-extrabold text-slate-800">سجل الأحداث</h2>
        <p className="text-xs text-slate-400">آخر الأحداث المستلمة ({fmtNum(events?.length ?? 0)})</p>
       </div>
       <select value={filterExam} onChange={(e) => setFilterExam(e.target.value)} className="rounded-xl border border-slate-200 bg-white px-3 py-1.5 text-xs font-bold text-slate-600 outline-none focus:border-brand-500">
        <option value="">كل الامتحانات</option>
        {exams.map((e) => (
         <option key={e.exam_id} value={e.exam_id}>{e.exam_name}</option>
        ))}
       </select>
      </div>
      {events === null ? (
       <Spinner />
      ) : events.length === 0 ? (
       <p className="py-8 text-center text-sm text-slate-400">لا توجد أحداث</p>
      ) : (
       <ul className="max-h-[560px] space-y-1 overflow-y-auto pl-1">
        {events.map((ev, i) => {
         const chips = metadataChips(ev)
         return (
          <li key={i} className="rounded-lg px-2 py-2 transition-colors hover:bg-slate-50">
           <div className="flex items-center gap-3">
            <span className={`h-2 w-2 shrink-0 rounded-full ${(ev.event_type || '').startsWith('devtools') || ev.event_type === 'suspicious_key' ? 'bg-rose-500 animate-pulse' : 'bg-slate-300'}`} />
            <div className="min-w-0 flex-1">
             <p className="text-sm font-bold text-slate-700">{eventLabel(ev.event_type)}</p>
             <p className="truncate text-[11px] text-slate-400">{ev.url || ev.session_id}</p>
            </div>
            <div className="shrink-0 text-left">
             <p className="text-[11px] tabular-nums text-slate-500">{fmtTime(ev.event_time)}</p>
             {ev.duration_ms && <p className="text-[10px] tabular-nums text-slate-400">{fmtDuration(ev.duration_ms)}</p>}
            </div>
           </div>
           {chips && (
            <div className="mt-1.5 mr-5 flex flex-wrap gap-1.5">
             {chips.map((c, j) => (
              <span key={j} className="rounded-md px-2 py-0.5 text-[11px] font-semibold bg-slate-100 text-slate-500">
               {c.label}: <span className="text-slate-700">{c.isDur ? fmtDuration(c.value) : c.value}</span>
              </span>
             ))}
            </div>
           )}
          </li>
         )
        })}
       </ul>
      )}
     </Card>
    </Reveal>
   )}
  </div>
 )
}
