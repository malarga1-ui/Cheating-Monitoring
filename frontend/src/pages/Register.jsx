import { useState } from 'react'
import { Link, useNavigate } from 'react-router-dom'
import { useAuth } from '../auth'
import { useI18n } from '../i18n'

const CONSENT_ITEMS = [
  { icon: '📋', text: 'نجمع بيانات الاسم والبريد الإلكتروني لإنشاء حسابك فقط.' },
  { icon: '🔒', text: 'كلمات المرور مشفّرة ولا يمكن لأحد قراءتها.' },
  { icon: '📊', text: 'بيانات الامتحانات تُستخدم لحساب نسبة الخطر ولا تُشارك مع أطراف ثالثة.' },
  { icon: '🔗', text: 'يربط الحساب بموقع Moodle الخاص بك فقط ولا يعمل مع أي موقع آخر.' },
  { icon: '🗑️', text: 'يمكنك حذف حسابك في أي وقت وسيتم حذف جميع بياناتك.' },
  { icon: '📝', text: 'الكود مفتوح المصدر على GitHub ويمكنك مراجعته.' },
]

export default function Register() {
  const { register } = useAuth()
  const { t } = useI18n()
  const navigate = useNavigate()
  const [org, setOrg] = useState('')
  const [username, setUsername] = useState('')
  const [email, setEmail] = useState('')
  const [password, setPassword] = useState('')
  const [error, setError] = useState('')
  const [busy, setBusy] = useState(false)
  const [consent, setConsent] = useState(false)
  const [showConsent, setShowConsent] = useState(true)

  async function submit(e) {
    e.preventDefault()
    if (!org.trim() || !username.trim() || !email.trim() || !password) {
      setError(t('register.required'))
      return
    }
    if (!consent) {
      setError('يجب الموافقة على سياسة الخصوصية أولاً')
      return
    }
    setError('')
    setBusy(true)
    try {
      const res = await register({ email: email.trim(), password, org_name: org.trim(), username: username.trim() })
      navigate(res?.status?.status === 'trial' ? '/admin/account' : '/admin', { replace: true })
    } catch (err) {
      setError(err.message || t('err.generic'))
    } finally {
      setBusy(false)
    }
  }

  const inputCls = (bad) =>
    `mb-4 w-full rounded-xl border bg-slate-50 px-4 py-2.5 text-sm font-semibold text-slate-800 outline-none transition-all placeholder:font-normal placeholder:text-slate-400 focus:bg-white focus:ring-4 ${
      bad
        ? 'border-rose-300 bg-rose-50/40 focus:border-rose-400 focus:ring-rose-500/10'
        : 'border-slate-200 focus:border-brand-500 focus:ring-brand-500/10'
    }`

  return (
    <div className="relative flex min-h-screen items-center justify-center overflow-hidden p-5">
      <div className="pointer-events-none absolute inset-0">
        <div className="absolute -right-40 -top-40 h-[28rem] w-[28rem] animate-float rounded-full bg-gradient-to-br from-brand-300/50 to-violet-300/40 blur-3xl" />
        <div className="absolute -bottom-44 -left-32 h-[26rem] w-[26rem] animate-float rounded-full bg-gradient-to-br from-cyan-200/50 to-brand-200/40 blur-3xl" style={{ animationDelay: '-4s' }} />
      </div>

      <div className="relative w-full max-w-sm animate-fade-up">
        <div className="mb-7 flex flex-col items-center gap-3 text-center">
          <div className="flex h-16 w-16 items-center justify-center rounded-2xl bg-gradient-to-br from-brand-500 to-violet-600 text-white shadow-xl shadow-brand-600/30">
            <svg width="32" height="32" viewBox="0 0 24 24" fill="none">
              <path d="M7 3h10a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2Z" stroke="currentColor" strokeWidth="2" strokeLinecap="round" />
              <path d="M12 8v8M8 11v2" stroke="currentColor" strokeWidth="2" strokeLinecap="round" />
              <circle cx="16" cy="10" r="1.6" fill="currentColor" />
            </svg>
          </div>
          <div>
            <h1 className="text-xl font-extrabold text-slate-800">{t('register.title')}</h1>
            <p className="mt-1 text-sm text-slate-500">{t('register.subtitle')}</p>
          </div>
        </div>

        {showConsent && (
          <div className="mb-5 rounded-2xl border border-slate-200 bg-white/90 p-5 shadow-[0_24px_60px_-20px_rgba(16,24,40,.25)] ring-1 ring-white/60 backdrop-blur-xl">
            <div className="mb-4 flex items-center gap-2">
              <div className="flex h-8 w-8 items-center justify-center rounded-lg bg-emerald-100 text-emerald-600">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2.5" strokeLinecap="round">
                  <path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z" />
                </svg>
              </div>
              <h2 className="text-sm font-extrabold text-slate-800">سياسة الخصوصية والأمان</h2>
            </div>
            <p className="mb-3 text-xs leading-relaxed text-slate-500">
              قبل إنشاء حسابك، يرجى قراءة ما نقوم بجمعه وكيف نحمي بياناتك:
            </p>
            <ul className="mb-4 space-y-2">
              {CONSENT_ITEMS.map((item, i) => (
                <li key={i} className="flex items-start gap-2.5">
                  <span className="mt-0.5 text-sm">{item.icon}</span>
                  <span className="text-xs font-semibold leading-relaxed text-slate-600">{item.text}</span>
                </li>
              ))}
            </ul>
            <div className="flex items-start gap-3 rounded-xl border border-emerald-200 bg-emerald-50/50 p-3">
              <input
                type="checkbox"
                id="consent-check"
                checked={consent}
                onChange={(e) => setConsent(e.target.checked)}
                className="mt-0.5 h-4 w-4 shrink-0 rounded border-emerald-300 text-emerald-600 focus:ring-emerald-500/20"
              />
              <label htmlFor="consent-check" className="text-xs font-bold leading-relaxed text-emerald-700">
                أقرّ بأنني قرأت سياسة الخصوصية وأوافق على جمع واستخدام البيانات كما هو موضح أعلاه لأغراض مراقبة الامتحانات فقط.
              </label>
            </div>
            <button
              onClick={() => {
                if (!consent) {
                  setError('يجب الموافقة على سياسة الخصوصية أولاً')
                  return
                }
                setShowConsent(false)
              }}
              className="mt-4 w-full rounded-xl bg-gradient-to-l from-brand-600 to-violet-600 py-2.5 text-sm font-extrabold text-white transition-all hover:shadow-lg active:scale-[.98]"
            >
              موافق — أكمل التسجيل
            </button>
            <p className="mt-2 text-center text-[11px] text-slate-400">
              يمكنك مراجعة الكود المصدري على{' '}
              <a href="https://github.com" target="_blank" rel="noreferrer" className="font-bold text-brand-600 hover:text-brand-700">GitHub</a>
            </p>
          </div>
        )}

        {!showConsent && (
          <form onSubmit={submit} className="rounded-2xl bg-white/90 p-6 shadow-[0_24px_60px_-20px_rgba(16,24,40,.25)] ring-1 ring-white/60 backdrop-blur-xl">
            <div className="mb-4 flex items-center gap-2 rounded-xl bg-emerald-50 px-3 py-2">
              <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2.5" className="shrink-0 text-emerald-600">
                <path d="M20 6 9 17l-5-5" />
              </svg>
              <span className="text-xs font-bold text-emerald-700">وافقت على سياسة الخصوصية</span>
              <button type="button" onClick={() => setShowConsent(true)} className="me-auto text-[11px] font-bold text-slate-400 hover:text-slate-600">تعديل</button>
            </div>
            <label className="mb-1.5 block text-sm font-bold text-slate-700">{t('register.org')}</label>
            <input
              value={org}
              onChange={(e) => {
                setOrg(e.target.value)
                setError('')
              }}
              autoFocus
              autoComplete="organization"
              placeholder={t('register.org.placeholder')}
              className={inputCls(!!error)}
            />
            <label className="mb-1.5 block text-sm font-bold text-slate-700">اسم المستخدم</label>
            <input
              value={username}
              onChange={(e) => {
                setUsername(e.target.value)
                setError('')
              }}
              autoComplete="username"
              placeholder="admin"
              className={inputCls(!!error)}
            />
            <label className="mb-1.5 block text-sm font-bold text-slate-700">{t('register.email')}</label>
            <input
              type="email"
              value={email}
              onChange={(e) => {
                setEmail(e.target.value)
                setError('')
              }}
              autoComplete="email"
              placeholder="you@school.edu"
              className={inputCls(!!error)}
            />
            <label className="mb-1.5 block text-sm font-bold text-slate-700">{t('register.password')}</label>
            <input
              type="password"
              value={password}
              onChange={(e) => {
                setPassword(e.target.value)
                setError('')
              }}
              autoComplete="new-password"
              placeholder="••••••••"
              className={inputCls(!!error)}
            />

            {error && (
              <div
                className="mb-5 flex items-start gap-2.5 rounded-xl border border-rose-200 bg-gradient-to-l from-rose-50 to-orange-50/60 px-4 py-3 shadow-sm animate-shake"
                role="alert"
              >
                <span className="mt-0.5 flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-rose-100 text-rose-600">
                  <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2.2" strokeLinecap="round">
                    <path d="M12 8v4m0 4h.01" />
                    <circle cx="12" cy="12" r="9" />
                  </svg>
                </span>
                <div className="min-w-0">
                  <p className="text-sm font-extrabold text-rose-700">{t('login.failed')}</p>
                  <p className="mt-0.5 text-xs font-semibold text-rose-600/80">{error}</p>
                </div>
              </div>
            )}

            <button
              type="submit"
              disabled={busy}
              className="group relative flex w-full items-center justify-center gap-2 overflow-hidden rounded-xl bg-gradient-to-l from-brand-600 to-violet-600 py-3 text-sm font-extrabold text-white shadow-lg shadow-brand-600/25 transition-all hover:shadow-xl hover:shadow-brand-600/30 active:scale-[.98] disabled:opacity-60"
            >
              {busy ? (
                <span className="h-4 w-4 animate-spin rounded-full border-2 border-white/40 border-t-white" />
              ) : (
                <>
                  {t('register.button')}
                  <svg width="16" height="16" viewBox="0 0 24 24" fill="none" className="transition-transform group-hover:-translate-x-0.5">
                    <path d="M15 5l7 7-7 7M22 12H2" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round" />
                  </svg>
                </>
              )}
            </button>
          </form>
        )}

        <div className="mt-6 text-center">
          <p className="text-xs text-slate-400">
            {t('register.haveaccount')}{' '}
            <Link to="/admin" className="font-extrabold text-brand-600 hover:text-brand-700">
              {t('register.signin')}
            </Link>
          </p>
        </div>
      </div>
    </div>
  )
}
