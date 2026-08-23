import { useEffect, useState } from 'react'
import { Link, useNavigate } from 'react-router-dom'
import { useAuth } from '../auth'
import { useI18n } from '../i18n'
import { api } from '../api'

export default function Login() {
  const { login, teacherLogin, teacherChangePassword, mustChangePassword } = useAuth()
  const { t } = useI18n()
  const navigate = useNavigate()

  const [tab, setTab] = useState('admin') // 'admin' | 'teacher'
  const [error, setError] = useState('')
  const [busy, setBusy] = useState(false)

  // --- Admin fields ---
  const [email, setEmail] = useState('')
  const [password, setPassword] = useState('')

  // --- Teacher fields ---
  const [sites, setSites] = useState([])
  const [sitesError, setSitesError] = useState('')
  const [tStep, setTStep] = useState(0) // 0=university, 1=credentials, 2=change password
  const [accountId, setAccountId] = useState('')
  const [siteName, setSiteName] = useState('')
  const [username, setUsername] = useState('')
  const [teacherPassword, setTeacherPassword] = useState('')
  const [newPassword, setNewPassword] = useState('')
  const [confirmPassword, setConfirmPassword] = useState('')

  useEffect(() => {
    api
      .get('/api/public/sites')
      .then((rows) => {
        setSites(Array.isArray(rows) ? rows : [])
        if (rows.length === 1) {
          setAccountId(String(rows[0].id))
          setSiteName(rows[0].org_name)
          setTStep(1)
        }
      })
      .catch(() => setSitesError('تعذر تحميل قائمة الجامعات'))
  }, [])

  useEffect(() => {
    if (mustChangePassword && tab === 'teacher') {
      setTStep(2)
    }
  }, [mustChangePassword, tab])

  // ---- Admin login ----
  async function handleAdminLogin(e) {
    e.preventDefault()
    if (!email.trim() || !password) {
      setError('أدخل اسم المستخدم أو البريد وكلمة المرور')
      return
    }
    setError('')
    setBusy(true)
    try {
      await login(email.trim(), password)
      navigate('/admin', { replace: true })
    } catch (err) {
      setError(err.message || 'تعذر تسجيل الدخول')
    } finally {
      setBusy(false)
    }
  }

  // ---- Teacher login step 1 (credentials) ----
  async function handleTeacherLogin(e) {
    e.preventDefault()
    if (!username.trim() || !teacherPassword) {
      setError('أدخل اسم المستخدم وكلمة المرور')
      return
    }
    setError('')
    setBusy(true)
    try {
      const res = await teacherLogin(accountId, username.trim(), teacherPassword)
      if (res.must_change_password) {
        setTStep(2)
      } else {
        navigate('/teacher/portal', { replace: true })
      }
    } catch (err) {
      setError(err.message || 'تعذر تسجيل الدخول')
    } finally {
      setBusy(false)
    }
  }

  // ---- Teacher password change ----
  async function handlePasswordChange(e) {
    e.preventDefault()
    setError('')
    if (!newPassword || !confirmPassword) {
      setError('كلمة المرور الجديدة وتأكيدها مطلوبان')
      return
    }
    if (newPassword !== confirmPassword) {
      setError('كلمتا المرور غير متطابقتين')
      return
    }
    if (newPassword.length < 6) {
      setError('كلمة المرور يجب ألا تقل عن 6 أحرف')
      return
    }
    setBusy(true)
    try {
      await teacherChangePassword(newPassword, confirmPassword)
      navigate('/teacher/portal', { replace: true })
    } catch (err) {
      setError(err.message || 'فشل تغيير كلمة المرور')
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

      <div className="relative w-full max-w-md animate-fade-up">
        <div className="mb-7 flex flex-col items-center gap-3 text-center">
          <div className="flex h-16 w-16 items-center justify-center rounded-2xl bg-gradient-to-br from-brand-500 to-violet-600 text-white shadow-xl shadow-brand-600/30">
            <svg width="32" height="32" viewBox="0 0 24 24" fill="none">
              <path d="M7 3h10a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2Z" stroke="currentColor" strokeWidth="2" strokeLinecap="round" />
              <path d="M12 8v8M8 11v2" stroke="currentColor" strokeWidth="2" strokeLinecap="round" />
              <circle cx="16" cy="10" r="1.6" fill="currentColor" />
            </svg>
          </div>
          <div>
            <h1 className="text-xl font-extrabold text-slate-800">{t('app.name')}</h1>
            <p className="mt-1 text-sm text-slate-500">سجّل الدخول لعرض تحليلات الامتحانات</p>
          </div>
        </div>

        <div className="rounded-2xl bg-white/90 p-6 shadow-[0_24px_60px_-20px_rgba(16,24,40,.25)] ring-1 ring-white/60 backdrop-blur-xl">

          {/* Tab switcher - hidden during password change */}
          {tStep !== 2 && (
            <div className="mb-5 grid grid-cols-2 gap-1 rounded-xl bg-slate-100 p-1">
              <button
                type="button"
                onClick={() => { setTab('admin'); setError(''); setTStep(0) }}
                className={`cursor-pointer rounded-lg py-2.5 text-sm font-extrabold transition-all ${
                  tab === 'admin' ? 'bg-white text-brand-700 shadow-sm' : 'text-slate-500 hover:text-slate-700'
                }`}
              >
                مدير الجامعة
              </button>
              <button
                type="button"
                onClick={() => { setTab('teacher'); setError('') }}
                className={`cursor-pointer rounded-lg py-2.5 text-sm font-extrabold transition-all ${
                  tab === 'teacher' ? 'bg-white text-brand-700 shadow-sm' : 'text-slate-500 hover:text-slate-700'
                }`}
              >
                المدرس
              </button>
            </div>
          )}

          {/* === ADMIN TAB === */}
          {tab === 'admin' && tStep !== 2 && (
            <form onSubmit={handleAdminLogin}>
              <label className="mb-1.5 block text-sm font-bold text-slate-700">اسم المستخدم أو البريد الإلكتروني</label>
              <input
                value={email}
                onChange={(e) => { setEmail(e.target.value); setError('') }}
                autoFocus
                autoComplete="username"
                placeholder="admin"
                className={inputCls(!!error)}
              />
              <label className="mb-1.5 block text-sm font-bold text-slate-700">كلمة المرور</label>
              <input
                type="password"
                value={password}
                onChange={(e) => { setPassword(e.target.value); setError('') }}
                autoComplete="current-password"
                placeholder="••••••••"
                className={inputCls(!!error)}
              />

              {error && (
                <div className="mb-5 flex items-start gap-2.5 rounded-xl border border-rose-200 bg-gradient-to-l from-rose-50 to-orange-50/60 px-4 py-3 shadow-sm animate-shake" role="alert">
                  <span className="mt-0.5 flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-rose-100 text-rose-600">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2.2" strokeLinecap="round">
                      <path d="M12 8v4m0 4h.01" /><circle cx="12" cy="12" r="9" />
                    </svg>
                  </span>
                  <div className="min-w-0">
                    <p className="text-sm font-extrabold text-rose-700">تعذر تسجيل الدخول</p>
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
                    تسجيل الدخول
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" className="transition-transform group-hover:-translate-x-0.5">
                      <path d="M15 5l7 7-7 7M22 12H2" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round" />
                    </svg>
                  </>
                )}
              </button>

              <div className="mt-5 text-center">
                <p className="text-xs text-slate-400">
                  ليس لديك حساب؟{' '}
                  <Link to="/register" className="font-extrabold text-brand-600 hover:text-brand-700">
                    سجّل الآن
                  </Link>
                </p>
              </div>
            </form>
          )}

          {/* === TEACHER TAB: Step 0 — Pick University === */}
          {tab === 'teacher' && tStep === 0 && (
            <>
              <label className="mb-1.5 block text-sm font-bold text-slate-700">اختر جامعتك</label>
              <p className="mb-3 text-xs text-slate-500">اختر الجامعة التي تعمل فيها</p>
              <div className="mb-5 flex max-h-64 flex-col gap-2 overflow-y-auto pe-1">
                {sitesError ? (
                  <p className="text-sm text-rose-600">{sitesError}</p>
                ) : sites.length === 0 ? (
                  <p className="text-sm text-slate-400">جاري التحميل...</p>
                ) : (
                  sites.map((s) => (
                    <button
                      key={s.id}
                      type="button"
                      onClick={() => {
                        setAccountId(String(s.id))
                        setSiteName(s.org_name)
                        setTStep(1)
                        setError('')
                      }}
                      className="flex cursor-pointer items-center justify-between gap-3 rounded-xl border border-slate-200 bg-slate-50/60 px-4 py-3 text-start transition-all hover:border-brand-400 hover:bg-brand-50/50"
                    >
                      <div className="min-w-0">
                        <p className="truncate text-sm font-bold text-slate-700">{s.org_name}</p>
                        <p className="truncate text-xs text-slate-400">{s.site_domain}</p>
                      </div>
                      <span className="shrink-0 text-brand-500">&larr;</span>
                    </button>
                  ))
                )}
              </div>
              <p className="text-center text-xs text-slate-400">
                أنت مدير الجامعة؟{' '}
                <button type="button" onClick={() => { setTab('admin'); setError('') }} className="font-extrabold text-brand-600 hover:text-brand-700">
                  سجّل دخول كمدير
                </button>
              </p>
            </>
          )}

          {/* === TEACHER TAB: Step 1 — Credentials === */}
          {tab === 'teacher' && tStep === 1 && (
            <form onSubmit={handleTeacherLogin}>
              <div className="mb-4 flex items-center gap-2">
                <button
                  type="button"
                  onClick={() => { setTStep(0); setError('') }}
                  className="cursor-pointer text-xs font-extrabold text-slate-400 hover:text-slate-600"
                >
                  &larr; رجوع
                </button>
                <span className="min-w-0 flex-1 truncate rounded-lg bg-brand-50 px-3 py-1.5 text-center text-xs font-extrabold text-brand-700 ring-1 ring-brand-100">
                  {siteName}
                </span>
              </div>

              <label className="mb-1.5 block text-sm font-bold text-slate-700">اسم المستخدم</label>
              <input
                value={username}
                onChange={(e) => { setUsername(e.target.value); setError('') }}
                autoFocus
                autoComplete="username"
                placeholder="teacher"
                className={inputCls(!!error)}
              />
              <label className="mb-1.5 block text-sm font-bold text-slate-700">كلمة المرور</label>
              <input
                type="password"
                value={teacherPassword}
                onChange={(e) => { setTeacherPassword(e.target.value); setError('') }}
                autoComplete="current-password"
                placeholder="••••••••"
                className={inputCls(!!error)}
              />
              <p className="mb-4 text-xs text-slate-400">
                كلمة المرور الافتراضية: <span className="font-mono font-semibold text-slate-500">{username || 'اسمالمستخدم'}@915</span> — سيُطلب تغييرها بعد أول دخول
              </p>

              {error && (
                <div className="mb-5 flex items-start gap-2.5 rounded-xl border border-rose-200 bg-rose-50 px-4 py-3 animate-shake" role="alert">
                  <span className="mt-0.5 flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-rose-100 text-rose-600">!</span>
                  <p className="text-xs font-semibold text-rose-700">{error}</p>
                </div>
              )}

              <button
                type="submit"
                disabled={busy}
                className="flex w-full items-center justify-center gap-2 rounded-xl bg-gradient-to-l from-brand-600 to-violet-600 py-3 text-sm font-extrabold text-white shadow-lg shadow-brand-600/25 transition-all hover:shadow-xl active:scale-[.98] disabled:opacity-60"
              >
                {busy ? (
                  <span className="h-4 w-4 animate-spin rounded-full border-2 border-white/40 border-t-white" />
                ) : (
                  'تسجيل الدخول'
                )}
              </button>
            </form>
          )}

          {/* === TEACHER TAB: Step 2 — Force Password Change === */}
          {tab === 'teacher' && tStep === 2 && (
            <form onSubmit={handlePasswordChange}>
              <div className="mb-5 rounded-xl border border-amber-200 bg-amber-50 px-4 py-3">
                <p className="text-xs font-semibold text-amber-700">
                  كلمة المرور الافتراضية: <span className="font-mono">{username}@915</span>
                </p>
                <p className="mt-1 text-xs text-amber-600">
                  استخدم كلمة المرور الافتراضية ككلمة مرور حالية، ثم اختر كلمة جديدة
                </p>
              </div>

              <label className="mb-1.5 block text-sm font-bold text-slate-700">كلمة المرور الحالية</label>
              <input
                type="password"
                value={teacherPassword}
                onChange={(e) => setTeacherPassword(e.target.value)}
                placeholder="••••••••"
                className={inputCls(!!error)}
              />

              <label className="mb-1.5 block text-sm font-bold text-slate-700">كلمة المرور الجديدة</label>
              <input
                type="password"
                value={newPassword}
                onChange={(e) => { setNewPassword(e.target.value); setError('') }}
                autoComplete="new-password"
                placeholder="••••••••"
                className={inputCls(!!error)}
              />

              <label className="mb-1.5 block text-sm font-bold text-slate-700">تأكيد كلمة المرور الجديدة</label>
              <input
                type="password"
                value={confirmPassword}
                onChange={(e) => { setConfirmPassword(e.target.value); setError('') }}
                autoComplete="new-password"
                placeholder="••••••••"
                className={inputCls(!!error)}
              />

              {error && (
                <div className="mb-5 flex items-start gap-2.5 rounded-xl border border-rose-200 bg-rose-50 px-4 py-3 animate-shake" role="alert">
                  <span className="mt-0.5 flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-rose-100 text-rose-600">!</span>
                  <p className="text-xs font-semibold text-rose-700">{error}</p>
                </div>
              )}

              <button
                type="submit"
                disabled={busy}
                className="flex w-full items-center justify-center gap-2 rounded-xl bg-gradient-to-l from-amber-500 to-orange-500 py-3 text-sm font-extrabold text-white shadow-lg shadow-amber-500/25 transition-all hover:shadow-xl active:scale-[.98] disabled:opacity-60"
              >
                {busy ? (
                  <span className="h-4 w-4 animate-spin rounded-full border-2 border-white/40 border-t-white" />
                ) : (
                  'تغيير كلمة المرور والمتابعة'
                )}
              </button>
            </form>
          )}
        </div>
      </div>
    </div>
  )
}
