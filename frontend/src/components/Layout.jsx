import { useState } from 'react'
import { NavLink, useNavigate } from 'react-router-dom'
import { useAuth } from '../auth'
import { useI18n } from '../i18n'

function Logo({ compact = false }) {
  const { t } = useI18n()
  return (
    <div className="flex items-center gap-2.5">
      <div className="relative flex h-10 w-10 items-center justify-center rounded-xl bg-gradient-to-br from-brand-500 via-violet-500 to-brand-600 text-white shadow-lg shadow-brand-600/30 animate-gradient">
        <svg width="20" height="20" viewBox="0 0 24 24" fill="none">
          <path d="M7 3h10a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2Z" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round" />
          <path d="M12 8v8M8 11v2" stroke="currentColor" strokeWidth="2" strokeLinecap="round" />
          <circle cx="16" cy="10" r="1.6" fill="currentColor" />
        </svg>
        <span className="absolute -right-1 -top-1 h-3 w-3 rounded-full bg-rose-500 ring-2 ring-white" />
      </div>
      {!compact && (
        <div className="leading-tight">
          <p className="text-[15px] font-extrabold text-slate-800">{t('app.name')}</p>
          <p className="text-[11px] font-semibold text-slate-400">{t('app.tagline')}</p>
        </div>
      )}
    </div>
  )
}

const NAV = [
  { to: '/admin', end: true, labelKey: 'nav.dashboard', icon: <path d="M4 13h6V4H4v9Zm0 7h6v-4H4v4Zm10 0h6v-9h-6v9Zm0-16v4h6V4h-6Z" stroke="currentColor" strokeWidth="1.8" strokeLinecap="round" strokeLinejoin="round" fill="none" /> },
  { to: '/admin/exams', labelKey: 'nav.exams', icon: <path d="M9 4h9a2 2 0 0 1 2 2v12a2 2 0 0 1-2 2H9m0-16H6a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h3m0-16v16" stroke="currentColor" strokeWidth="1.8" strokeLinecap="round" strokeLinejoin="round" fill="none" /> },
  { to: '/admin/courses', labelKey: 'nav.courses', icon: <path d="M4 5.5A2.5 2.5 0 0 1 6.5 3H11a2 2 0 0 1 2 2v16a2 2 0 0 0-2-2H6.5A2.5 2.5 0 0 0 4 16.5v-11Zm16 0A2.5 2.5 0 0 0 17.5 3H13a2 2 0 0 0-2 2v16a2 2 0 0 1 2-2h4.5a2.5 2.5 0 0 0 2.5-2.5v-11Z" stroke="currentColor" strokeWidth="1.8" strokeLinecap="round" strokeLinejoin="round" fill="none" /> },
  { to: '/admin/teachers', labelKey: 'nav.teachers', icon: <path d="M17 10a3 3 0 1 0 0-6 3 3 0 0 0 0 6Zm-8-1a3 3 0 1 0 0-6 3 3 0 0 0 0 6Zm9 5.5v1.5a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2v-1.5a3.5 3.5 0 0 1 3.5-3.5h5a3.5 3.5 0 0 1 3.5 3.5ZM9 9a1 1 0 1 0 0-2 1 1 0 0 0 0 2Zm6 0a1 1 0 1 0 0-2 1 1 0 0 0 0 2Z" stroke="currentColor" strokeWidth="1.8" strokeLinecap="round" strokeLinejoin="round" fill="none" /> },
  { to: '/admin/network', labelKey: 'nav.network', icon: <path d="M12 2a5 5 0 0 1 5 5v1a5 5 0 0 1-10 0V7a5 5 0 0 1 5-5ZM2 12.5a7 7 0 0 0 6 6.93V22h2v-2.57a7 7 0 0 0 6-6.93M17.5 17a3 3 0 0 0-3 3h6a3 3 0 0 0-3-3Z" stroke="currentColor" strokeWidth="1.8" strokeLinecap="round" strokeLinejoin="round" fill="none" /> },
  { to: '/admin/similarity', labelKey: 'nav.similarity', icon: <path d="M8 2v4M16 2v4M3 4h18v16a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V4ZM10 10h4M10 14h2" stroke="currentColor" strokeWidth="1.8" strokeLinecap="round" strokeLinejoin="round" fill="none" /> },
  { to: '/admin/devices', labelKey: 'nav.devices', icon: <path d="M5 7a2 2 0 0 1 2-2h10a2 2 0 0 1 2 2v8a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V7ZM8 21h8M12 17v4" stroke="currentColor" strokeWidth="1.8" strokeLinecap="round" strokeLinejoin="round" fill="none" /> },
  { to: '/admin/account', labelKey: 'nav.account', icon: <path d="M5 21a7 7 0 0 1 14 0M12 3a4 4 0 1 1 0 8 4 4 0 0 1 0-8Z" stroke="currentColor" strokeWidth="1.8" strokeLinecap="round" strokeLinejoin="round" fill="none" /> },
]

