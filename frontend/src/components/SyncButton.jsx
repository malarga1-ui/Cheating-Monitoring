import { useState } from 'react'
import { api } from '../api'

export default function SyncButton({ onSynced }) {
  const [syncing, setSyncing] = useState(false)
  const [result, setResult] = useState(null)
  const [error, setError] = useState('')
  const [showConfirm, setShowConfirm] = useState(false)
  const [needUrl, setNeedUrl] = useState(false)
  const [moodleUrl, setMoodleUrl] = useState('')
  const [savingUrl, setSavingUrl] = useState(false)

  const doSync = async (url) => {
    setSyncing(true)
    setError('')
    setResult(null)
    setShowConfirm(false)
    try {
      const payload = url ? { moodle_url: url } : {}
      const data = await api.post('/api/sync/trigger', payload)
      setResult(data?.synced || data)
      setNeedUrl(false)
      if (onSynced) onSynced()
    } catch (e) {
      if (e.status === 409) {
        setNeedUrl(true)
        setError('')
      } else {
        setError(e.message || 'فشلت المزامنة')
      }
    } finally {
      setSyncing(false)
    }
  }

  const saveUrlAndSync = async () => {
    const url = moodleUrl.trim()
    if (!url) return
    setSavingUrl(true)
    await doSync(url)
    setSavingUrl(false)
  }

  if (result) {
    const s = result
    return (
      <div className="rounded-2xl border border-emerald-200 bg-emerald-50 p-5 text-center animate-fade-up">
        <div className="mx-auto mb-2 flex h-12 w-12 items-center justify-center rounded-full bg-emerald-100 text-2xl">
          ✅
        </div>
        <p className="text-sm font-extrabold text-emerald-800">تمت المزامنة بنجاح!</p>
        <div className="mx-auto mt-3 grid max-w-xs grid-cols-2 gap-2 text-sm">
          {[
            ['📦 الدورات', s.courses],
            ['👨‍🏫 المدرّسين', s.teachers],
            ['🎓 الطلاب', s.students],
            ['📝 الامتحانات', s.quizzes],
          ].map(([label, val]) => (
            <div key={label} className="rounded-lg bg-white px-3 py-2 ring-1 ring-emerald-100">
              <span className="text-slate-500">{label}</span>
              <span className="mr-2 font-extrabold text-emerald-700 tabular-nums">{val || 0}</span>
            </div>
          ))}
        </div>
        <button
          onClick={() => setResult(null)}
          className="mt-4 rounded-xl bg-emerald-600 px-5 py-2 text-xs font-bold text-white shadow hover:bg-emerald-700"
        >
          حسناً
        </button>
      </div>
    )
  }

  if (needUrl) {
    return (
      <div className="rounded-2xl border border-blue-200 bg-blue-50 p-5 text-center animate-fade-up">
        <div className="mx-auto mb-2 flex h-12 w-12 items-center justify-center rounded-full bg-blue-100 text-2xl">
          🔗
        </div>
        <p className="text-sm font-extrabold text-blue-800">ربط موقع المودل</p>
        <p className="mt-1 text-xs text-blue-600">
          أدخل رابط موقع المودل الخاص بك لربطه بحسابك
        </p>
        <input
          type="text"
          value={moodleUrl}
          onChange={(e) => setMoodleUrl(e.target.value)}
          placeholder="مثال: moodle.example.com"
          className="mt-3 w-full rounded-xl border border-blue-200 bg-white px-4 py-2.5 text-sm font-bold text-slate-800 placeholder:text-slate-400 focus:border-blue-500 focus:outline-none focus:ring-2 focus:ring-blue-200"
          onKeyDown={(e) => e.key === 'Enter' && saveUrlAndSync()}
          autoFocus
        />
        <div className="mt-3 flex items-center justify-center gap-2">
          <button
            onClick={() => { setNeedUrl(false); setError('') }}
            className="rounded-xl px-4 py-2 text-xs font-bold text-slate-500 hover:bg-white"
          >
            إلغاء
          </button>
          <button
            onClick={saveUrlAndSync}
            disabled={savingUrl || !moodleUrl.trim()}
            className="rounded-xl bg-blue-600 px-5 py-2 text-xs font-bold text-white shadow hover:bg-blue-700 disabled:opacity-50"
          >
            {savingUrl ? '⏳ جاري الربط والمزامنة...' : '🔗 ربط ومزامنة'}
          </button>
        </div>
      </div>
    )
  }

  if (showConfirm) {
    return (
      <div className="rounded-2xl border border-amber-200 bg-amber-50 p-5 text-center animate-fade-up">
        <div className="mx-auto mb-2 flex h-12 w-12 items-center justify-center rounded-full bg-amber-100 text-2xl">
          🔄
        </div>
        <p className="text-sm font-extrabold text-amber-800">المزامنة من مودل</p>
        <p className="mt-1 text-xs text-amber-600">
          سيتم الاتصال بموقع مودل وسحب جميع البيانات (المدرّسين، الطلاب، الدورات، الامتحانات) تلقائياً
        </p>
        <div className="mt-4 flex items-center justify-center gap-2">
          <button
            onClick={() => setShowConfirm(false)}
            className="rounded-xl px-4 py-2 text-xs font-bold text-slate-500 hover:bg-white"
          >
            إلغاء
          </button>
          <button
            onClick={() => doSync()}
            disabled={syncing}
            className="rounded-xl bg-amber-600 px-5 py-2 text-xs font-bold text-white shadow hover:bg-amber-700 disabled:opacity-50"
          >
            {syncing ? '⏳ جاري المزامنة...' : 'نعم، ابدأ المزامنة'}
          </button>
        </div>
      </div>
    )
  }

  return (
    <div className="rounded-2xl border border-dashed border-slate-300 bg-slate-50 p-5 text-center">
      <div className="mx-auto mb-2 flex h-12 w-12 items-center justify-center rounded-full bg-slate-100 text-2xl">
        🔄
      </div>
      <p className="text-sm font-extrabold text-slate-700">لا توجد بيانات بعد</p>
      <p className="mt-1 text-xs text-slate-500">
        يبدو أن البيانات لم يتم مزامنتها من مودل بعد. اضغط الزر أدناه لسحب البيانات تلقائياً
      </p>
      {error && (
        <p className="mt-2 rounded-lg bg-red-50 px-3 py-2 text-xs font-bold text-red-600 ring-1 ring-red-200">
          ❌ {error}
        </p>
      )}
      <button
        onClick={() => setShowConfirm(true)}
        disabled={syncing}
        className="mt-4 rounded-xl bg-brand-600 px-6 py-2.5 text-sm font-bold text-white shadow-lg shadow-brand-600/25 transition-colors hover:bg-brand-700 disabled:opacity-50"
      >
        🔄 مزامنة البيانات من مودل
      </button>
    </div>
  )
}
