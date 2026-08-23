import { useEffect, useState } from 'react'
import { Link, useNavigate } from 'react-router-dom'
import { useAuth } from '../auth'
import { useI18n } from '../i18n'
import { api } from '../api'

export default function StaffLogin() {
  const { t } = useI18n()
  const { user, loading, staffLogin } = useAuth()
  const navigate = useNavigate()

  const [sites, setSites] = useState([])
  const [sitesError, setSitesError] = useState('')
  const [step, setStep] = useState(0) // 0 = pick university, 1 = credentials
  const [accountId, setAccountId] = useState('')
  const [siteName, setSiteName] = useState('')

  const [username, setUsername] = useState('')
  const [password, setPassword] = useState('')
  const [error, setError] = useState('')
  const [busy, setBusy] = useState(false)

  useEffect(() => {
    if (!loading && user) navigate('/admin', { replace: true })
  }, [user, loading, navigate])

  useEffect(() => {
    api
      .get('/api/public/sites')
      .then((rows) => {
        const list = Array.isArray(rows) ? rows : []
        setSites(list)
        if (list.length === 1) {
          setAccountId(String(list[0].id))
          setSiteName(list[0].org_name)
          setStep(1)
        }
      })
      .catch(() => setSitesError(t('err.generic')))
  }, [t])

  async function submit(e) {
    e.preventDefault()
    if (!accountId) {
      setError(t('staff.chooseHint'))
      return
    }
    if (!username.trim() || !password) {
      setError(t('login.required'))
      return
    }
    setError('')
    setBusy(true)
    try {
      await staffLogin(accountId, username.trim(), password)
      navigate('/admin', { replace: true })
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

      <div className="relative w-full max-w-md animate-fade-up">
        <div className="mb-7 flex flex-col items-center gap-3 text-center">
          <div className="flex h-16 w-16 items-center justify-center rounded-2xl bg-gradient-to-br from-brand-500 to-violet-600 text-white shadow-xl shadow-brand-600/30">
            <svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round">
              <path d="M14 7h3a2 2 0 0 1 2 2v10a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V9a2 2 0 0 1 2-2h3" />
              <path d="M9 2h6a1 1 0 0 1 1 1v2a1 1 0 0 1-1 1H9a1 1 0 0 1-1-1V3a1 1 0 0 1 1-1Z" />
              <path d="M12 11v6M9 14h6" />
            </svg>
          </div>
          <div>
            <h1 className="text-xl font-extrabold text-slate-800">{t('staff.title')}</h1>
            <p className="mt-1 text-sm text-slate-500">{t('staff.subtitle')}</p>
          </div>
        </div>

        <div className="rounded-2xl bg-white/90 p-6 shadow-[0_24px_60px_-20px_rgba(16,24,40,.25)] ring-1 ring-white/60 backdrop-blur-xl">
          {step === 0 ? (
            <>
              <label className="mb-1.5 block text-sm font-bold text-slate-700">{t('teacher.university')}</label>
              <p className="mb-3 text-xs text-slate-500">{t('staff.chooseHint')}</p>
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
                      <span className="shrink-0 text-brand-500">←</span>
                    </button>
                  ))
                )}
              </div>
              <p className="text-center text-xs text-slate-400">
                <Link to="/teacher-login" className="font-extrabold text-brand-600 hover:text-brand-700">
                  {t('staff.toTeacherLogin')}
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
                  ← {t('teacher.back')}
                </button>
                <span className="min-w-0 flex-1 truncate rounded-lg bg-brand-50 px-3 py-1.5 text-center text-xs font-extrabold text-brand-700 ring-1 ring-brand-100">
                  {siteName}
                </span>
              </div>

              <label className="mb-1.5 block text-sm font-bold text-slate-700">{t('staff.username')}</label>
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
              <label className="mb-1.5 block text-sm font-bold text-slate-700">{t('staff.password')}</label>
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
                  t('staff.login.submit')
                )}
              </button>

              <p className="mt-4 text-center text-xs text-slate-400">
                <Link to="/login" className="font-extrabold text-brand-600 hover:text-brand-700">
                  {t('staff.toAdminLogin')}
                </Link>
              </p>
            </form>
          )}
        </div>
      </div>
    </div>
  )
}
