import { useState, useEffect, useRef } from 'react'
import { Link } from 'react-router-dom'
import { SITE } from '../site.config'
import { Reveal, Tilt, GlowCard } from '../components/motion'
import { Terminal } from '../components/InstallBits'

const SECTION = 'mx-auto w-full max-w-6xl px-5 lg:px-8'

const FEATURES = [
  { icon: '🧠', title: 'محرك الذكاء الاصطناعي', desc: 'يحلل الإجابات النصية المكتوبة لاكتشاف ما إذا تم توليدها بواسطة ChatGPT أو أدوات مشابهة.', color: 'from-violet-500 to-purple-500' },
  { icon: '🌐', title: 'محرك تحليل الشبكة', desc: 'يرصد تواجد عدة طلاب على نفس شبكة Wi-Fi أو IP لتشخيص محاولات الغش الجماعي.', color: 'from-blue-500 to-cyan-500' },
  { icon: '👥', title: 'محرك كشف التشابه', desc: 'يقارن إجابات الطالب الحالية بجميع إجابات زملائه في نفس اللحظة لاستخراج نسبة التطابق.', color: 'from-pink-500 to-rose-500' },
  { icon: '👁️', title: 'محرك التحليل السلوكي', desc: 'يرصد مغادرة الصفحة، إخفاء التبويب، النسخ واللصق، واستخدام أدوات المطور.', color: 'from-amber-500 to-orange-500' },
  { icon: '🛡️', title: 'هيكلية SOAR أمنية', desc: 'استجابة وتنسيق أمني آلي، يعمل السيرفر بعيداً عن Moodle لحماية البيانات من العبث.', color: 'from-emerald-500 to-teal-500' },
  { icon: '⚖️', title: 'حساب الـ Risk Score', desc: 'مؤشر خطورة مئوي يجمع نتائج المحركات الأربعة لتصنيف الطلاب فوراً بالألوان.', color: 'from-indigo-500 to-blue-500' },
  { icon: '📊', title: 'لوحة قيادة مركزية', desc: 'نظرة شاملة لمستويات الخطورة والتهديدات المسجلة بتقارير وتحليلات فورية.', color: 'from-cyan-500 to-blue-500' },
  { icon: '🔁', title: 'تزامن آلي وآمن', desc: 'جلب آلي ومشفر لبيانات الطلاب والامتحانات دون الحاجة لأي تدخل يدوي.', color: 'from-rose-500 to-pink-500' },
]

const STEPS = [
  {
    n: '١',
    title: 'ثبّت الإضافة',
    desc: 'أمر واحد على خادم مودل يُنزّل الإضافة ويُفعّلها، ثم اربطها بموقع المنصة من إعدادات مودل.',
    icon: (
      <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round">
        <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4m4-5 5 5 5-5m-5 5V3" />
      </svg>
    ),
  },
  {
    n: '٢',
    title: 'الطالب يؤدي الامتحان',
    desc: 'أثناء الامتحان ترصد الإضافة داخل متصفح الطالب كل السلوكيات المشبوهة بشكل فوري وذكي.',
    icon: (
      <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round">
        <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z" />
        <circle cx="12" cy="12" r="3" />
      </svg>
    ),
  },
  {
    n: '٣',
    title: 'شاهد النتائج',
    desc: 'تظهر نِسَب الغش لكل طالب لحظياً في لوحة التحكم مع تفاصيل كل حدث وتقارير قابلة للتصدير.',
    icon: (
      <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round">
        <path d="M18 20V10m-6 10V4M6 20v-6" />
      </svg>
    ),
  },
]

const FORMULA_CHIPS = [
  { label: 'أدوات المطوّر', pct: '12%', color: 'bg-violet-500/20 text-violet-300' },
  { label: 'لقطة شاشة', pct: '10%', color: 'bg-rose-500/20 text-rose-300' },
  { label: 'مفاتيح مشبوهة', pct: '8%', color: 'bg-amber-500/20 text-amber-300' },
  { label: 'لصق', pct: '8%', color: 'bg-orange-500/20 text-orange-300' },
  { label: 'إخفاء التبويب', pct: '8%', color: 'bg-cyan-500/20 text-cyan-300' },
  { label: 'مغادرة الصفحة', pct: '8%', color: 'bg-emerald-500/20 text-emerald-300' },
  { label: 'نسخ', pct: '6%', color: 'bg-blue-500/20 text-blue-300' },
  { label: 'انقطاع النت', pct: '5%', color: 'bg-pink-500/20 text-pink-300' },
]