function NavItem({ item, collapsed }) {
  const { t } = useI18n()
  return (
    <NavLink
      to={item.to}
      end={item.end}
      className={({ isActive }) =>
        `group relative flex items-center gap-3 rounded-xl px-3 py-2.5 text-sm font-bold transition-all duration-300 ${
          collapsed ? 'justify-center px-2' : ''
        } ${
          isActive
            ? 'bg-brand-50 text-brand-700'
            : 'text-slate-500 hover:bg-white/60 hover:text-slate-700 hover:shadow-sm hover:shadow-brand-500/5'
        }`
      }
    >
      {({ isActive }) => (
        <>
          <span className={`absolute start-0 top-1/2 h-6 w-1 -translate-y-1/2 rounded-full bg-gradient-to-b from-brand-500 to-violet-600 transition-all duration-300 ${isActive ? 'opacity-100 scale-100' : 'opacity-0 scale-75'}`} />
          <svg width="18" height="18" viewBox="0 0 24 24" className={`transition-colors duration-200 ${isActive ? 'text-brand-600' : 'text-slate-400 group-hover:text-slate-500'}`}>
            {item.icon}
          </svg>
          {!collapsed && <span className="truncate">{t(item.labelKey)}</span>}
          {collapsed && (
            <span className="absolute start-full ms-2 z-50 whitespace-nowrap rounded-lg bg-slate-900 px-3 py-1.5 text-xs font-bold text-white opacity-0 shadow-xl transition-all duration-200 group-hover:opacity-100 group-hover:translate-x-0 -translate-x-1 pointer-events-none">
              {t(item.labelKey)}
            </span>
          )}
        </>
      )}
    </NavLink>
  )
}

