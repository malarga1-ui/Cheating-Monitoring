import { useEffect, useState } from 'react'
import { Link, useNavigate, useSearchParams } from 'react-router-dom'
import { useAuth } from '../auth'
import { useI18n } from '../i18n'
import { api } from '../api'

export default function TeacherLogin() {
  const { t } = useI18n()
  const { teacherLogin, teacherTokenLogin, teacherChangePassword, mustChangePassword } = useAuth()
  const navigate = useNavigate()
  const [params] = useSearchParams()

  const [sites, setSites] = useState([])
  const [sitesError, setSitesError] = useState('')
  const [step, setStep] = useState(0) // 0 = pick university, 1 = credentials/token, 2 = change password
  const [accountId, setAccountId] = useState('')
  const [siteName, setSiteName] = useState('')

  const [mode, setMode] = useState('login') // 'login' | 'token'
  const [username, setUsername] = useState('')
  const [password, setPassword] = useState('')
  const [token, setToken] = useState(params.get('token') || '')
  const [error, setError] = useState('')
  const [busy, setBusy] = useState(false)

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
          setStep(1)
        }
      })
      .catch(() => setSitesError(t('err.generic')))
  }, [t])

  useEffect(() => {
    if (mustChangePassword) {
      setStep(2)
    }
  }, [mustChangePassword])

  async function submit(e) {
    e.preventDefault()
    if (!accountId) {
      setError(t('teacher.chooseHint'))
      return
    }
    setError('')
    setBusy(true)
    try {
      if (mode === 'login') {
        if (!username.trim() || !password) {
          setError(t('login.required'))
          return
        }
        const res = await teacherLogin(accountId, username.trim(), password)
        if (res.must_change_password) {
          setStep(2)
        } else {
          navigate('/teacher/portal', { replace: true })
        }
      } else {
        if (!token.trim()) {
          setError(t('teacher.token'))
          return
        }
        await teacherTokenLogin(token.trim())
        navigate('/teacher/portal', { replace: true })
      }
    } catch (err) {
      setError(err.message || t('err.generic'))
    } finally {
      setBusy(false)
    }
  }

  async function submitPasswordChange(e) {
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
            {step === 2 ? (
              <svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round">
                <rect x="3" y="11" width="18" height="11" rx="2" ry="2" />
                <path d="M7 11V7a5 5 0 0 1 10 0v4" />
              </svg>
            ) : (
              <svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round">
                <path d="M2 3h6a4 4 0 0 1 4 4v14a3 3 0 0 0-3-3H2z" />
                <path d="M22 3h-6a4 4 0 0 0-4 4v14a3 3 0 0 1 3-3h7z" />
              </svg>
            )}
          </div>
          <div>
            <h1 className="text-xl font-extrabold text-slate-800">
              {step === 2 ? 'تغيير كلمة المرور' : t('teacher.title')}
            </h1>
            <p className="mt-1 text-sm text-slate-500">
              {step === 2
                ? 'يجب تغيير كلمة المرور الافتراضية قبل المتابعة'
                : t('teacher.subtitle')}
            </p>
          </div>
        </div>

        <div className="rounded-2xl bg-white/90 p-6 shadow-[0_24px_60px_-20px_rgba(16,24,40,.25)] ring-1 ring-white/60 backdrop-blur-xl">

          {/* STEP 2: Force Password Change */}
          {step === 2 ? (
            <form onSubmit={submitPasswordChange}>
              <div className="mb-5 rounded-xl border border-amber-200 bg-amber-50 px-4 py-3">
                <p className="text-xs font-semibold text-amber-700">
                  كلمة المرور الحالية: <span className="font-mono">Teacher@{username ? '' : '...'}</span>
                </p>
                <p className="mt-1 text-xs text-amber-600">
                  استخدم كلمة المرور الافتراضية أعلاه ككلمة مرور حالية، ثم اختر كلمة جديدة
                </p>
              </div>

              <label className="mb-1.5 block text-sm font-bold text-slate-700">كلمة المرور الحالية</label>
              <input
                type="password"
                value={password}
                onChange={(e) => setPassword(e.target.value)}
                placeholder="Teacher@..."
                className={inputCls(!!error)}
              />

              <label className="mb-1.5 block text-sm font-bold text-slate-700">كلمة المرور الجديدة</label>
              <input
                type="password"
                value={newPassword}
                onChange={(e) => {
                  setNewPassword(e.target.value)
                  setError('')
                }}
                autoComplete="new-password"
                placeholder="••••••••"
                className={inputCls(!!error)}
              />

              <label className="mb-1.5 block text-sm font-bold text-slate-700">تأكيد كلمة المرور الجديدة</label>
              <input
                type="password"
                value={confirmPassword}
                onChange={(e) => {
                  setConfirmPassword(e.target.value)
                  setError('')
                }}
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
          ) : step === 0 ? (
            <>
              <label className="mb-1.5 block text-sm font-bold text-slate-700">{t('teacher.university')}</label>
              <p className="mb-3 text-xs text-slate-500">{t('teacher.chooseHint')}</p>
              <div className="mb-5 flex max-h-64 flex-col gap-2 overflow-y-auto pe-1">
                {sitesError ? (
                  <p className="text-sm text-rose-600">{sitesError}</p>
                ) : sites.length === 0 ? (
                  <p className="text-sm text-slate-400">{t('common.loading')}</p>
                ) : (
                  sites.map((s) => (
                    <button
                      key={s.id}
                      type="button"
                      onClick={() => {
                        setAccountId(String(s.id))
                        setSiteName(s.org_name)
                        setStep(1)
                        setError('')
                      }}
                      className="flex cursor-pointer items-center justify-between gap-3 rounded-xl border border-slate-200 bg-slate-50/60 px-4 py-3 text-start transition-all hover:border-brand-400 hover:bg-brand-50/50"
                    >
                      <div className="min-w-0">
                        <p className="truncate text-sm font-bold text-slate-700">{s.org_name}</p>
                        <p className="truncate text-xs text-slate-400" dir="ltr" style={{ textAlign: 'right' }}>
                          {s.site_domain}
                        </p>
                      </div>
                      <span className="shrink-0 text-brand-500">&larr;</span>
                    </button>
                  ))
                )}
              </div>
              <p className="text-center text-xs text-slate-400">
                <Link to="/login" className="font-extrabold text-brand-600 hover:text-brand-700">
                  {t('teacher.toAdminLogin')}
                </Link>
              </p>
            </>
          ) : (
            <form onSubmit={submit}>
              <div className="mb-4 flex items-center gap-2">
                <button
                  type="button"
                  onClick={() => {
                    setStep(0)
                    setError('')
                  }}
                  className="cursor-pointer text-xs font-extrabold text-slate-400 hover:text-slate-600"
                >
                  &larr; {t('teacher.back')}
                </button>
                <span className="min-w-0 flex-1 truncate rounded-lg bg-brand-50 px-3 py-1.5 text-center text-xs font-extrabold text-brand-700 ring-1 ring-brand-100">
                  {siteName}
                </span>
              </div>

              <div className="mb-5 grid grid-cols-2 gap-1 rounded-xl bg-slate-100 p-1">
                <button
                  type="button"
                  onClick={() => setMode('login')}
                  className={`cursor-pointer rounded-lg py-2 text-xs font-extrabold transition-all ${
                    mode === 'login' ? 'bg-white text-brand-700 shadow-sm' : 'text-slate-500 hover:text-slate-700'
                  }`}
                >
                  {t('teacher.login.tab')}
                </button>
                <button
                  type="button"
                  onClick={() => setMode('token')}
                  className={`cursor-pointer rounded-lg py-2 text-xs font-extrabold transition-all ${
                    mode === 'token' ? 'bg-white text-brand-700 shadow-sm' : 'text-slate-500 hover:text-slate-700'
                  }`}
                >
                  {t('teacher.token.tab')}
                </button>
              </div>

              {mode === 'login' ? (
                <>
                  <label className="mb-1.5 block text-sm font-bold text-slate-700">{t('teacher.username')}</label>
                  <input
                    value={username}
                    onChange={(e) => {
                      setUsername(e.target.value)
                      setError('')
                    }}
                    autoComplete="username"
                    placeholder="teacher@moodle"
                    className={inputCls(!!error)}
                  />
                  <label className="mb-1.5 block text-sm font-bold text-slate-700">{t('teacher.password')}</label>
                  <input
                    type="password"
                    value={password}
                    onChange={(e) => {
                      setPassword(e.target.value)
                      setError('')
                    }}
                    autoComplete="current-password"
                    placeholder="••••••••"
                    className={inputCls(!!error)}
                  />
                </>
              ) : (
                <>
                  <label className="mb-1.5 block text-sm font-bold text-slate-700">{t('teacher.token')}</label>
                  <input
                    value={token}
                    onChange={(e) => {
                      setToken(e.target.value)
                      setError('')
                    }}
                    placeholder="••••••••••••"
                    dir="ltr"
                    className={inputCls(!!error) + ' text-center tracking-widest'}
                  />
                  <p className="mb-4 text-xs leading-relaxed text-slate-500">{t('teacher.token.hint')}</p>
                </>
              )}

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
                  t('teacher.login.submit')
                )}
              </button>
            </form>
          )}
        </div>
      </div>
    </div>
  )
}