// Animated counter component
function AnimatedCounter({ value, suffix = '', duration = 2000 }) {
  const [count, setCount] = useState(0)
  const ref = useRef(null)
  const hasAnimated = useRef(false)

  useEffect(() => {
    const observer = new IntersectionObserver(
      ([entry]) => {
        if (entry.isIntersecting && !hasAnimated.current) {
          hasAnimated.current = true
          const numericValue = parseInt(value.replace(/[^0-9]/g, ''), 10)
          if (isNaN(numericValue)) return

          const startTime = Date.now()
          const animate = () => {
            const elapsed = Date.now() - startTime
            const progress = Math.min(elapsed / duration, 1)
            const eased = 1 - Math.pow(1 - progress, 3)
            setCount(Math.floor(numericValue * eased))
            if (progress < 1) requestAnimationFrame(animate)
          }
          requestAnimationFrame(animate)
        }
      },
      { threshold: 0.5 }
    )
    if (ref.current) observer.observe(ref.current)
    return () => observer.disconnect()
  }, [value, duration])

  return (
    <span ref={ref}>
      {count.toLocaleString('ar-EG')}{suffix}
    </span>
  )
}

function Avatar({ name, photo }) {
  const initials = name
    .split(' ')
    .slice(-2)
    .map((w) => w[0])
    .join(' ')
  if (photo) {
    return <img src={photo} alt={name} className="h-full w-full object-cover" />
  }
  return (
    <div className="flex h-full w-full items-center justify-center bg-gradient-to-br from-brand-500 to-violet-600 text-2xl font-extrabold text-white">
      {initials}
    </div>
  )
}