export default function Layout({ children }) {
  const { user, status, logout } = useAuth()
  const { t } = useI18n()
  const navigate = useNavigate()
  const [open, setOpen] = useState(false)
  const [collapsed, setCollapsed] = useState(false)

  const roleLabel =
    user?.staffRole === 'admin' ? t('role.staff.admin')
    : user?.staffRole === 'supervisor' ? t('role.staff.supervisor')
    : user?.role === 'owner' ? t('role.owner')
    : t('role.customer')
  const isOwner = user?.role === 'owner'
  const canManageStaff = (user?.authType === 'account' && !isOwner) || user?.staffRole === 'admin'
  const remaining = status?.status === 'trial' ? Number(status.remaining_days) || 0 : null

  async function handleLogout() {
    await logout()
    navigate('/admin')
  }

  const adminNav = [
    ...NAV,
    ...(canManageStaff ? [
      { to: '/admin/staff', labelKey: 'nav.staff', icon: <path d="M17 10a3 3 0 1 0 0-6 3 3 0 0 0 0 6Zm-8-1a3 3 0 1 0 0-6 3 3 0 0 0 0 6Zm9 5.5v1.5a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2v-1.5a3.5 3.5 0 0 1 3.5-3.5h5a3.5 3.5 0 0 1 3.5 3.5ZM9 9a1 1 0 1 0 0-2 1 1 0 0 0 0 2Zm6 0a1 1 0 1 0 0-2 1 1 0 0 0 0 2Z" stroke="currentColor" strokeWidth="1.8" strokeLinecap="round" strokeLinejoin="round" fill="none" /> },
      { to: '/admin/audit', labelKey: 'nav.audit', icon: <path d="M8 2v4M16 2v4M3 4h18v16a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V4Z" stroke="currentColor" strokeWidth="1.8" strokeLinecap="round" strokeLinejoin="round" fill="none" /> },
    ] : []),
    ...(isOwner ? [
      { to: '/admin/raw', labelKey: 'nav.raw', icon: <path d="M8 4 3 12l5 8M16 4l5 8-5 8M13 4l-2 16" stroke="currentColor" strokeWidth="1.8" strokeLinecap="round" strokeLinejoin="round" fill="none" /> },
      { to: '/admin/formula', labelKey: 'nav.formula', icon: <path d="M14 4v5m0 0h5m-5 0-7 7m7-7 7 7m-7 0h5m-5 0v5M9 4l7 7m-7-7 7 7" stroke="currentColor" strokeWidth="1.8" strokeLinecap="round" strokeLinejoin="round" fill="none" /> },
      { to: '/admin/access', labelKey: 'nav.access', icon: <path d="M12 11a4 4 0 1 0 0-8 4 4 0 0 0 0 8Zm-7 9a7 7 0 0 1 14 0M5 21h14" stroke="currentColor" strokeWidth="1.8" strokeLinecap="round" strokeLinejoin="round" fill="none" /> },
      { to: '/admin/accounts', labelKey: 'nav.accounts', icon: <path d="M17 20h5v-1a4 4 0 0 0-3-3.87M9 20H4v-1a4 4 0 0 1 4-4h2a4 4 0 0 1 4 4v1M13 3.87A4 4 0 0 1 14 11M10 12a4 4 0 1 1 0-8 4 4 0 0 1 0 8Z" stroke="currentColor" strokeWidth="1.8" strokeLinecap="round" strokeLinejoin="round" fill="none" /> },
    ] : []),
  ]

  const sidebarWidth = collapsed ? 'w-[72px]' : 'w-[260px]'

  const sidebarContent = (
    <div className="flex h-full flex-col">
      <div className={`px-5 pt-6 pb-4 ${collapsed ? 'px-3 flex justify-center' : ''}`}>
        {!collapsed && <Logo />}
        {collapsed && (
          <div className="relative flex h-10 w-10 items-center justify-center rounded-xl bg-gradient-to-br from-brand-500 via-violet-500 to-brand-600 text-white shadow-lg shadow-brand-600/30">
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none">
              <path d="M7 3h10a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2Z" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round" />
              <path d="M12 8v8M8 11v2" stroke="currentColor" strokeWidth="2" strokeLinecap="round" />
              <circle cx="16" cy="10" r="1.6" fill="currentColor" />
            </svg>
            <span className="absolute -right-1 -top-1 h-3 w-3 rounded-full bg-rose-500 ring-2 ring-white/80" />
          </div>
        )}
      </div>

      <nav className={`mt-2 flex flex-col gap-1 ${collapsed ? 'px-2' : 'px-3'}`}>
        {adminNav.map((item, i) => (
          <div key={item.to} className={`animate-slide-right stagger-${Math.min(i + 1, 8)}`}>
            <NavItem item={item} collapsed={collapsed} />
          </div>
        ))}
      </nav>

      <div className={`mt-auto ${collapsed ? 'px-2 pb-4' : 'px-4 pb-5'}`}>
        {!collapsed && (
          <div className="rounded-xl bg-gradient-to-br from-brand-50 to-violet-50 p-3.5 ring-1 ring-brand-100 animate-fade-up stagger-5">
            <p className="text-xs font-bold text-brand-700">{t('nav.monitoring')}</p>
            {remaining !== null && (
              <p className="mt-1 text-[11px] font-extrabold text-emerald-600">{t('trial.badge', { days: remaining })}</p>
            )}
            <p className="mt-0.5 text-[11px] leading-relaxed text-slate-500">{t('nav.monitoring.hint')}</p>
          </div>
        )}

        {collapsed && remaining !== null && (
          <div className="mb-2 flex justify-center">
            <span className="relative flex h-8 w-8 items-center justify-center rounded-lg bg-emerald-50 text-emerald-600 ring-1 ring-emerald-200">
              <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round">
                <path d="M12 2v4m0 12v4M4.93 4.93l2.83 2.83m8.48 8.48 2.83 2.83M2 12h4m12 0h4M4.93 19.07l2.83-2.83m8.48-8.48 2.83-2.83" />
              </svg>
            </span>
          </div>
        )}

        <div className={`flex items-center gap-3 rounded-xl px-2 py-2 ${collapsed ? 'justify-center' : ''}`}>
          <div className="flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-gradient-to-br from-slate-700 to-slate-900 text-sm font-extrabold text-white">
            {(user?.org_name || user?.email || 'A').charAt(0).toUpperCase()}
          </div>
          {!collapsed && (
            <div className="min-w-0 flex-1 leading-tight">
              <p className="truncate text-sm font-bold text-slate-700" dir="ltr" style={{ textAlign: 'right' }}>
                {user?.org_name || user?.email}
              </p>
              <p className="text-[11px] text-slate-400">{roleLabel}</p>
            </div>
          )}
          {!collapsed && (
            <button onClick={handleLogout} title={t('account.logout')} aria-label={t('account.logout')} className="flex h-8 w-8 shrink-0 cursor-pointer items-center justify-center rounded-lg text-slate-400 transition-all duration-200 hover:bg-rose-50 hover:text-rose-600">
              <svg width="17" height="17" viewBox="0 0 24 24" fill="none">
                <path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4m7 14 5-5-5-5m5 5H9" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round" />
              </svg>
            </button>
          )}
        </div>
      </div>
    </div>
  )

  return (
    <div className="relative min-h-screen bg-slate-50">
      <div className="pointer-events-none fixed inset-0 -z-10 overflow-hidden">
        <div className="orb orb-1" />
        <div className="orb orb-2" />
        <div className="orb orb-3" />
      </div>

      <aside className={`glass-strong fixed inset-y-0 start-0 z-40 hidden ${sidebarWidth} transition-all duration-300 ease-in-out lg:block`}>
        {sidebarContent}
      </aside>

      {open && (
        <div className="fixed inset-0 z-40 lg:hidden">
          <div className="absolute inset-0 bg-slate-900/40 backdrop-blur-sm" onClick={() => setOpen(false)} />
          <aside className="glass-strong absolute inset-y-0 start-0 w-64 animate-slide-right shadow-2xl">
            {sidebarContent}
          </aside>
        </div>
      )}

      <div className={`transition-all duration-300 ease-in-out ${collapsed ? 'lg:ms-[72px]' : 'lg:ms-[260px]'}`}>
        <header className="glass sticky top-0 z-30 border-b border-slate-200/50 px-5 py-3.5 backdrop-blur-xl lg:px-8">
          <div className="flex items-center gap-3">
            <button onClick={() => setOpen(true)} aria-label="Open menu" className="flex h-9 w-9 cursor-pointer items-center justify-center rounded-lg text-slate-500 transition-colors hover:bg-slate-100 lg:hidden">
              <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round">
                <path d="M4 6h16M4 12h16M4 18h16" />
              </svg>
            </button>

            <button onClick={() => setCollapsed((c) => !c)} className="hidden h-9 w-9 cursor-pointer items-center justify-center rounded-lg text-slate-400 transition-all duration-200 hover:bg-slate-100 hover:text-slate-600 lg:flex">
              <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round" className={`transition-transform duration-300 ${collapsed ? 'rotate-180' : ''}`}>
                <path d="M15 18l-6-6 6-6" />
              </svg>
            </button>

            <div className="lg:hidden"><Logo compact /></div>

            <div className="ms-auto flex items-center gap-3">
              <button
                onClick={() => { localStorage.removeItem('exammonitor_tour_done'); window.location.reload() }}
                title="جولة تعليمية"
                className="flex h-8 w-8 items-center justify-center rounded-full bg-brand-50 text-sm font-extrabold text-brand-600 ring-1 ring-brand-200 transition-all hover:bg-brand-100 hover:ring-brand-300"
              >
                ?
              </button>
              <span className="hidden items-center gap-1.5 rounded-full bg-emerald-50 px-3 py-1 text-xs font-bold text-emerald-700 ring-1 ring-emerald-200 sm:inline-flex">
                <span className="relative flex h-2 w-2">
                  <span className="absolute inline-flex h-full w-full animate-ping rounded-full bg-emerald-400 opacity-75" />
                  <span className="relative inline-flex h-2 w-2 rounded-full bg-emerald-500" />
                </span>
                {t('account.connected')}
              </span>

              <span className="hidden max-w-[180px] truncate text-sm font-bold text-slate-600 sm:block" dir="ltr">
                {user?.org_name || user?.email}
              </span>

              <div className="flex h-9 w-9 cursor-pointer items-center justify-center rounded-full bg-gradient-to-br from-slate-700 to-slate-900 text-sm font-extrabold text-white shadow-md shadow-slate-900/20 transition-all duration-200 hover:shadow-lg hover:shadow-brand-500/20">
                {(user?.org_name || user?.email || 'A').charAt(0).toUpperCase()}
              </div>
            </div>
          </div>
        </header>

        <main className="mx-auto px-5 py-8 lg:px-8">
          <div className="animate-fade-up">{children}</div>
        </main>
      </div>
    </div>
  )
}
