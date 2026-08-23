import { useEffect, useState } from 'react'
import { useAuth } from '../auth'
import { api } from '../api'
import { useI18n } from '../i18n'
import Card from '../components/Card'
import Spinner from '../components/Spinner'
import EmptyState from '../components/EmptyState'

const EMPTY_FORM = { username: '', fullname: '', email: '', password: '', role: 'supervisor' }

export default function Staff() {
  const { t } = useI18n()
  const { user } = useAuth()
  const canManage = (user?.authType === 'account' && user?.role !== 'owner') || user?.staffRole === 'admin'

  const [staff, setStaff] = useState([])
  const [courses, setCourses] = useState([])
  const [busy, setBusy] = useState(true)
  const [err, setErr] = useState('')
  const [msg, setMsg] = useState('')

  const [creating, setCreating] = useState(false)
  const [form, setForm] = useState(EMPTY_FORM)
  const [saving, setSaving] = useState(false)
  const [formError, setFormError] = useState('')

  const [editing, setEditing] = useState(null)

  const [grantTarget, setGrantTarget] = useState(null)
  const [granted, setGranted] = useState([])
  const [loadingGrant, setLoadingGrant] = useState(false)

  const [confirmDelete, setConfirmDelete] = useState(null)

  const flash = (m) => {
    setMsg(m)
    setTimeout(() => setMsg(''), 3000)
  }

  const reload = async () => {
    try {
      const s = await api.get('/api/staff')
      setStaff(s)
      setErr('')
    } catch (e) {
      setErr(e.message)
    } finally {
      setBusy(false)
    }
  }

  useEffect(() => {
    reload()
  }, [])

  async function submitCreate(e) {
    e.preventDefault()
    setFormError('')
    setSaving(true)
    try {
      await api.post('/api/staff', form)
      setCreating(false)
      setForm(EMPTY_FORM)
      flash(t('staff.created'))
      reload()
    } catch (ex) {
      setFormError(ex.message)
    } finally {
      setSaving(false)
    }
  }

  async function submitEdit(e) {
    e.preventDefault()
    setFormError('')
    setSaving(true)
    try {
      const body = {
        fullname: form.fullname,
        email: form.email,
        role: form.role,
        ...(form.password ? { password: form.password } : {}),
      }
      await api.post(`/api/staff/${editing.id}`, body)
      setEditing(null)
      setForm(EMPTY_FORM)
      flash(t('staff.updated'))
      reload()
    } catch (ex) {
      setFormError(ex.message)
    } finally {
      setSaving(false)
    }
  }

  async function toggleActive(s) {
    try {
      await api.post(`/api/staff/${s.id}/toggle`, { active: !s.is_active })
      flash(s.is_active ? t('staff.inactivated') : t('staff.activated'))
      reload()
    } catch (ex) {
      setErr(ex.message)
    }
  }

  async function doDelete() {
    try {
      await api.post(`/api/staff/${confirmDelete.id}/delete`, {})
      setConfirmDelete(null)
      flash(t('staff.deleted'))
      reload()
    } catch (ex) {
      setErr(ex.message)
      setConfirmDelete(null)
    }
  }

  async function openGrant(s) {
    setGrantTarget(s)
    setLoadingGrant(true)
    try {
      const [coursesRes, grantRes] = await Promise.all([
        api.get('/api/courses'),
        api.get(`/api/staff/${s.id}/courses`),
      ])
      setCourses(coursesRes)
      setGranted(grantRes.granted_course_ids || [])
    } catch (ex) {
      setErr(ex.message)
      setGrantTarget(null)
    } finally {
      setLoadingGrant(false)
    }
  }

  async function saveGrant() {
    try {
      await api.post(`/api/staff/${grantTarget.id}/courses`, { course_ids: granted })
      setGrantTarget(null)
      flash(t('staff.grantsSaved'))
      reload()
    } catch (ex) {
      setErr(ex.message)
    }
  }

  const toggleCourse = (cid) => {
    setGranted((g) => (g.includes(cid) ? g.filter((x) => x !== cid) : [...g, cid]))
  }

  const openCreate = () => {
    setForm(EMPTY_FORM)
    setFormError('')
    setCreating(true)
  }

  const openEdit = (s) => {
    setForm({ username: s.username, fullname: s.fullname, email: s.email, password: '', role: s.role })
    setFormError('')
    setEditing(s)
  }

  const inputCls = (bad) =>
    `mb-4 w-full rounded-xl border bg-slate-50 px-4 py-2.5 text-sm font-semibold text-slate-800 outline-none transition-all placeholder:font-normal placeholder:text-slate-400 focus:bg-white focus:ring-4 ${
      bad ? 'border-rose-300 bg-rose-50/40 focus:ring-rose-500/10' : 'border-slate-200 focus:border-brand-500 focus:ring-brand-500/10'
    }`

  if (busy) return <Spinner />
  if (!canManage) return <EmptyState icon="🔒" title={t('staff.noPermission')} hint={t('staff.noPermissionHint')} />
  if (err && !staff.length) return <EmptyState icon="⚠️" title={t('staff.loadFailed')} hint={err} />

  return (
    <div className="space-y-6">
      <header className="flex flex-wrap items-end justify-between gap-3 animate-fade-up">
        <div>
          <h1 className="text-2xl font-extrabold text-slate-800">{t('staff.title')}</h1>
          <p className="mt-1 text-sm text-slate-500">{t('staff.subtitle')}</p>
        </div>
        <button
          onClick={openCreate}
          className="cursor-pointer rounded-xl bg-gradient-to-l from-brand-600 to-violet-600 px-4 py-2.5 text-sm font-extrabold text-white shadow-lg shadow-brand-600/25 transition-all hover:shadow-xl active:scale-[.98]"
        >
          {t('staff.add')}
        </button>
      </header>

      {msg && (
        <div className="rounded-xl bg-emerald-50 px-4 py-3 text-sm font-bold text-emerald-700 ring-1 ring-emerald-200 animate-fade-up">
          {msg}
        </div>
      )}
      {err && (
        <div className="rounded-xl bg-rose-50 px-4 py-3 text-sm font-bold text-rose-700 ring-1 ring-rose-200 animate-fade-up">
          {err}
        </div>
      )}

      <Card className="animate-fade-up">
        <div className="flex items-center justify-between px-6 pt-5">
          <div>
            <h2 className="text-base font-extrabold text-slate-800">{t('staff.list')}</h2>
            <p className="mt-0.5 text-xs text-slate-400">{t('staff.listHint')}</p>
          </div>
          <span className="rounded-full bg-brand-50 px-3 py-1 text-xs font-bold text-brand-700">{staff.length}</span>
        </div>

        {staff.length === 0 ? (
          <p className="px-6 py-12 text-center text-sm text-slate-400">{t('staff.empty')}</p>
        ) : (
          <ul className="mt-3 divide-y divide-slate-50">
            {staff.map((s) => (
              <li key={s.id} className="px-6 py-4">
                <div className="flex flex-wrap items-center justify-between gap-3">
                  <div className="flex min-w-0 items-center gap-3">
                    <div className="flex h-10 w-10 items-center justify-center rounded-full bg-gradient-to-br from-slate-600 to-slate-800 text-sm font-extrabold text-white">
                      {(s.fullname || s.username || 'S').charAt(0).toUpperCase()}
                    </div>
                    <div className="min-w-0">
                      <p className="truncate text-sm font-bold text-slate-800">{s.fullname || s.username}</p>
                      <p className="truncate text-xs text-slate-400" dir="ltr" style={{ textAlign: 'right' }}>
                        {s.username}
                        {s.email ? ` · ${s.email}` : ''}
                      </p>
                    </div>
                  </div>
                  <div className="flex flex-wrap items-center gap-2">
                    <span
                      className={`rounded-full px-2.5 py-1 text-[11px] font-extrabold ${
                        s.role === 'admin' ? 'bg-violet-50 text-violet-700 ring-1 ring-violet-200' : 'bg-amber-50 text-amber-700 ring-1 ring-amber-200'
                      }`}
                    >
                      {s.role === 'admin' ? t('staff.role.admin') : t('staff.role.supervisor')}
                    </span>
                    <span
                      className={`rounded-full px-2.5 py-1 text-[11px] font-extrabold ${
                        s.is_active ? 'bg-emerald-50 text-emerald-700' : 'bg-rose-50 text-rose-600'
                      }`}
                    >
                      {s.is_active ? t('staff.active') : t('staff.inactive')}
                    </span>
                    <span className="rounded-full bg-slate-100 px-2.5 py-1 text-[11px] font-bold tabular-nums text-slate-500">
                      {t('staff.coursesCount', { n: s.courses_count })}
                    </span>
                  </div>
                  <div className="flex items-center gap-1">
                    <ActionBtn label={t('staff.edit')} onClick={() => openEdit(s)}>
                      <path d="M17 3a2.8 2.8 0 1 1 4 4L7.5 20.5 2 22l1.5-5.5L17 3Z" />
                    </ActionBtn>
                    {s.role === 'supervisor' && (
                      <ActionBtn label={t('staff.grants')} onClick={() => openGrant(s)}>
                        <path d="M12 11a4 4 0 1 0 0-8 4 4 0 0 0 0 8Zm-7 9a7 7 0 0 1 14 0" />
                      </ActionBtn>
                    )}
                    <ActionBtn
                      label={s.is_active ? t('staff.disable') : t('staff.enable')}
                      onClick={() => toggleActive(s)}
                    >
                      <path d="M12 3v10M5.6 6.2a8 8 0 1 0 12.8 0" />
                    </ActionBtn>
                    <ActionBtn label={t('staff.delete')} danger onClick={() => setConfirmDelete(s)}>
                      <path d="M3 6h18M8 6V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2m3 0v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6" />
                    </ActionBtn>
                  </div>
                </div>
              </li>
            ))}
          </ul>
        )}
      </Card>

      {/* Create / edit modal */}
      {(creating || editing) && (
        <Modal
          title={creating ? t('staff.new') : `${t('staff.edit')}: ${editing.username}`}
          onClose={() => {
            setCreating(false)
            setEditing(null)
          }}
        >
          <form onSubmit={creating ? submitCreate : submitEdit}>
            {!creating && (
              <p className="mb-4 text-xs font-semibold text-slate-500">
                {t('staff.username')}: <span dir="ltr">{editing.username}</span>
              </p>
            )}
            <label className="mb-1.5 block text-sm font-bold text-slate-700">{t('staff.username')}</label>
            <input
              value={form.username}
              onChange={(e) => setForm({ ...form, username: e.target.value })}
              disabled={!creating}
              placeholder="admin"
              dir="ltr"
              className={inputCls(!!formError) + ' disabled:opacity-60'}
            />
            <label className="mb-1.5 block text-sm font-bold text-slate-700">{t('staff.fullname')}</label>
            <input
              value={form.fullname}
              onChange={(e) => setForm({ ...form, fullname: e.target.value })}
              placeholder={t('staff.fullnamePlaceholder')}
              className={inputCls(!!formError)}
            />
            <label className="mb-1.5 block text-sm font-bold text-slate-700">{t('staff.email')}</label>
            <input
              type="email"
              value={form.email}
              onChange={(e) => setForm({ ...form, email: e.target.value })}
              placeholder="name@uni.edu"
              dir="ltr"
              className={inputCls(!!formError)}
            />
            <label className="mb-1.5 block text-sm font-bold text-slate-700">
              {t('staff.password')} {editing && <span className="text-xs font-normal text-slate-400">({t('staff.passwordOptional')})</span>}
            </label>
            <input
              type="password"
              value={form.password}
              onChange={(e) => setForm({ ...form, password: e.target.value })}
              autoComplete="new-password"
              placeholder={editing ? '••••••••' : t('staff.passwordHint')}
              className={inputCls(!!formError)}
            />
            <label className="mb-1.5 block text-sm font-bold text-slate-700">{t('staff.role')}</label>
            <div className="mb-4 grid grid-cols-2 gap-1 rounded-xl bg-slate-100 p-1">
              {['admin', 'supervisor'].map((r) => (
                <button
                  key={r}
                  type="button"
                  onClick={() => setForm({ ...form, role: r })}
                  className={`cursor-pointer rounded-lg py-2 text-xs font-extrabold transition-all ${
                    form.role === r ? 'bg-white text-brand-700 shadow-sm' : 'text-slate-500 hover:text-slate-700'
                  }`}
                >
                  {r === 'admin' ? t('staff.role.admin') : t('staff.role.supervisor')}
                </button>
              ))}
            </div>

            {formError && (
              <div className="mb-4 rounded-xl border border-rose-200 bg-rose-50 px-4 py-3 text-xs font-semibold text-rose-700">
                {formError}
              </div>
            )}

            <div className="flex justify-end gap-2">
              <button
                type="button"
                onClick={() => {
                  setCreating(false)
                  setEditing(null)
                }}
                className="cursor-pointer rounded-xl px-4 py-2.5 text-sm font-bold text-slate-500 hover:bg-slate-100"
              >
                {t('staff.cancel')}
              </button>
              <button
                type="submit"
                disabled={saving}
                className="cursor-pointer rounded-xl bg-brand-600 px-5 py-2.5 text-sm font-bold text-white shadow-lg shadow-brand-600/25 transition-colors hover:bg-brand-700 disabled:opacity-60"
              >
                {saving ? (
                  <span className="inline-block h-4 w-4 animate-spin rounded-full border-2 border-white/40 border-t-white" />
                ) : (
                  t('staff.save')
                )}
              </button>
            </div>
          </form>
        </Modal>
      )}

      {/* Course grants modal */}
      {grantTarget && (
        <Modal title={`${t('staff.grantsTitle')}: ${grantTarget.fullname || grantTarget.username}`} onClose={() => setGrantTarget(null)}>
          {loadingGrant ? (
            <div className="py-10">
              <Spinner />
            </div>
          ) : (
            <>
              <p className="mb-4 text-xs text-slate-500">{t('staff.grantsHint')}</p>
              <div className="max-h-72 space-y-2 overflow-y-auto">
                {courses.length === 0 && <p className="py-6 text-center text-sm text-slate-400">{t('staff.noCourses')}</p>}
                {courses.map((c) => {
                  const on = granted.includes(c.moodle_course_id)
                  return (
                    <label
                      key={c.id}
                      className={`flex cursor-pointer items-center justify-between rounded-xl border px-4 py-3 transition-colors ${
                        on ? 'border-brand-300 bg-brand-50' : 'border-slate-200'
                      }`}
                    >
                      <span className="text-sm font-bold text-slate-700">
                        {c.name || t('staff.courseFallback', { id: c.moodle_course_id })}
                        <span className="mr-2 text-[11px] font-normal text-slate-400">{t('staff.examsCount', { n: c.exams_count })}</span>
                      </span>
                      <input type="checkbox" checked={on} onChange={() => toggleCourse(c.moodle_course_id)} className="h-4 w-4 accent-brand-600" />
                    </label>
                  )
                })}
              </div>
              <div className="mt-5 flex justify-end gap-2">
                <button onClick={() => setGrantTarget(null)} className="cursor-pointer rounded-xl px-4 py-2.5 text-sm font-bold text-slate-500 hover:bg-slate-100">
                  {t('staff.cancel')}
                </button>
                <button onClick={saveGrant} className="cursor-pointer rounded-xl bg-brand-600 px-5 py-2.5 text-sm font-bold text-white shadow-lg shadow-brand-600/25 transition-colors hover:bg-brand-700">
                  {t('staff.save')}
                </button>
              </div>
            </>
          )}
        </Modal>
      )}

      {/* Delete confirm */}
      {confirmDelete && (
        <Modal title={t('staff.deleteTitle')} onClose={() => setConfirmDelete(null)}>
          <p className="text-sm leading-relaxed text-slate-600">
            {t('staff.deleteConfirm', { name: confirmDelete.fullname || confirmDelete.username })}
          </p>
          <div className="mt-5 flex justify-end gap-2">
            <button onClick={() => setConfirmDelete(null)} className="cursor-pointer rounded-xl px-4 py-2.5 text-sm font-bold text-slate-500 hover:bg-slate-100">
              {t('staff.cancel')}
            </button>
            <button onClick={doDelete} className="cursor-pointer rounded-xl bg-rose-600 px-5 py-2.5 text-sm font-bold text-white shadow-lg shadow-rose-600/25 transition-colors hover:bg-rose-700">
              {t('staff.delete')}
            </button>
          </div>
        </Modal>
      )}
    </div>
  )
}

function Modal({ title, onClose, children }) {
  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center p-4">
      <div className="absolute inset-0 bg-slate-900/40 backdrop-blur-sm" onClick={onClose} />
      <div className="relative w-full max-w-lg rounded-2xl bg-white p-6 shadow-2xl animate-pop">
        <div className="flex items-center justify-between">
          <h3 className="text-lg font-extrabold text-slate-800">{title}</h3>
          <button onClick={onClose} className="flex h-8 w-8 cursor-pointer items-center justify-center rounded-lg text-slate-400 hover:bg-slate-100">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round">
              <path d="M18 6 6 18M6 6l12 12" />
            </svg>
          </button>
        </div>
        <div className="mt-5">{children}</div>
      </div>
    </div>
  )
}

function ActionBtn({ label, onClick, danger = false, children }) {
  return (
    <button
      onClick={onClick}
      title={label}
      aria-label={label}
      className={`flex h-8 w-8 cursor-pointer items-center justify-center rounded-lg transition-colors ${
        danger ? 'text-rose-400 hover:bg-rose-50 hover:text-rose-600' : 'text-slate-400 hover:bg-slate-100 hover:text-slate-700'
      }`}
    >
      <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round">
        {children}
      </svg>
    </button>
  )
}