export default function PublicSite() {
  return (
    <div className="min-h-screen bg-white text-slate-800" dir="rtl">
      {/* Navbar */}
      <header className="sticky top-0 z-50 border-b border-slate-100 bg-white/80 backdrop-blur-xl">
        <div className="flex items-center justify-between px-5 py-3.5 lg:px-8">
          <div className="flex items-center gap-2.5">
            <div className="flex h-10 w-10 items-center justify-center rounded-xl bg-gradient-to-br from-brand-500 to-violet-600 text-white shadow-lg shadow-brand-600/25 transition-transform hover:scale-105">
              <svg width="20" height="20" viewBox="0 0 24 24" fill="none">
                <path d="M7 3h10a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2Z" stroke="currentColor" strokeWidth="2" strokeLinecap="round" />
                <path d="M12 8v8M8 11v2" stroke="currentColor" strokeWidth="2" strokeLinecap="round" />
                <circle cx="16" cy="10" r="1.6" fill="currentColor" />
              </svg>
            </div>
            <div className="leading-tight">
              <p className="text-sm font-extrabold text-slate-800">{SITE.name}</p>
              <p className="text-[11px] font-semibold text-slate-400">{SITE.tagline}</p>
            </div>
          </div>
          <nav className="hidden items-center gap-6 text-sm font-bold text-slate-500 md:flex">
            <a href="#features" className="transition-colors hover:text-brand-600">المميزات</a>
            <a href="#how" className="transition-colors hover:text-brand-600">كيف يعمل</a>
            <a href="#install" className="transition-colors hover:text-brand-600">التثبيت</a>
            <a href="#pricing" className="transition-colors hover:text-brand-600">الأسعار</a>
            <a href="#team" className="transition-colors hover:text-brand-600">الفريق</a>
          </nav>
          <Link
            to="/admin"
            className="flex items-center gap-2 rounded-xl bg-slate-900 px-4 py-2 text-xs font-bold text-white transition-all hover:bg-slate-700 hover:shadow-lg active:scale-[.98]"
          >
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2.2" strokeLinecap="round">
              <path d="M12 11a4 4 0 1 0 0-8 4 4 0 0 0 0 8Zm-7 9a7 7 0 0 1 14 0M5 21h14" />
            </svg>
            دخول لوحة التحكم
          </Link>
        </div>
      </header>

      {/* Hero */}
      <section className="relative overflow-hidden">
        <div className="pointer-events-none absolute inset-0">
          <div className="absolute -top-32 right-1/4 h-96 w-96 rounded-full bg-brand-200/50 blur-3xl animate-float" />
          <div className="absolute -bottom-24 left-1/4 h-80 w-80 rounded-full bg-violet-200/50 blur-3xl animate-float" style={{ animationDelay: '-4s' }} />
        </div>
        <div className={`${SECTION} relative py-20 text-center lg:py-28`}>
          <span className="inline-flex items-center gap-2 rounded-full bg-brand-50 px-4 py-1.5 text-xs font-bold text-brand-700 ring-1 ring-brand-100 animate-fade-up">
            <span className="relative flex h-2 w-2">
              <span className="absolute inline-flex h-full w-full animate-ping rounded-full bg-emerald-400 opacity-75" />
              <span className="relative inline-flex h-2 w-2 rounded-full bg-emerald-500" />
            </span>
            إضافة رسمية لـ Moodle · جاهزة للتثبيت
          </span>
          <h1 className="mx-auto mt-6 max-w-3xl text-4xl font-extrabold leading-[1.2] text-slate-900 sm:text-5xl lg:text-6xl animate-fade-up" style={{ animationDelay: '60ms' }}>
            نظام <span className="bg-gradient-to-l from-brand-600 to-violet-600 bg-clip-text text-transparent">SOAR أمني مستقل</span>
            <br /> لحماية امتحانات مودل
          </h1>
          <p className="mx-auto mt-6 max-w-2xl text-base leading-relaxed text-slate-500 sm:text-lg animate-fade-up" style={{ animationDelay: '120ms' }}>
            نعمل كخادم مستقل ومنفصل تماماً عن Moodle لضمان الأمان والأداء العالي. نجمع التهديدات (Threats) عبر ٤ محركات (سلوكي، شبكة، ذكاء اصطناعي، تشابه) لتوليد مؤشر خطورة (Risk Score) لكل طالب.
          </p>
          <div className="mt-9 flex flex-wrap items-center justify-center gap-3 animate-fade-up" style={{ animationDelay: '180ms' }}>
            <a
              href="#install"
              className="flex items-center gap-2 rounded-xl bg-gradient-to-l from-brand-600 to-violet-600 px-6 py-3 text-sm font-extrabold text-white shadow-xl shadow-brand-600/30 transition-all hover:shadow-2xl hover:shadow-brand-600/40 hover:-translate-y-0.5 active:scale-[.98]"
            >
              ابدأ التثبيت خلال دقائق
              <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2.5" strokeLinecap="round">
                <path d="m9 18 6-6-6-6" />
              </svg>
            </a>
            <a
              href="#features"
              className="flex items-center gap-2 rounded-xl bg-white px-6 py-3 text-sm font-extrabold text-slate-700 ring-1 ring-slate-200 transition-all hover:bg-slate-50 hover:shadow-md hover:-translate-y-0.5"
            >
              تعرّف على المميزات
            </a>
          </div>

          {/* Mock dashboard with 3D tilt */}
          <Tilt max={8} className="mx-auto mt-16 max-w-4xl">
            <div className="animate-fade-up" style={{ animationDelay: '240ms' }}>
              <div className="overflow-hidden rounded-3xl bg-white text-right shadow-[0_40px_100px_-30px_rgba(16,24,40,.35)] ring-1 ring-slate-200">
                <div className="flex items-center gap-2 border-b border-slate-100 bg-slate-50/70 px-5 py-3">
                  <span className="h-2.5 w-2.5 rounded-full bg-rose-400" />
                  <span className="h-2.5 w-2.5 rounded-full bg-amber-400" />
                  <span className="h-2.5 w-2.5 rounded-full bg-emerald-400" />
                  <span className="mr-2 text-[11px] font-bold text-slate-400">لوحة مراقبة الامتحان — مباشر</span>
                </div>
                <div className="grid grid-cols-2 gap-3 p-5 sm:grid-cols-4">
                  {[
                    { l: 'إجمالي التهديدات', v: '12,480', c: 'text-brand-600' },
                    { l: 'طلاب نشطون', v: '46', c: 'text-violet-600' },
                    { l: 'حالات حرجة', v: '3', c: 'text-rose-600' },
                    { l: 'متوسط مؤشر الخطورة', v: '18%', c: 'text-amber-600' },
                  ].map((s) => (
                    <div key={s.l} className="rounded-xl bg-slate-50 p-3 text-center transition-all hover:bg-slate-100 hover:shadow-sm">
                      <p className="text-[10px] font-semibold text-slate-400">{s.l}</p>
                      <p className={`mt-1 text-xl font-extrabold tabular-nums ${s.c}`}>{s.v}</p>
                    </div>
                  ))}
                </div>
                <div className="space-y-2 px-5 pb-5">
                  {[
                    { n: 'أحمد سمير', s: 87, l: 'حرج', c: 'bg-rose-500' },
                    { n: 'محمد خالد', s: 64, l: 'مرتفع', c: 'bg-orange-500' },
                    { n: 'سارة أحمد', s: 42, l: 'متوسط', c: 'bg-amber-500' },
                    { n: 'ليلى حسن', s: 12, l: 'منخفض', c: 'bg-teal-500' },
                  ].map((r) => (
                    <div key={r.n} className="flex items-center gap-3 rounded-xl border border-slate-100 px-3 py-2 transition-all hover:border-slate-200 hover:bg-slate-50 hover:shadow-sm">
                      <div className="flex h-8 w-8 items-center justify-center rounded-full bg-slate-100 text-[11px] font-extrabold text-slate-500">{r.n[0]}</div>
                      <p className="flex-1 text-sm font-bold text-slate-700">{r.n}</p>
                      <div className="h-2 w-32 overflow-hidden rounded-full bg-slate-100">
                        <div className={`h-full rounded-full transition-all duration-1000 ${r.c}`} style={{ width: `${r.s}%` }} />
                      </div>
                      <span className="text-xs font-extrabold tabular-nums text-slate-600">{r.s}%</span>
                      <span className="w-12 rounded-md bg-slate-100 px-2 py-0.5 text-center text-[10px] font-bold text-slate-500">{r.l}</span>
                    </div>
                  ))}
                </div>
              </div>
            </div>
          </Tilt>
        </div>
      </section>

      {/* Stats with animated counters */}
      <section className="border-y border-slate-100 bg-slate-50/60">
        <div className={`${SECTION} grid grid-cols-2 gap-4 py-10 sm:grid-cols-4`}>
          {[
            { v: 4, suffix: '', l: 'محركات ذكية للرصد', icon: '🧠' },
            { v: 100, suffix: '%', l: 'فصل تام عن سيرفر Moodle', icon: '🛡️' },
            { v: 5, suffix: '', l: 'دقائق التثبيت', icon: '⚡' },
            { v: 7, suffix: ' أيام', l: 'نسخة تجريبية', icon: '🎁' },
          ].map((s) => (
            <div key={s.l} className="text-center transition-all hover:scale-105">
              <p className="text-3xl font-extrabold text-slate-900">
                <AnimatedCounter value={String(s.v)} suffix={s.suffix} />
              </p>
              <p className="mt-1 text-sm font-semibold text-slate-500">{s.l}</p>
            </div>
          ))}
        </div>
      </section>

      {/* Features with enhanced cards */}
      <section id="features" className="py-20 lg:py-24">
        <div className={`${SECTION}`}>
          <div className="mx-auto max-w-2xl text-center">
            <span className="rounded-full bg-brand-50 px-3 py-1 text-xs font-bold text-brand-700 ring-1 ring-brand-100">المحركات الأربعة</span>
            <h2 className="mt-4 text-3xl font-extrabold text-slate-900">ماذا يرصد خادم التحليل؟</h2>
            <p className="mt-3 text-slate-500">
              نجمع التهديدات (Threats) ونحللها عبر أربعة محركات متطورة لإنتاج درجة خطورة دقيقة (Risk Score).
            </p>
          </div>
          <div className="mt-12 grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
            {FEATURES.map((f, i) => (
              <Reveal key={f.title} delay={i * 60}>
                <GlowCard className="h-full p-6 transition-all duration-300 hover:-translate-y-2 hover:shadow-[0_20px_50px_-20px_rgba(16,24,40,.2)]">
                  <div className={`flex h-12 w-12 items-center justify-center rounded-xl bg-gradient-to-br ${f.color} text-xl text-white shadow-lg`}>
                    {f.icon}
                  </div>
                  <h3 className="mt-4 text-sm font-extrabold text-slate-800">{f.title}</h3>
                  <p className="mt-1.5 text-xs leading-relaxed text-slate-500">{f.desc}</p>
                </GlowCard>
              </Reveal>
            ))}
          </div>
        </div>
      </section>

      {/* How it works with enhanced steps */}
      <section id="how" className="border-y border-slate-100 bg-slate-50/60 py-20 lg:py-24">
        <div className={`${SECTION}`}>
          <div className="mx-auto max-w-2xl text-center">
            <span className="rounded-full bg-violet-50 px-3 py-1 text-xs font-bold text-violet-700 ring-1 ring-violet-100">كيف يعمل</span>
            <h2 className="mt-4 text-3xl font-extrabold text-slate-900">كيف يعمل المشروع؟</h2>
            <p className="mt-3 text-slate-500">ثلاث خطوات تفصلك عن أول تقرير غش موثوق.</p>
          </div>
          <div className="mt-12 grid grid-cols-1 gap-6 md:grid-cols-3">
            {STEPS.map((s, i) => (
              <Reveal key={s.n} delay={i * 100}>
                <div className="relative h-full rounded-2xl bg-white p-6 ring-1 ring-slate-200/70 transition-all duration-300 hover:-translate-y-2 hover:shadow-[0_20px_50px_-20px_rgba(16,24,40,.15)]">
                  {i < STEPS.length - 1 && (
                    <div className="absolute left-0 top-1/2 hidden h-px w-1/3 bg-gradient-to-l from-brand-200 to-transparent md:block" />
                  )}
                  <div className="flex h-14 w-14 items-center justify-center rounded-2xl bg-gradient-to-br from-brand-500 to-violet-600 text-white shadow-lg shadow-brand-600/25">
                    {s.icon}
                  </div>
                  <div className="mt-4 flex items-center gap-3">
                    <span className="flex h-8 w-8 items-center justify-center rounded-full bg-brand-50 text-sm font-extrabold text-brand-600">{s.n}</span>
                    <h3 className="text-base font-extrabold text-slate-800">{s.title}</h3>
                  </div>
                  <p className="mt-3 text-sm leading-relaxed text-slate-500">{s.desc}</p>
                </div>
              </Reveal>
            ))}
          </div>
        </div>
      </section>

      {/* Formula highlight with 3D effect */}
      <section className="py-20 lg:py-24">
        <div className={`${SECTION} grid items-center gap-10 lg:grid-cols-2`}>
          <Reveal>
            <div>
              <span className="rounded-full bg-violet-50 px-3 py-1 text-xs font-bold text-violet-700 ring-1 ring-violet-100">معادلة الغش</span>
              <h2 className="mt-4 text-3xl font-extrabold leading-tight text-slate-900">
                أنت من يضع القواعد،
                <br />ليس النظام
              </h2>
              <p className="mt-4 leading-relaxed text-slate-500">
                من لوحة التحكم افتح «معادلة الغش» وحدّد بنفسك نسبة كل محدد من 0 إلى 100%، أضف محددات
                جديدة أو أوقف غير المهم — والنسبة تتحول مباشرة إلى نسبة غش لكل طالب:
              </p>
              <ul className="mt-6 space-y-3 text-sm font-semibold text-slate-600">
                {[
                  'نسبة الطالب = مجموع نسب المحددات التي قام بها',
                  'مثال: لقطة شاشة (10%) + إخفاء التبويب (8%) = 18%',
                  'زر واحد يعيد حساب كل الجلسات المسجلة بالمعادلة الجديدة',
                ].map((li) => (
                  <li key={li} className="flex items-start gap-2.5">
                    <span className="mt-0.5 flex h-5 w-5 shrink-0 items-center justify-center rounded-full bg-emerald-100 text-emerald-600">
                      <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="3" strokeLinecap="round">
                        <path d="M20 6 9 17l-5-5" />
                      </svg>
                    </span>
                    {li}
                  </li>
                ))}
              </ul>
            </div>
          </Reveal>
          <Tilt max={6}>
            <div className="rounded-3xl bg-slate-900 p-6 shadow-2xl">
              <div className="flex items-center justify-between">
                <p className="text-sm font-extrabold text-white">المعادلة الافتراضية</p>
                <span className="rounded-full bg-emerald-500/20 px-3 py-1 text-xs font-bold text-emerald-300">المجموع 100%</span>
              </div>
              <div className="mt-5 flex flex-wrap gap-2">
                {FORMULA_CHIPS.map((c) => (
                  <div key={c.label} className={`flex items-center gap-2 rounded-xl px-3 py-2 ring-1 ring-white/10 transition-all hover:ring-white/20 hover:scale-105 ${c.color}`}>
                    <span className="text-xs font-bold">{c.label}</span>
                    <span className="text-xs font-extrabold">{c.pct}</span>
                  </div>
                ))}
              </div>
              <div className="mt-6 rounded-2xl bg-white/5 p-4 ring-1 ring-white/10">
                <p className="text-xs font-bold text-slate-300">مثال حساب طالب قام بـ:</p>
                <div className="mt-2 flex flex-wrap items-center gap-2 text-xs font-bold">
                  <span className="rounded-lg bg-rose-500/20 px-2.5 py-1 text-rose-300">لقطة شاشة +10%</span>
                  <span className="rounded-lg bg-rose-500/20 px-2.5 py-1 text-rose-300">إخفاء تبويب +8%</span>
                  <span className="text-slate-400">=</span>
                  <span className="rounded-lg bg-emerald-500/20 px-2.5 py-1 text-emerald-300">18% غش محتمل</span>
                </div>
              </div>
            </div>
          </Tilt>
        </div>
      </section>

      {/* How to start */}
      <section id="install" className="border-y border-slate-100 bg-slate-50/60 py-20 lg:py-24">
        <div className={`${SECTION}`}>
          <div className="mx-auto max-w-2xl text-center">
            <span className="rounded-full bg-emerald-50 px-3 py-1 text-xs font-bold text-emerald-700 ring-1 ring-emerald-100">كيف تبدأ</span>
            <h2 className="mt-4 text-3xl font-extrabold text-slate-900">أربع خطوات بسيطة تفصلك عن أول امتحان مراقب</h2>
            <p className="mt-3 text-slate-500">
              أنشئ حساباً مجانياً وسنرشدك خطوة بخطوة داخل لوحة التحكم مع شريط تقدم يوضح ما أنجزته وما تبقّى — دون أي خبرة برمجية.
            </p>
          </div>
          <div className="mx-auto mt-12 grid max-w-4xl grid-cols-1 gap-5 md:grid-cols-4">
            {[
              { n: '١', t: 'أنشئ حسابك', d: 'حساب مجاني مع نسخة تجريبية كاملة لمدة 7 أيام.' },
              { n: '٢', t: 'نزّل الإضافة', d: 'سكربت جاهز يبحث عن المسار ويثبّت الإضافة نيابة عنك، أو يدوياً بدقائق.' },
              { n: '٣', t: 'اربط المنصة', d: 'انسخ مفتاحك الخاص من حسابك وألصقه في إعدادات مودل.' },
              { n: '٤', t: 'راقب امتحاناتك', d: 'فعّل المراقبة على أي اختبار وابدأ استقبال النتائج فوراً.' },
            ].map((s, i) => (
              <Reveal key={s.n} delay={i * 80}>
                <div className="h-full rounded-2xl bg-white p-5 ring-1 ring-slate-200/70 transition-all duration-300 hover:-translate-y-2 hover:shadow-[0_20px_50px_-20px_rgba(16,24,40,.15)]">
                  <div className="flex h-10 w-10 items-center justify-center rounded-xl bg-gradient-to-br from-brand-500 to-violet-600 text-sm font-extrabold text-white shadow-lg shadow-brand-600/25">
                    {s.n}
                  </div>
                  <h3 className="mt-3 text-sm font-extrabold text-slate-800">{s.t}</h3>
                  <p className="mt-1.5 text-xs leading-relaxed text-slate-500">{s.d}</p>
                </div>
              </Reveal>
            ))}
          </div>
          <div className="mx-auto mt-10 max-w-3xl">
            <Terminal lines={SITE.installCommands} />
          </div>
          <div className="mt-8 text-center">
            <Link
              to="/register"
              className="inline-flex items-center gap-2 rounded-xl bg-gradient-to-l from-brand-600 to-violet-600 px-8 py-3.5 text-sm font-extrabold text-white shadow-xl shadow-brand-600/30 transition-all hover:shadow-2xl hover:-translate-y-0.5 active:scale-[.98]"
            >
              أنشئ حسابك المجاني وابدأ الآن
              <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2.5" strokeLinecap="round">
                <path d="m9 18 6-6-6-6" />
              </svg>
            </Link>
            <p className="mt-3 text-[11px] font-semibold text-slate-400">
              بعد التسجيل ستجد دليلاً كاملاً خطوة بخطوة داخل حسابك مع متابعة التقدّم.
            </p>
          </div>
        </div>
      </section>

      {/* Pricing / Activation */}
      <section id="pricing" className="py-20 lg:py-24">
        <div className={`${SECTION}`}>
          <div className="mx-auto max-w-2xl text-center">
            <span className="rounded-full bg-brand-50 px-3 py-1 text-xs font-bold text-brand-700 ring-1 ring-brand-100">الأسعار</span>
            <h2 className="mt-4 text-3xl font-extrabold text-slate-900">ابدأ مجاناً اليوم</h2>
            <p className="mt-3 text-slate-500">
              جرّب المنصة بكامل ميزاتها لمدة 7 أيام، ثم فعّلها بمفتاح ترخيص.
            </p>
          </div>
          <div className="mx-auto mt-12 grid max-w-4xl grid-cols-1 gap-5 md:grid-cols-2">
            <div className="rounded-3xl border-2 border-brand-500 bg-white p-7 shadow-xl shadow-brand-600/10 transition-all hover:shadow-2xl hover:-translate-y-1">
              <span className="rounded-full bg-brand-50 px-3 py-1 text-xs font-bold text-brand-700">الأكثر شعبية</span>
              <h3 className="mt-4 text-xl font-extrabold text-slate-900">نسخة تجريبية</h3>
              <p className="mt-1 text-3xl font-extrabold text-slate-900">
                مجاناً
                <span className="text-sm font-semibold text-slate-400"> / 7 أيام</span>
              </p>
              <ul className="mt-5 space-y-2.5 text-sm font-semibold text-slate-600">
                {['كامل الميزات والرصد', 'معادلة الغش قابلة للتعديل', 'تقارير وبيانات غير محدودة', 'بدون بطاقة ائتمان'].map((li) => (
                  <li key={li} className="flex items-center gap-2.5">
                    <span className="flex h-5 w-5 items-center justify-center rounded-full bg-emerald-100 text-emerald-600">
                      <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="3" strokeLinecap="round"><path d="M20 6 9 17l-5-5" /></svg>
                    </span>
                    {li}
                  </li>
                ))}
              </ul>
              <Link to="/admin" className="mt-7 block rounded-xl bg-gradient-to-l from-brand-600 to-violet-600 py-3 text-center text-sm font-extrabold text-white shadow-lg shadow-brand-600/25 transition-all hover:shadow-xl hover:-translate-y-0.5 active:scale-[.98]">
                ابدأ التجربة الآن
              </Link>
            </div>
            <div className="rounded-3xl bg-slate-900 p-7 text-white transition-all hover:shadow-2xl hover:-translate-y-1">
              <span className="rounded-full bg-white/10 px-3 py-1 text-xs font-bold text-slate-200">ترخيص كامل</span>
              <h3 className="mt-4 text-xl font-extrabold">مفتاح الترخيص</h3>
              <p className="mt-1 text-3xl font-extrabold">
                تواصل معنا
                <span className="text-sm font-semibold text-slate-400"> / للأسعار</span>
              </p>
              <ul className="mt-5 space-y-2.5 text-sm font-semibold text-slate-300">
                {['استخدام تجاري بلا قيود', 'تحديثات مستقبلية', 'مفتاح خاص بموقعك', 'دعم مباشر'].map((li) => (
                  <li key={li} className="flex items-center gap-2.5">
                    <span className="flex h-5 w-5 items-center justify-center rounded-full bg-emerald-500/20 text-emerald-300">
                      <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="3" strokeLinecap="round"><path d="M20 6 9 17l-5-5" /></svg>
                    </span>
                    {li}
                  </li>
                ))}
              </ul>
              <Link to="/admin" className="mt-7 block rounded-xl bg-white py-3 text-center text-sm font-extrabold text-slate-900 transition-all hover:bg-slate-100 active:scale-[.98]">
                أدخل مفتاح التفعيل
              </Link>
            </div>
          </div>
        </div>
      </section>

      {/* Team */}
      <section id="team" className="border-t border-slate-100 bg-slate-50/60 py-20 lg:py-24">
        <div className={`${SECTION}`}>
          <div className="mx-auto max-w-2xl text-center">
            <span className="rounded-full bg-violet-50 px-3 py-1 text-xs font-bold text-violet-700 ring-1 ring-violet-100">الفريق</span>
            <h2 className="mt-4 text-3xl font-extrabold text-slate-900">فريق المشروع</h2>
            <p className="mt-3 text-slate-500">أربعة شباب بنوا هذا المشروع من الفكرة إلى الإنتاج.</p>
          </div>

          {/* المشرف العام */}
          {SITE.supervisor && (
            <Reveal>
              <div className="mx-auto mt-12 flex max-w-md flex-col items-center gap-5 rounded-3xl bg-gradient-to-br from-brand-500/10 to-violet-500/10 p-8 text-center ring-1 ring-brand-200/50 backdrop-blur-sm">
                <div className="h-28 w-28 overflow-hidden rounded-full bg-white ring-4 ring-brand-200/60 shadow-lg">
                  <img src={SITE.supervisor.photo} alt={SITE.supervisor.name} className="h-full w-full object-cover" />
                </div>
                <div>
                  <h3 className="text-lg font-extrabold text-slate-900">{SITE.supervisor.name}</h3>
                  <p className="mt-1 text-sm font-bold text-brand-600">{SITE.supervisor.role}</p>
                </div>
              </div>
            </Reveal>
          )}

          <div className="mx-auto mt-12 grid max-w-4xl grid-cols-2 gap-5 lg:grid-cols-4">
            {SITE.team.map((m, i) => (
              <Reveal key={m.name} delay={i * 80}>
                <div className="group rounded-2xl bg-white p-5 text-center ring-1 ring-slate-200/70 transition-all duration-300 hover:-translate-y-2 hover:shadow-[0_20px_50px_-20px_rgba(16,24,40,.15)]">
                  <div className="mx-auto h-24 w-24 overflow-hidden rounded-2xl bg-slate-100 ring-1 ring-slate-200 transition-transform duration-300 group-hover:scale-110">
                    <Avatar name={m.name} photo={m.photo} />
                  </div>
                  <h3 className="mt-4 text-sm font-extrabold text-slate-800">{m.name}</h3>
                  <p className="mt-1 text-xs font-semibold text-slate-400">{m.role || 'فريق التطوير'}</p>
                  {m.bio && <p className="mt-2 text-[11px] leading-relaxed text-slate-500">{m.bio}</p>}
                </div>
              </Reveal>
            ))}
          </div>
        </div>
      </section>

      {/* Footer */}
      <footer className="bg-slate-900 py-10 text-slate-400">
        <div className={`${SECTION} flex flex-col items-center gap-4 text-center`}>
          <div className="flex items-center gap-2.5">
            <div className="flex h-9 w-9 items-center justify-center rounded-xl bg-gradient-to-br from-brand-500 to-violet-600 text-white">
              <svg width="17" height="17" viewBox="0 0 24 24" fill="none">
                <path d="M7 3h10a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2Z" stroke="currentColor" strokeWidth="2" strokeLinecap="round" />
                <path d="M12 8v8M8 11v2" stroke="currentColor" strokeWidth="2" strokeLinecap="round" />
              </svg>
            </div>
            <p className="text-sm font-extrabold text-white">{SITE.name}</p>
          </div>
          <p className="max-w-md text-center text-xs leading-relaxed">
            مشروع تخرج — منصة مراقبة امتحانات ذكية تعمل مع نظام إدارة التعلم Moodle. جميع الحقوق محفوظة.
          </p>
          <div className="flex items-center justify-center gap-3 sm:flex-row flex-col">
            <Link
              to="/privacy"
              className="flex items-center gap-2 rounded-xl bg-white/10 px-4 py-2 text-xs font-bold text-white transition-colors hover:bg-white/20"
            >
              <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round">
                <path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/>
              </svg>
              سياسة الأمان والخصوصية
            </Link>
            <a
              href={SITE.repoUrl}
              target="_blank"
              rel="noreferrer"
              className="flex items-center gap-2 rounded-xl bg-white/10 px-4 py-2 text-xs font-bold text-white transition-colors hover:bg-white/20"
            >
              <svg width="15" height="15" viewBox="0 0 24 24" fill="currentColor">
                <path d="M12 .3a12 12 0 0 0-3.8 23.4c.6.1.8-.3.8-.6v-2c-3.3.7-4-1.6-4-1.6-.6-1.4-1.4-1.8-1.4-1.8-1-.7.1-.7.1-.7 1.2 0 1.9 1.2 1.9 1.2 1 1.8 2.8 1.3 3.5 1 0-.8.4-1.3.7-1.6-2.7-.3-5.5-1.3-5.5-6 0-1.2.5-2.3 1.3-3.1-.2-.4-.6-1.6.1-3.2 0 0 1-.3 3.3 1.2a11.5 11.5 0 0 1 6 0C17.3 4.6 18.3 5 18.3 5c.7 1.6.2 2.8.1 3.2.8.8 1.3 1.9 1.3 3.2 0 4.6-2.8 5.6-5.5 5.9.4.4.8 1.1.8 2.2v3.3c0 .3.2.7.8.6A12 12 0 0 0 12 .3" />
              </svg>
              الكود المصدري على GitHub
            </a>
          </div>
        </div>
      </footer>
    </div>
  )
}
