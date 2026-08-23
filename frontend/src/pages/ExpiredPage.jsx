import { Link } from 'react-router-dom'
import { useAuth } from '../auth'
import { useI18n } from '../i18n'

export default function ExpiredPage() {
  const { t } = useI18n()
  const { logout, user } = useAuth()

  return (
    <div className="relative flex min-h-screen items-center justify-center overflow-hidden p-5">
      <div className="pointer-events-none absolute inset-0">
        <div className="absolute -right-40 -top-40 h-[28rem] w-[28rem] animate-float rounded-full bg-gradient-to-br from-rose-300/40 to-orange-300/30 blur-3xl" />
        <div className="absolute -bottom-44 -left-32 h-[26rem] w-[26rem] animate-float rounded-full bg-gradient-to-br from-slate-200/50 to-rose-200/40 blur-3xl" style={{ animationDelay: '-4s' }} />
      </div>

      <div className="relative w-full max-w-md animate-fade-up text-center">
        <div className="mx-auto mb-6 flex h-20 w-20 items-center justify-center rounded-3xl bg-gradient-to-br from-rose-500 to-orange-500 text-white shadow-xl shadow-rose-500/30">
          <svg width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round">
            <circle cx="12" cy="12" r="9" />
            <path d="M12 7v5m0 3.5h.01" />
          </svg>
        </div>

        <h1 className="text-2xl font-extrabold text-slate-800">{t('expired.title')}</h1>
        <p className="mt-2 text-sm font-semibold leading-relaxed text-slate-500">{t('expired.subtitle')}</p>
        {user && (
          <p className="mt-1 text-xs font-bold text-slate-400" dir="ltr">
            {user.email}
          </p>
        )}

        <div className="mt-8 flex flex-col items-center gap-3">
          <Link
            to="/register"
            className="flex w-full items-center justify-center gap-2 rounded-xl bg-gradient-to-l from-brand-600 to-violet-600 px-5 py-3 text-sm font-extrabold text-white shadow-lg shadow-brand-600/25 transition-all hover:shadow-xl active:scale-[.98]"
          >
            {t('expired.new')}
          </Link>
          <button
            onClick={async () => {
              await logout()
              window.location.href = '/admin'
            }}
            className="w-full rounded-xl border border-slate-200 bg-white px-5 py-3 text-sm font-extrabold text-slate-600 transition-colors hover:bg-slate-50"
          >
            {t('expired.signin')}
          </button>
        </div>
      </div>
    </div>
  )
}
