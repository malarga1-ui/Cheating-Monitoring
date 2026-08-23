import { useEffect, useState } from 'react'
import { useNavigate } from 'react-router-dom'
import { api } from '../api'
import { SITE } from '../site.config'
import { Terminal, PathTree, FileTree } from '../components/InstallBits'

const STEP_META = [
  {
    id: 'download',
    title: 'نزّل إضافة المراقبة',
    short: 'نزّل الإضافة من GitHub وضعها في مكانها الصحيح داخل مودل.',
  },
  {
    id: 'update',
    title: 'ثبّت وحدّث مودل',
    short: 'حدّث مودل ليتم تثبيت الإضافة وتفعيلها تلقائياً.',
  },
  {
    id: 'connect',
    title: 'اربط المنصة بمفتاحك الخاص',
    short: 'انسخ مفتاح حسابك وألصقه في إعدادات الإضافة داخل مودل.',
  },
  {
    id: 'enable',
    title: 'فعّل المراقبة على اختبار',
    short: 'فعّل "مراقبة الامتحان" على أي اختبار وابدأ استقبال النتائج.',
  },
]

const DOWNLOAD_SCRIPT_LINES = [
  '# 1) نزّل السكربت إلى خادم مودل ثم شغّله',
  'curl -o install_plugin.sh https://' + (typeof window !== 'undefined' ? window.location.host : '') + '/scripts/install_plugin.sh',
  '',
  '# 2) شغّل السكربت — سيبحث عن المسار ويثبّت الإضافة ويحذف المضغوط',
  'bash install_plugin.sh',
]

const UPDATE_LINES = [
  '# حدّث مودل لتثبيت الإضافة وتفعيلها (استبدل المسار بمسارك إن اختلف)',
  'cd /home/luckhdvn/moodle.luckydraw.world',
  '',
  'php admin/cli/upgrade.php',
  '',
  '# بديل: افتح إدارة مودل من المتصفح وسيكتمل التحديث تلقائياً',
]

function StepNumber({ n, done, active }) {
  return (
    <div
      className={`flex h-11 w-11 shrink-0 items-center justify-center rounded-xl text-base font-extrabold transition-all ${
        done
          ? 'bg-emerald-500 text-white shadow-lg shadow-emerald-500/25'
          : active
            ? 'bg-gradient-to-br from-brand-500 to-violet-600 text-white shadow-lg shadow-brand-600/25'
            : 'bg-slate-100 text-slate-400'
      }`}
    >
      {done ? (
        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="3" strokeLinecap="round" strokeLinejoin="round">
          <path d="M20 6 9 17l-5-5" />
        </svg>
      ) : (
        <span>{n}</span>
      )}
    </div>
  )
}

