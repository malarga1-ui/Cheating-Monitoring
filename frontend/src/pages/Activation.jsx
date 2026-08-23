import { useState } from 'react'
import { Link } from 'react-router-dom'
import { api } from '../api'

export default function Activation({ status, onChanged }) {
  const [key, setKey] = useState('')
  const [err, setErr] = useState('')
  const [notice, setNotice] = useState('')
  const [busy, setBusy] = useState(false)

  const startTrial = async () => {
    if (!window.confirm('بدء النسخة التجريبية لمدة 7 أيام؟')) return
    setBusy(true)
    setErr('')
    setNotice('')
    try {
      const d = await api.post('/api/activation/trial', {})
      setNotice(`تم تفعيل النسخة التجريبية — متبقي ${d?.remaining_days ?? 0} يوم`)
      setTimeout(onChanged, 600)
    } catch (e) {
      setErr(e.message)
    } finally {
      setBusy(false)
    }
  }

  const activate = async () => {
    if (!key.trim()) {
      setErr('يرجى إدخال مفتاح الترخيص')
      return
    }
    setBusy(true)
    setErr('')
    setNotice('')
    try {
      await api.post('/api/activation/activate', { key: key.trim() })
      setNotice('تم تفعيل المفتاح بنجاح')
      setTimeout(onChanged, 600)
    } catch (e) {
      setErr(e.message)
    } finally {
      setBusy(false)
    }
  }

  const inputCls =
    'w-full rounded-xl border border-slate-200 bg-slate-50 px-4 py-2.5 text-sm font-bold text-slate-800 outline-none transition-all placeholder:font-normal placeholder:text-slate-400 focus:bg-white focus:ring-4 focus:ring-brand-500/10 focus:border-brand-500'

  return (
    <div className="relative flex min-h-screen items-center justify-center overflow-hidden p-5">
      <div className="pointer-events-none absolute inset-0">
        <div className="absolute -right-40 -top-40 h-[28rem] w-[28rem] animate-float rounded-full bg-gradient-to-br from-brand-300/50 to-violet-300/40 blur-3xl" />
        <div className="absolute -bottom-44 -left-32 h-[26rem] w-[26rem] animate-float rounded-full bg-gradient-to-br from-cyan-200/50 to-brand-200/40 blur-3xl" style={{ animationDelay: '-4s' }} />
      </div>

      <div className="relative w-full max-w-lg animate-fade-up">
        <div className="mb-6 flex flex-col items-center gap-3 text-center">
          <div className="flex h-16 w-16 items-center justify-center rounded-2xl bg-gradient-to-br from-brand-500 to-violet-600 text-white shadow-xl shadow-brand-600/30">
            <svg width="30" height="30" viewBox="0 0 24 24" fill="none">
              <path d="M12 2 4 5v6c0 5 3.4 9.4 8 11 4.6-1.6 8-6 8-11V5l-8-3Z" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round" />
              <path d="m9 12 2 2 4-4" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round" />
            </svg>
          </div>
          <h1 className="text-2xl font-extrabold text-slate-800">تفعيل المنصة</h1>
          <p className="max-w-sm text-sm text-slate-500">
            {status?.status === 'trial'
              ? `النسخة التجريبية نشطة — متبقي ${status.remaining_days} يوم. أدخل مفتاح الترخيص للتفعيل الدائم.`
              : 'ابدأ نسختك التجريبية المجانية لمدة 7 أيام، أو أدخل مفتاح الترخيص الخاص بك.'}
          </p>
        </div>

        {err && (
          <div className="mb-4 rounded-xl border border-rose-200 bg-rose-50 px-4 py-3 text-sm font-bold text-rose-700">{err}</div>
        )}
        {notice && (
          <div className="mb-4 rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm font-bold text-emerald-700">{notice}</div>
        )}

        {status?.status === 'trial' && (
          <div className="mb-5 flex items-center justify-between rounded-xl border border-emerald-200 bg-emerald-50/60 px-4 py-3">
            <div>
              <p className="text-sm font-extrabold text-emerald-700">النسخة التجريبية نشطة</p>
              <p className="text-[11px] font-semibold text-emerald-600/80">
                تنتهي {status.trial_ends_at ? new Date(status.trial_ends_at).toLocaleDateString('ar') : ''} — متبقي {status.remaining_days} يوم
              </p>
            </div>
            <span className="rounded-full bg-emerald-600 px-3 py-1 text-xs font-extrabold text-white">فعّالة</span>
          </div>
        )}

        <div className="rounded-2xl bg-white/90 p-6 shadow-[0_24px_60px_-20px_rgba(16,24,40,.25)] ring-1 ring-white/60 backdrop-blur-xl">
          <button
            onClick={startTrial}
            disabled={busy}
            className="flex w-full items-center justify-center gap-2 rounded-xl bg-gradient-to-l from-brand-600 to-violet-600 py-3 text-sm font-extrabold text-white shadow-lg shadow-brand-600/25 transition-all hover:shadow-xl active:scale-[.98] disabled:opacity-60"
          >
            {busy ? (
              <span className="h-4 w-4 animate-spin rounded-full border-2 border-white/40 border-t-white" />
            ) : (
              <>
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2.2" strokeLinecap="round">
                  <path d="M13 2 3 14h7l-1 8 11-12h-7l1-8Z" />
                </svg>
                ابدأ النسخة التجريبية 7 أيام
              </>
            )}
          </button>

          <div className="my-5 flex items-center gap-3">
            <div className="h-px flex-1 bg-slate-200" />
            <span className="text-[11px] font-bold text-slate-400">أو</span>
            <div className="h-px flex-1 bg-slate-200" />
          </div>

          <label className="mb-1.5 block text-sm font-bold text-slate-700">مفتاح الترخيص</label>
          <input
            value={key}
            onChange={(e) => {
              setKey(e.target.value.toUpperCase())
              setErr('')
            }}
            dir="ltr"
            placeholder="EM-XXXX-XXXX-XXXX-XXXX-XXXX"
            className={inputCls}
          />
          <button
            onClick={activate}
            disabled={busy}
            className="mt-3 flex w-full items-center justify-center gap-2 rounded-xl bg-slate-900 py-3 text-sm font-extrabold text-white transition-colors hover:bg-slate-700 disabled:opacity-60"
          >
            تفعيل المنصة
          </button>

          <p className="mt-5 text-center text-[11px] leading-relaxed text-slate-400">
            هل اشتريت المنصة؟ ستصلك رسالة فيها مفتاح الترخيص الخاص بموقعك.
            <br />
            <Link to="/" className="font-bold text-brand-600 hover:underline">العودة للصفحة الرئيسية</Link>
          </p>
        </div>
      </div>
    </div>
  )
}