export default function SetupWizard({ onFinish }) {
  const navigate = useNavigate()
  const [loading, setLoading] = useState(true)
  const [progress, setProgress] = useState({})
  const [apiSecret, setApiSecret] = useState('')
  const [openStep, setOpenStep] = useState('download')
  const [err, setErr] = useState('')
  const [busyStep, setBusyStep] = useState('')

  useEffect(() => {
    api
      .get('/api/setup')
      .then((d) => {
        setProgress(d.progress || {})
        setApiSecret(d.api_secret || '')
        const firstIncomplete = (d.steps || []).find((s) => !(d.progress || {})[s])
        if (firstIncomplete) setOpenStep(firstIncomplete)
      })
      .catch((e) => setErr(e.message || 'تعذر تحميل بيانات التهيئة'))
      .finally(() => setLoading(false))
  }, [])

  const doneCount = Object.keys(progress).length
  const total = STEP_META.length
  const pct = Math.round((doneCount / total) * 100)
  const complete = doneCount >= total

  async function toggle(step, done) {
    setBusyStep(step)
    setErr('')
    try {
      const d = await api.post(done ? `/api/setup/${step}` : `/api/setup/${step}/undo`, {})
      setProgress(d.progress || {})
      if (done) {
        // Step completed → collapse it and open next incomplete step
        const nextIncomplete = STEP_META.find((s) => s.id !== step && !(d.progress || {})[s.id])
        setOpenStep(nextIncomplete ? nextIncomplete.id : '')
      } else {
        // Step undone → reopen it
        setOpenStep(step)
      }
    } catch (e) {
      setErr(e.message || 'حدث خطأ أثناء الحفظ')
    } finally {
      setBusyStep('')
    }
  }

  function copy(text) {
    return navigator.clipboard?.writeText(text).then(() => {
      /* ok */
    })
  }

  const [copiedSecret, setCopiedSecret] = useState(false)
  const [copiedScript, setCopiedScript] = useState(false)
  const siteOrigin = typeof window !== 'undefined' ? window.location.origin : ''

  async function copySecret() {
    await copy(apiSecret)
    setCopiedSecret(true)
    setTimeout(() => setCopiedSecret(false), 2000)
  }

  async function copyScript() {
    await copy(DOWNLOAD_SCRIPT_LINES.join('\n'))
    setCopiedScript(true)
    setTimeout(() => setCopiedScript(false), 2000)
  }

  if (loading) {
    return (
      <div className="flex min-h-screen items-center justify-center">
        <span className="h-8 w-8 animate-spin rounded-full border-2 border-brand-500/20 border-t-brand-600" />
      </div>
    )
  }

  return (
    <div className="relative min-h-screen overflow-hidden bg-slate-50 pb-16">
      <div className="pointer-events-none absolute inset-0">
        <div className="absolute -right-40 -top-40 h-[28rem] w-[28rem] rounded-full bg-gradient-to-br from-brand-300/40 to-violet-300/30 blur-3xl" />
        <div className="absolute -bottom-44 -left-32 h-[26rem] w-[26rem] rounded-full bg-gradient-to-br from-cyan-200/40 to-brand-200/30 blur-3xl" style={{ animationDelay: '-4s' }} />
      </div>

      <div className="relative mx-auto w-full max-w-3xl px-5 pt-8 lg:px-8">
        {/* Header */}
        <div className="mb-6 flex flex-col items-center gap-3 text-center">
          <div className="flex h-14 w-14 items-center justify-center rounded-2xl bg-gradient-to-br from-brand-500 to-violet-600 text-white shadow-xl shadow-brand-600/30">
            <svg width="26" height="26" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round">
              <path d="M9 4h9a2 2 0 0 1 2 2v12a2 2 0 0 1-2 2H9m0-16H6a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h3m0-16v16" />
            </svg>
          </div>
          <h1 className="text-2xl font-extrabold text-slate-900">تهيئة حسابك — خطوة بخطوة</h1>
          <p className="max-w-lg text-sm leading-relaxed text-slate-500">
            أكمل الخطوات الأربع أدناه، وعلّم على كل خطوة بعد إنجازها. تقدمك محفوظ في حسابك ويمكنك العودة متى شئت.
          </p>
        </div>

        {/* Progress */}
        <div className="mb-8 rounded-2xl bg-white p-5 shadow-sm ring-1 ring-slate-200/70">
          <div className="flex items-center justify-between">
            <p className="text-sm font-extrabold text-slate-800">تقدّم التثبيت</p>
            <p className="text-sm font-extrabold text-brand-600">
              {doneCount} من {total} خطوات
            </p>
          </div>
          <div className="mt-3 h-3 overflow-hidden rounded-full bg-slate-100">
            <div
              className="h-full rounded-full bg-gradient-to-l from-brand-500 to-violet-600 transition-all duration-700"
              style={{ width: `${pct}%` }}
            />
          </div>
          <div className="mt-1.5 flex justify-between text-[11px] font-bold text-slate-400">
            {STEP_META.map((s, i) => (
              <span key={s.id} className={progress[s.id] ? 'text-emerald-600' : ''}>
                {i + 1}. {s.title}
              </span>
            ))}
          </div>
        </div>

        {err && (
          <div className="mb-5 rounded-xl border border-rose-200 bg-rose-50 px-4 py-3 text-sm font-bold text-rose-700">{err}</div>
        )}

        {complete && (
          <div className="mb-6 rounded-2xl border-2 border-emerald-300 bg-emerald-50 p-6 text-center">
            <div className="mx-auto flex h-14 w-14 items-center justify-center rounded-full bg-emerald-500 text-white shadow-lg shadow-emerald-500/30">
              <svg width="26" height="26" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="3" strokeLinecap="round" strokeLinejoin="round">
                <path d="M20 6 9 17l-5-5" />
              </svg>
            </div>
            <h2 className="mt-4 text-xl font-extrabold text-emerald-800">أحسنت! اكتملت تهيئة حسابك</h2>
            <p className="mt-2 text-sm font-semibold text-emerald-700">
              أنت الآن جاهز لمراقبة امتحاناتك. أدخل لوحة التحكم لبدء استقبال النتائج.
            </p>
            <button
              onClick={() => {
                onFinish?.()
                navigate('/admin')
              }}
              className="mt-5 inline-flex items-center gap-2 rounded-xl bg-emerald-600 px-8 py-3 text-sm font-extrabold text-white shadow-lg shadow-emerald-600/25 transition-all hover:bg-emerald-700 hover:shadow-xl active:scale-[.98]"
            >
              ادخل إلى لوحة التحكم
              <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2.5" strokeLinecap="round">
                <path d="m9 18 6-6-6-6" />
              </svg>
            </button>
          </div>
        )}

        {/* Steps */}
        <div className="space-y-4">
          {STEP_META.map((step, i) => {
            const done = !!progress[step.id]
            const active = openStep === step.id
            return (
              <div
                key={step.id}
                className={`overflow-hidden rounded-2xl bg-white ring-1 transition-all ${
                  done ? 'ring-emerald-200/80' : active ? 'ring-brand-300 shadow-lg shadow-brand-600/5' : 'ring-slate-200/70'
                }`}
              >
                <button
                  onClick={() => setOpenStep(active ? '' : step.id)}
                  className="flex w-full cursor-pointer items-center gap-4 px-5 py-4 text-start"
                >
                  <StepNumber n={i + 1} done={done} active={active} />
                  <div className="min-w-0 flex-1">
                    <h3 className={`text-base font-extrabold ${done ? 'text-emerald-700' : 'text-slate-800'}`}>{step.title}</h3>
                    <p className="mt-0.5 text-xs font-semibold text-slate-400">{step.short}</p>
                  </div>
                  <span className={`text-slate-400 transition-transform ${active ? 'rotate-180' : ''}`}>▼</span>
                </button>

                {active && (
                  <div className="border-t border-slate-100 px-5 py-5">
                    {step.id === 'download' && (
                      <div className="space-y-5">
                        <p className="text-sm leading-relaxed text-slate-600">
                          الإضافة تعمل داخل مودل مباشرة — توضع في المسار القياسي{' '}
                          <span dir="ltr" className="rounded bg-slate-100 px-1.5 py-0.5 font-mono text-[12px] text-slate-700">
                            mod/quiz/accessrule/exammonitor
                          </span>
                          . اختر الطريقة الأنسب لك:
                        </p>

                        <div>
                          <p className="mb-2 text-sm font-extrabold text-slate-700">الطريقة ١ — سكربت أوامر جاهز (الأسهل)</p>
                          <div className="mb-2 flex flex-wrap items-center gap-2">
                            <a
                              href="/scripts/install_plugin.sh"
                              download="install_plugin.sh"
                              className="flex items-center gap-2 rounded-xl bg-slate-900 px-4 py-2 text-xs font-extrabold text-white transition-all hover:bg-slate-700 active:scale-[.98]"
                            >
                              ⬇ نزّل السكربت
                            </a>
                            <button
                              onClick={copyScript}
                              className="flex items-center gap-2 rounded-xl border border-slate-200 px-4 py-2 text-xs font-extrabold text-slate-600 transition-colors hover:bg-slate-50"
                            >
                              {copiedScript ? '✓ تم النسخ' : 'نسخ الأوامر'}
                            </button>
                          </div>
                          <Terminal lines={DOWNLOAD_SCRIPT_LINES} />
                          <ul className="mt-3 space-y-1.5 text-xs font-semibold leading-relaxed text-slate-500">
                            {[
                              'السكربت يبحث تلقائياً عن المسار mod/quiz/accessrule في خادمك.',
                              'ينشئ مجلد exammonitor وينزّل الكود من GitHub ويفك الضغط.',
                              'يتأكد أن الملفات في مكانها الصحيح ثم يحذف الملف المضغوط.',
                            ].map((li) => (
                              <li key={li} className="flex items-start gap-2">
                                <span className="mt-0.5 text-emerald-500">✔</span>
                                <span>{li}</span>
                              </li>
                            ))}
                          </ul>
                        </div>

                        <div>
                          <p className="mb-2 text-sm font-extrabold text-slate-700">الطريقة ٢ — يدوياً (من لوحة الاستضافة)</p>
                          <ol className="space-y-2.5">
                            {[
                              <>افتح <b>مستودع الإضافة</b> واضغط <b>Code</b> ثم <b>Download ZIP</b>.</>,
                              <>في cPanel افتح <b>File Manager</b> وادخل إلى مجلد مودل ثم المسار <span dir="ltr" className="font-mono text-[12px]">mod/quiz/accessrule</span>.</>,
                              <>ارفع الملف المضغوط وفكّ ضغطه ثم أعد تسمية المجلد الناتج إلى <b>exammonitor</b> تماماً.</>,
                            ].map((li, k) => (
                              <li key={k} className="flex items-start gap-3 text-sm font-semibold leading-relaxed text-slate-600">
                                <span className="flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-slate-100 text-xs font-extrabold text-slate-600">{k + 1}</span>
                                <span>{li}</span>
                              </li>
                            ))}
                          </ol>
                          <div className="mt-4 grid gap-4 md:grid-cols-2">
                            <PathTree />
                            <FileTree />
                          </div>
                          <p className="mt-3 rounded-xl bg-emerald-50 px-4 py-3 text-xs font-semibold leading-relaxed text-emerald-700 ring-1 ring-emerald-100">
                            ✅ بعد الانتهاء يجب أن يكون داخل المجلد: amd / classes / db / lang / rule.php / settings.php / teacher.php / version.php
                          </p>
                        </div>

                        <button
                          onClick={() => toggle(step.id, true)}
                          disabled={busyStep === step.id}
                          className="w-full rounded-xl bg-emerald-500 py-3 text-sm font-extrabold text-white transition-all hover:bg-emerald-600 active:scale-[.98] disabled:opacity-60"
                        >
                          {busyStep === step.id ? 'جارٍ الحفظ…' : '✓ تم تنزيل الإضافة'}
                        </button>
                      </div>
                    )}

                    {step.id === 'update' && (
                      <div className="space-y-5">
                        <p className="text-sm leading-relaxed text-slate-600">
                          بعد وضع الإضافة في مكانها، يحتاج مودل إلى «تحديث» ليتم تثبيتها وتفعيلها تلقائياً. طريقتان:
                        </p>
                        <div>
                          <p className="mb-2 text-sm font-extrabold text-slate-700">عبر الطرفية (SSH)</p>
                          <Terminal lines={UPDATE_LINES} />
                        </div>
                        <div>
                          <p className="mb-2 text-sm font-extrabold text-slate-700">عبر المتصفح (الأسهل)</p>
                          <ol className="space-y-2.5">
                            {[
                              'افتح موقع مودل على متصفحك وسجّل الدخول بحساب الأدمن.',
                              'من إدارة الموقع ← الإشعارات (Notifications) — سيجد مودل الإضافة الجديدة تلقائياً.',
                              'اضغط «الاستمرار» (Continue) حتى اكتمال التثبيت.',
                            ].map((s, k) => (
                              <li key={k} className="flex items-start gap-3 text-sm font-semibold leading-relaxed text-slate-600">
                                <span className="flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-slate-100 text-xs font-extrabold text-slate-600">{k + 1}</span>
                                <span>{s}</span>
                              </li>
                            ))}
                          </ol>
                          <p className="mt-3 rounded-xl bg-slate-100 px-4 py-3 text-xs font-semibold leading-relaxed text-slate-600">
                            بعد التحديث، تأكد أن الإضافة تظهر في: إدارة الموقع ← الإضافات ← قواعد الوصول للاختبار ← <b>Exam monitor</b>
                          </p>
                        </div>
                        <button
                          onClick={() => toggle(step.id, true)}
                          disabled={busyStep === step.id}
                          className="w-full rounded-xl bg-emerald-500 py-3 text-sm font-extrabold text-white transition-all hover:bg-emerald-600 active:scale-[.98] disabled:opacity-60"
                        >
                          {busyStep === step.id ? 'جارٍ الحفظ…' : '✓ تم تثبيت وتحديث الإضافة'}
                        </button>
                      </div>
                    )}

                    {step.id === 'connect' && (
                      <div className="space-y-5">
                        <p className="text-sm leading-relaxed text-slate-600">
                          كل حساب له مفتاح خاص مربوط به. ضع هذا المفتاح في إعدادات الإضافة داخل مودل لتربط موقعك بالمنصة:
                        </p>
                        <div className="rounded-xl border border-brand-200 bg-brand-50/70 p-4">
                          <p className="text-xs font-bold text-slate-400">مفتاح حسابك (انسخه)</p>
                          <div className="mt-2 flex items-center gap-2">
                            <p className="min-w-0 flex-1 break-all font-mono text-sm font-bold text-slate-800" dir="ltr" style={{ textAlign: 'left' }}>
                              {apiSecret}
                            </p>
                            <button
                              onClick={copySecret}
                              className="shrink-0 rounded-lg bg-slate-900 px-4 py-2 text-xs font-extrabold text-white transition-colors hover:bg-slate-700"
                            >
                              {copiedSecret ? '✓ تم النسخ' : 'نسخ'}
                            </button>
                          </div>
                        </div>
                        <ol className="space-y-2.5">
                          {[
                            'افتح مودل ← إدارة الموقع ← الإضافات ← قواعد الوصول للاختبار.',
                            'افتح إعدادات «Exam monitor» (Settings).',
                            <>في حقل <b>Sync server URL</b> ضع: <span dir="ltr" className="rounded bg-slate-100 px-1.5 py-0.5 font-mono text-[12px] text-slate-700">{siteOrigin}</span></>,
                            <>في حقل <b>Sync secret</b> (المفتاح) ألصق المفتاح أعلاه، ثم احفظ الإعدادات.</>,
                          ].map((s, k) => (
                            <li key={k} className="flex items-start gap-3 text-sm font-semibold leading-relaxed text-slate-600">
                              <span className="flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-brand-100 text-xs font-extrabold text-brand-700">{k + 1}</span>
                              <span>{s}</span>
                            </li>
                          ))}
                        </ol>
                        <p className="rounded-xl bg-slate-100 px-4 py-3 text-xs font-semibold leading-relaxed text-slate-600">
                          أول موقع مودل يتصل بالمفتاح يُسجَّل كموقعك الخاص تلقائياً ولا يعمل المفتاح مع أي موقع آخر.
                        </p>
                        <button
                          onClick={() => toggle(step.id, true)}
                          disabled={busyStep === step.id}
                          className="w-full rounded-xl bg-emerald-500 py-3 text-sm font-extrabold text-white transition-all hover:bg-emerald-600 active:scale-[.98] disabled:opacity-60"
                        >
                          {busyStep === step.id ? 'جارٍ الحفظ…' : '✓ تم ربط المنصة'}
                        </button>
                      </div>
                    )}

                    {step.id === 'enable' && (
                      <div className="space-y-5">
                        <p className="text-sm leading-relaxed text-slate-600">
                          المراقبة تُفعَّل لكل اختبار على حدة. بمجرد تفعيلها يبدأ الرصد فور أول دخول لأي طالب:
                        </p>
                        <ol className="space-y-2.5">
                          {[
                            'افتح الاختبار (Quiz) الذي تريد مراقبته ← إعدادات الاختبار.',
                            'ضمن قيود الوصول (Access restrictions) فعّل «Exam monitor» / مراقبة الامتحان.',
                            'اختر ما تريد منعه فعلياً: النسخ، اللصق، النقر الأيمن، الطباعة، أدوات المطوّر (F12).',
                            'احفظ، وعند بدء الطلاب للامتحان تظهر النتائج في لوحة التحكم مباشرة.',
                          ].map((s, k) => (
                            <li key={k} className="flex items-start gap-3 text-sm font-semibold leading-relaxed text-slate-600">
                              <span className="flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-slate-100 text-xs font-extrabold text-slate-600">{k + 1}</span>
                              <span>{s}</span>
                            </li>
                          ))}
                        </ol>
                        <button
                          onClick={() => toggle(step.id, true)}
                          disabled={busyStep === step.id}
                          className="w-full rounded-xl bg-emerald-500 py-3 text-sm font-extrabold text-white transition-all hover:bg-emerald-600 active:scale-[.98] disabled:opacity-60"
                        >
                          {busyStep === step.id ? 'جارٍ الحفظ…' : '✓ تم تفعيل المراقبة'}
                        </button>
                      </div>
                    )}
                  </div>
                )}
              </div>
            )
          })}
        </div>

        {/* Bottom actions */}
        <div className="mt-8 flex flex-col items-center gap-3">
          <button
            onClick={() => {
              onFinish?.()
              navigate('/admin')
            }}
            className="cursor-pointer text-sm font-extrabold text-slate-500 transition-colors hover:text-brand-600"
          >
            تخطَّ إلى لوحة التحكم الآن
          </button>
          <a href={SITE.repoUrl.replace(/\.git$/, '')} target="_blank" rel="noreferrer" className="text-xs font-semibold text-slate-400 hover:text-slate-600">
            فتح مستودع الإضافة على GitHub
          </a>
        </div>
      </div>
    </div>
  )
}
