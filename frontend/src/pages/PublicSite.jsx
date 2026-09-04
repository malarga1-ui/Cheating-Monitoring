import { useState, useEffect, useRef } from 'react'
import { Link } from 'react-router-dom'
import { SITE } from '../site.config'
import { Reveal, Tilt, GlowCard } from '../components/motion'
import { Terminal, PathTree, FileTree } from '../components/InstallBits'

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
  const [installTab, setInstallTab] = useState('script')
  const [copiedScript, setCopiedScript] = useState(false)

  const copyOneLiner = async () => {
    try {
      await navigator.clipboard.writeText('curl -fsSL https://jadallahkhaled.com/scripts/install_plugin.sh | bash')
      setCopiedScript(true)
      setTimeout(() => setCopiedScript(false), 2000)
    } catch {}
  }

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
            <a href="#problem-gap" className="transition-colors hover:text-brand-600">المشكلة والفجوة البحثية</a>
            <a href="#features" className="transition-colors hover:text-brand-600">المميزات</a>
            <a href="#how" className="transition-colors hover:text-brand-600">كيف يعمل</a>
            <a href="#install" className="transition-colors hover:text-brand-600">التثبيت الذكي</a>
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
            { v: 5, suffix: '', l: 'دقائق التثبيت الذكي', icon: '⚡' },
            { v: 30, suffix: ' يوماً', l: 'فترة تجريبية مجانية', icon: '🎁' },
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

      {/* Problem & Research Gap Section */}
      <section id="problem-gap" className="relative overflow-hidden py-20 lg:py-24">
        <div className="pointer-events-none absolute inset-0">
          <div className="absolute top-1/3 left-0 h-96 w-96 rounded-full bg-violet-100/40 blur-3xl" />
          <div className="absolute bottom-0 right-0 h-96 w-96 rounded-full bg-brand-100/40 blur-3xl" />
        </div>

        <div className={`${SECTION} relative`}>
          {/* Header */}
          <div className="mx-auto max-w-3xl text-center">
            <span className="inline-flex items-center gap-1.5 rounded-full bg-rose-50 px-3.5 py-1 text-xs font-bold text-rose-700 ring-1 ring-rose-200">
              <span>🎯</span>
              الإطار الأكاديمي والبحثي
            </span>
            <h2 className="mt-4 text-3xl font-extrabold text-slate-900 sm:text-4xl">
              المشكلة الواقعية والفجوة البحثية
            </h2>
            <p className="mt-3 text-base leading-relaxed text-slate-600">
              في ظل الثورة المتسارعة للذكاء الاصطناعي والتعليم الرقمي، أصبحت أنظمة المراقبة التقليدية عاجزة عن حماية النزاهة الأكاديمية. هنا نوضح جوهر الأزمة، وما أهملته الأبحاث السابقة، وكيف يسد مشروعنا هذا الفراغ العلمي والتطبيقي.
            </p>
          </div>

          {/* Part 1: Real World Problem */}
          <div className="mt-14">
            <div className="flex items-center gap-3">
              <span className="flex h-8 w-8 items-center justify-center rounded-xl bg-rose-500 text-sm font-bold text-white shadow-md shadow-rose-500/30">١</span>
              <h3 className="text-xl font-extrabold text-slate-900">المشكلة الواقعية في الامتحانات المعاصرة</h3>
            </div>
            <p className="mt-1.5 text-xs text-slate-500">أشكال الغش الرقمي الحديث التي تخترق المنظومات التقليدية وتفرغ نتائج الاختبارات من قيمتها:</p>

            <div className="mt-6 grid grid-cols-1 gap-5 md:grid-cols-3">
              <div className="rounded-2xl border border-rose-100 bg-white p-6 shadow-sm transition-all hover:shadow-md hover:border-rose-200">
                <div className="flex h-12 w-12 items-center justify-center rounded-xl bg-gradient-to-br from-rose-500 to-pink-600 text-2xl text-white shadow-md shadow-rose-500/20">
                  🤖
                </div>
                <h4 className="mt-4 text-base font-extrabold text-slate-800">طوفان الذكاء الاصطناعي (GenAI & LLMs)</h4>
                <p className="mt-2 text-xs leading-relaxed text-slate-600">
                  أدوات الذكاء الاصطناعي التوليدي مثل ChatGPT و Claude و Gemini قادرة على صياغة حلول معقدة وتحليل مقالات برمجية ونظرية في ثوانٍ معدودة. نسخ السؤال ولصق الإجابة المكتملة أفقد الامتحانات الإلكترونية قدرتها على قياس الفهم الحقيقي.
                </p>
                <div className="mt-4 rounded-xl bg-rose-50/80 p-3 text-[11px] font-semibold text-rose-800">
                  ⚠️ النتيجة: درجات ممتازة وهمية لطلاب اعتمدوا على التوليد الآلي دون بذل أي جهد فكري.
                </div>
              </div>

              <div className="rounded-2xl border border-amber-100 bg-white p-6 shadow-sm transition-all hover:shadow-md hover:border-amber-200">
                <div className="flex h-12 w-12 items-center justify-center rounded-xl bg-gradient-to-br from-amber-500 to-orange-600 text-2xl text-white shadow-md shadow-amber-500/20">
                  👥
                </div>
                <h4 className="mt-4 text-base font-extrabold text-slate-800">التواطؤ الجماعي وتعدد الأجهزة (Collusion)</h4>
                <p className="mt-2 text-xs leading-relaxed text-slate-600">
                  اجتماع الطلاب في قاعة واحدة أو استخدام هواتف ثانوية غير مرئية خلف الشاشة، وتناقل الإجابات عبر قنوات اتصال سريعة. نظم إدارة التعلم مثل Moodle ترى كل طالب ككيان مستقل تماماً ولا تملك أي رؤية ترابطية بين الطلاب.
                </p>
                <div className="mt-4 rounded-xl bg-amber-50/80 p-3 text-[11px] font-semibold text-amber-800">
                  ⚠️ النتيجة: إجابات جماعية متطابقة ممررة بين الأقران تمر دون أي تنبيه للمدرس.
                </div>
              </div>

              <div className="rounded-2xl border border-violet-100 bg-white p-6 shadow-sm transition-all hover:shadow-md hover:border-violet-200">
                <div className="flex h-12 w-12 items-center justify-center rounded-xl bg-gradient-to-br from-violet-500 to-indigo-600 text-2xl text-white shadow-md shadow-violet-500/20">
                  👁️
                </div>
                <h4 className="mt-4 text-base font-extrabold text-slate-800">معضلة المراقبة القمعية وانتهاك الخصوصية</h4>
                <p className="mt-2 text-xs leading-relaxed text-slate-600">
                  الأنظمة التجارية التقليدية تفرض فتح الكاميرا والمايكروفون ومسح غرف المنازل، مما يسبب قلقاً وتوتراً للطلبة وانتهاكاً صارخاً للخصوصية واستهلاكاً ضخماً للإنترنت، ومع ذلك تعجز عن اكتشاف ما يدور خارج زاوية الكاميرا.
                </p>
                <div className="mt-4 rounded-xl bg-violet-50/80 p-3 text-[11px] font-semibold text-violet-800">
                  ⚠️ النتيجة: انتهاك خصوصية باهظ التكلفة مع فاعلية رصد متدنية وسهلة الالتفاف.
                </div>
              </div>
            </div>
          </div>

          {/* Part 2: Research Gap & Our Scientific Novelty */}
          <div className="mt-16">
            <div className="flex items-center gap-3">
              <span className="flex h-8 w-8 items-center justify-center rounded-xl bg-brand-600 text-sm font-bold text-white shadow-md shadow-brand-600/30">٢</span>
              <h3 className="text-xl font-extrabold text-slate-900">الفجوة البحثية والمساهمة العلمية للمشروع</h3>
            </div>
            <p className="mt-1.5 text-xs text-slate-500">أين توقفت الأدبيات السابقة وماذا يقدم بحثنا لحل التحدي بطريقة علمية محكمة:</p>

            <div className="mt-6 grid grid-cols-1 gap-5 lg:grid-cols-2">
              {/* Gap 1 */}
              <div className="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm">
                <div className="flex items-center justify-between border-b border-slate-100 pb-3">
                  <span className="rounded-lg bg-red-100 px-2.5 py-1 text-[11px] font-extrabold text-red-700">الفجوة البحثية الأولى</span>
                  <span className="text-xs font-bold text-slate-400">أحادية الرصد vs الاندماج متعدد الوسائط</span>
                </div>
                <div className="mt-4 space-y-3 text-xs leading-relaxed">
                  <div className="rounded-xl bg-slate-50 p-3.5 border-r-2 border-red-500">
                    <p className="font-extrabold text-slate-700">❌ قصور الأنظمة السابقة:</p>
                    <p className="mt-1 text-slate-500">
                      تعتمد معظم الدراسات على مؤشر واحد فقط (مثل إخفاء التبويب فقط، أو فحص نصوص بعد انتهاء الاختبار)، مما يؤدي لقرارات خاطئة ومعدل إنذارات زائفة مرتفع جداً (High False-Positive Rate).
                    </p>
                  </div>
                  <div className="rounded-xl bg-emerald-50/70 p-3.5 border-r-2 border-emerald-500">
                    <p className="font-extrabold text-emerald-800">✅ ابتكار مشروعنا (Multimodal Telemetry Fusion):</p>
                    <p className="mt-1 text-emerald-700">
                      بناء نموذج اندماج رياضي متكامل يدمج ٤ أبعاد متزامنة في مؤشر خطورة موحد:
                    </p>
                    <div className="mt-2 rounded-lg bg-emerald-900/90 px-3 py-2 font-mono text-[12px] font-bold text-emerald-300 text-center" dir="ltr">
                      Risk = w_B · Behavior + w_S · Similarity + w_N · Network + w_A · AI_Gen
                    </div>
                    <p className="mt-1 text-[11px] text-emerald-600">
                      كل بعد يغطي ثغرة الآخر ليضمن دقة تشخيصية عالية وموثوقة أكاديمياً.
                    </p>
                  </div>
                </div>
              </div>

              {/* Gap 2 */}
              <div className="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm">
                <div className="flex items-center justify-between border-b border-slate-100 pb-3">
                  <span className="rounded-lg bg-red-100 px-2.5 py-1 text-[11px] font-extrabold text-red-700">الفجوة البحثية الثانية</span>
                  <span className="text-xs font-bold text-slate-400">إيجابيات كاذبة في خيارات متعددة</span>
                </div>
                <div className="mt-4 space-y-3 text-xs leading-relaxed">
                  <div className="rounded-xl bg-slate-50 p-3.5 border-r-2 border-red-500">
                    <p className="font-extrabold text-slate-700">❌ قصور الأنظمة السابقة:</p>
                    <p className="mt-1 text-slate-500">
                      محركات الانتحال الحالية تقارن كل إجابات الطلاب دون تمييز، فإذا اختار طالبان الخيار الصحيح (A) في سؤال خيارات متعددة يتم تصنيفهما كمتشابهين 100%!
                    </p>
                  </div>
                  <div className="rounded-xl bg-emerald-50/70 p-3.5 border-r-2 border-emerald-500">
                    <p className="font-extrabold text-emerald-800">✅ ابتكار مشروعنا (Selective Question-Type Intelligence):</p>
                    <p className="mt-1 text-emerald-700">
                      تصميم خوارزمي دقيق يفصل منطق التحليل بحسب نوع السؤال:
                    </p>
                    <ul className="mt-1.5 list-inside list-disc space-y-1 text-[11px] text-emerald-700">
                      <li><strong>الأسئلة المقالية:</strong> تخضع لخوارزميات التشابه التركيبي والدلالي (Jaccard + Cosine Similarity) لاكتشاف التطابق النصي.</li>
                      <li><strong>أسئلة الاختيار من متعدد:</strong> تُستثنى تماماً من فحص التشابه النصي، وتُحلل سلوكياً عبر زمن الإجابة الفائق السرعة ومحرك الشبكة.</li>
                    </ul>
                  </div>
                </div>
              </div>

              {/* Gap 3 */}
              <div className="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm">
                <div className="flex items-center justify-between border-b border-slate-100 pb-3">
                  <span className="rounded-lg bg-red-100 px-2.5 py-1 text-[11px] font-extrabold text-red-700">الفجوة البحثية الثالثة</span>
                  <span className="text-xs font-bold text-slate-400">المعالجة بعد فوات الأوان vs استجابة مغلقة الحلقة</span>
                </div>
                <div className="mt-4 space-y-3 text-xs leading-relaxed">
                  <div className="rounded-xl bg-slate-50 p-3.5 border-r-2 border-red-500">
                    <p className="font-extrabold text-slate-700">❌ قصور الأنظمة السابقة:</p>
                    <p className="mt-1 text-slate-500">
                      أغلب أدوات Moodle تعمل بنمط (Post-mortem Analysis)، حيث يكتشف الأستاذ الغش بعد ساعات أو أيام من انتهاء الامتحان وإعلان النتائج، دون أي قدرة على التدخل الفوري لمنع استمرار المخالفة.
                    </p>
                  </div>
                  <div className="rounded-xl bg-emerald-50/70 p-3.5 border-r-2 border-emerald-500">
                    <p className="font-extrabold text-emerald-800">✅ ابتكار مشروعنا (Closed-Loop SOAR Active Response):</p>
                    <p className="mt-1 text-emerald-700">
                      تطبيق معمارية التنسيق والاستجابة الأمنية الآلية (SOAR) للتدخل اللحظي أثناء الامتحان:
                    </p>
                    <ul className="mt-1.5 list-inside list-disc space-y-1 text-[11px] text-emerald-700">
                      <li><strong>تقليص الوقت ديناميكياً:</strong> خفض وقت الطالب فورياً عند تكرار المغادرة.</li>
                      <li><strong>قفل الامتحان الفوري:</strong> شاشة حظر طارئة تتطلب كود أستاذ لإعادة الفتح.</li>
                      <li><strong>تنبيهات ردع فورية:</strong> رسائل تحذير رسمية تظهر مباشرة أمام الطالب المخالف.</li>
                    </ul>
                  </div>
                </div>
              </div>

              {/* Gap 4 */}
              <div className="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm">
                <div className="flex items-center justify-between border-b border-slate-100 pb-3">
                  <span className="rounded-lg bg-red-100 px-2.5 py-1 text-[11px] font-extrabold text-red-700">الفجوة البحثية الرابعة</span>
                  <span className="text-xs font-bold text-slate-400">عبء خادم LMS وسلامة البيانات</span>
                </div>
                <div className="mt-4 space-y-3 text-xs leading-relaxed">
                  <div className="rounded-xl bg-slate-50 p-3.5 border-r-2 border-red-500">
                    <p className="font-extrabold text-slate-700">❌ قصور الأنظمة السابقة:</p>
                    <p className="mt-1 text-slate-500">
                      معالجة الغش داخل سيرفر Moodle نفسه تستهلك موارد قاعدة البيانات وتؤدي لسقوط السيرفر عند وجود مئات الطلاب في وقت واحد، فضلاً عن سهولة التلاعب بها عبر ثغرات الـ Webhooks المدمجة.
                    </p>
                  </div>
                  <div className="rounded-xl bg-emerald-50/70 p-3.5 border-r-2 border-emerald-500">
                    <p className="font-extrabold text-emerald-800">✅ ابتكار مشروعنا (Decoupled Out-of-Band Engine):</p>
                    <p className="mt-1 text-emerald-700">
                      بناء خادم تحليلي مستقل تماماً، معتمد على تدفق telemetry غير متزامن فائق السرعة عبر API خفيف لا يؤثر إطلاقاً على سيرفر Moodle التعليمي، مع أمان تشفير كامل بدون أي اعتمادية على الكاميرا.
                    </p>
                  </div>
                </div>
              </div>
            </div>
          </div>
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

      {/* How to start & Installation Section */}
      <section id="install" className="border-y border-slate-100 bg-slate-50/60 py-20 lg:py-24">
        <div className={`${SECTION}`}>
          <div className="mx-auto max-w-3xl text-center">
            <span className="rounded-full bg-emerald-50 px-3.5 py-1 text-xs font-bold text-emerald-700 ring-1 ring-emerald-200">
              ⚡ التثبيت والتهيئة السريعة
            </span>
            <h2 className="mt-4 text-3xl font-extrabold text-slate-900 sm:text-4xl">
              تثبيت سهل وسريع خلال دقيقتين فقط
            </h2>
            <p className="mt-3 text-base leading-relaxed text-slate-600">
              صممنا سكربت تثبيت ذكي يتعرف تلقائياً على مسار خادم مودل وينشئ مجلد الإضافة ويسحب أحدث نسخة من GitHub ويحدّث قاعدة البيانات فورياً — دون أي تعقيد.
            </p>
          </div>

          {/* 4 Steps */}
          <div className="mx-auto mt-12 grid max-w-5xl grid-cols-1 gap-5 sm:grid-cols-2 lg:grid-cols-4">
            {[
              { n: '١', t: 'أنشئ حسابك', d: 'سجل حساب جديد واحصل فوراً على فترة تجريبية مجانية كاملة الميزات لمدة 30 يوماً.' },
              { n: '٢', t: 'التثبيت الذكي', d: 'أمر واحد في التيرمنال يكتشف مسار مودل ويثبت الإضافة في mod/quiz/accessrule/exammonitor.' },
              { n: '٣', t: 'اربط المنصة', d: 'انسخ رابط المنصة ومفتاح الحساب السري من لوحة تحكمك وألصقهما في إعدادات مودل.' },
              { n: '٤', t: 'راقب الاختبارات', d: 'افتح إعدادات أي اختبار في مودل وفعّل «مراقبة الامتحان الذكية» لمتابعة الطلاب مباشرة.' },
            ].map((s, i) => (
              <Reveal key={s.n} delay={i * 70}>
                <div className="h-full rounded-2xl bg-white p-5 ring-1 ring-slate-200/70 transition-all duration-300 hover:-translate-y-1.5 hover:shadow-[0_20px_50px_-20px_rgba(16,24,40,.15)]">
                  <div className="flex h-10 w-10 items-center justify-center rounded-xl bg-gradient-to-br from-brand-500 to-violet-600 text-sm font-extrabold text-white shadow-lg shadow-brand-600/25">
                    {s.n}
                  </div>
                  <h3 className="mt-3 text-sm font-extrabold text-slate-800">{s.t}</h3>
                  <p className="mt-1.5 text-xs leading-relaxed text-slate-500">{s.d}</p>
                </div>
              </Reveal>
            ))}
          </div>

          {/* Interactive Install Tabs */}
          <div className="mx-auto mt-14 max-w-4xl">
            <div className="flex flex-wrap items-center justify-center gap-2 rounded-2xl bg-slate-200/70 p-1.5 text-xs font-bold text-slate-600">
              <button
                type="button"
                onClick={() => setInstallTab('script')}
                className={`flex items-center gap-2 rounded-xl px-5 py-2.5 transition-all ${
                  installTab === 'script'
                    ? 'bg-white text-brand-700 shadow-md shadow-slate-300/40'
                    : 'text-slate-600 hover:text-slate-900'
                }`}
              >
                <span>⚡</span>
                السكربت الذكي التلقائي (موصى به)
              </button>
              <button
                type="button"
                onClick={() => setInstallTab('manual')}
                className={`flex items-center gap-2 rounded-xl px-5 py-2.5 transition-all ${
                  installTab === 'manual'
                    ? 'bg-white text-brand-700 shadow-md shadow-slate-300/40'
                    : 'text-slate-600 hover:text-slate-900'
                }`}
              >
                <span>🛠️</span>
                التثبيت اليدوي خطوة بخطوة
              </button>
              <button
                type="button"
                onClick={() => setInstallTab('tree')}
                className={`flex items-center gap-2 rounded-xl px-5 py-2.5 transition-all ${
                  installTab === 'tree'
                    ? 'bg-white text-brand-700 shadow-md shadow-slate-300/40'
                    : 'text-slate-600 hover:text-slate-900'
                }`}
              >
                <span>📁</span>
                هيكلية المسارات والملفات
              </button>
            </div>

            {/* Tab 1: Smart Script */}
            {installTab === 'script' && (
              <div className="mt-6 animate-fade-up">
                <div className="overflow-hidden rounded-3xl bg-slate-900 shadow-2xl ring-1 ring-white/10">
                  <div className="flex items-center justify-between border-b border-white/10 bg-slate-950/60 px-5 py-3.5">
                    <div className="flex items-center gap-2">
                      <span className="h-3 w-3 rounded-full bg-rose-500/80" />
                      <span className="h-3 w-3 rounded-full bg-amber-500/80" />
                      <span className="h-3 w-3 rounded-full bg-emerald-500/80" />
                      <span className="mr-2 text-xs font-bold text-slate-400">Terminal — التثبيت الذكي بأمر واحد</span>
                    </div>
                    <button
                      type="button"
                      onClick={copyOneLiner}
                      className="flex items-center gap-1.5 rounded-lg bg-emerald-500/20 px-3.5 py-1.5 text-xs font-extrabold text-emerald-300 ring-1 ring-emerald-500/30 transition-all hover:bg-emerald-500/30"
                    >
                      {copiedScript ? '✓ تم النسخ بنجاح!' : '📋 نسخ الأمر'}
                    </button>
                  </div>

                  <div className="p-6">
                    <p className="text-xs font-semibold text-slate-400">
                      انسخ الأمر التالي وشغّله داخل موجه أوامر السيرفر (SSH Terminal):
                    </p>
                    <div
                      dir="ltr"
                      className="mt-3 flex items-center justify-between rounded-xl bg-slate-950 p-4 font-mono text-xs sm:text-sm text-emerald-300 ring-1 ring-white/10 overflow-x-auto"
                    >
                      <span>curl -fsSL https://jadallahkhaled.com/scripts/install_plugin.sh | bash</span>
                    </div>

                    <div className="mt-6 grid grid-cols-1 gap-3 sm:grid-cols-2 text-right">
                      <div className="flex items-start gap-2.5 rounded-xl bg-white/5 p-3.5 ring-1 ring-white/10">
                        <span className="text-emerald-400 font-bold">✓</span>
                        <div className="text-xs">
                          <p className="font-extrabold text-white">كشف المسار التلقائي</p>
                          <p className="mt-0.5 text-slate-400">يبحث السكربت عن مجلد مودل القياسي (/var/www, /home, /opt) دون الحاجة لإدخاله يدوياً.</p>
                        </div>
                      </div>
                      <div className="flex items-start gap-2.5 rounded-xl bg-white/5 p-3.5 ring-1 ring-white/10">
                        <span className="text-emerald-400 font-bold">✓</span>
                        <div className="text-xs">
                          <p className="font-extrabold text-white">إنشاء مجلد exammonitor</p>
                          <p className="mt-0.5 text-slate-400">ينشئ المجلد في المسار السليم mod/quiz/accessrule/exammonitor ويستنسخ كود GitHub.</p>
                        </div>
                      </div>
                      <div className="flex items-start gap-2.5 rounded-xl bg-white/5 p-3.5 ring-1 ring-white/10">
                        <span className="text-emerald-400 font-bold">✓</span>
                        <div className="text-xs">
                          <p className="font-extrabold text-white">ضبط الصلاحيات 755</p>
                          <p className="mt-0.5 text-slate-400">يمنح تصاريح القراءة والتشغيل الصحيحة لمستخدم الويب (www-data / apache) تلقائياً.</p>
                        </div>
                      </div>
                      <div className="flex items-start gap-2.5 rounded-xl bg-white/5 p-3.5 ring-1 ring-white/10">
                        <span className="text-emerald-400 font-bold">✓</span>
                        <div className="text-xs">
                          <p className="font-extrabold text-white">ترقية وتفعيل فورية</p>
                          <p className="mt-0.5 text-slate-400">يشغل أداة ترقية مودل (admin/cli/upgrade.php) لتسجيل قاعدة الوصول وتفعيلها فوراً.</p>
                        </div>
                      </div>
                    </div>
                  </div>
                </div>
              </div>
            )}

            {/* Tab 2: Manual Terminal Commands */}
            {installTab === 'manual' && (
              <div className="mt-6 animate-fade-up">
                <Terminal lines={SITE.installCommands} />
                <p className="mt-3 text-center text-xs text-slate-500">
                  إذا كان مجلد مودل في مسار مخصص (مثال: cPanel)، استبدل المسار بالمسار الفعلي على خادمك قبل تشغيل الأوامر.
                </p>
              </div>
            )}

            {/* Tab 3: Path & File Tree */}
            {installTab === 'tree' && (
              <div className="mt-6 grid grid-cols-1 gap-5 md:grid-cols-2 animate-fade-up">
                <div>
                  <p className="mb-2 text-xs font-bold text-slate-600">مسار الإضافة داخل مودل:</p>
                  <PathTree />
                </div>
                <div>
                  <p className="mb-2 text-xs font-bold text-slate-600">الملفات والمجلدات الأساسية للإضافة:</p>
                  <FileTree />
                </div>
              </div>
            )}
          </div>

          {/* Important Technical & Operating Notes */}
          <div className="mx-auto mt-16 max-w-4xl">
            <div className="rounded-3xl border border-slate-200 bg-white p-7 shadow-sm">
              <div className="flex items-center gap-3 border-b border-slate-100 pb-4">
                <span className="flex h-8 w-8 items-center justify-center rounded-xl bg-amber-500 text-sm font-bold text-white shadow-md shadow-amber-500/30">💡</span>
                <h3 className="text-base font-extrabold text-slate-800">ملاحظات تشغيلية هامة لإدارة مودل والمعلمين</h3>
              </div>

              <div className="mt-5 grid grid-cols-1 gap-4 sm:grid-cols-2 text-xs leading-relaxed">
                <div className="rounded-xl bg-slate-50 p-4 border-r-2 border-brand-500">
                  <p className="font-extrabold text-slate-800">🧩 التوافقية الشاملة:</p>
                  <p className="mt-1 text-slate-600">
                    الإضافة متوافقة مع إصدارات Moodle 3.9 و 3.10 و 3.11 وجميع إصدارات Moodle 4.x، وتعمل تلقائياً دون الحاجة لتعديل أي كود في نواة مودل.
                  </p>
                </div>
                <div className="rounded-xl bg-slate-50 p-4 border-r-2 border-emerald-500">
                  <p className="font-extrabold text-slate-800">🔒 خصوصية تامة بدون كاميرا أو مايكروفون:</p>
                  <p className="mt-1 text-slate-600">
                    لا تطلب الإضافة أي أذونات وصول للكاميرا أو الصوت، وتعتمد فقط على رصد السلوك والأحداث المشبوهة، مما يحمي خصوصية الطلبة ويوفر استهلاك البيانات.
                  </p>
                </div>
                <div className="rounded-xl bg-slate-50 p-4 border-r-2 border-violet-500">
                  <p className="font-extrabold text-slate-800">⚙️ كيفية التفعيل على الامتحان:</p>
                  <p className="mt-1 text-slate-600">
                    في إعدادات الاختبار بمودل: افتح تبويب <strong>«قيود إضافية على المحاولات»</strong> واختر <strong>«نعم»</strong> أمام خيار <strong>«مراقبة الامتحان الذكية»</strong>.
                  </p>
                </div>
                <div className="rounded-xl bg-slate-50 p-4 border-r-2 border-amber-500">
                  <p className="font-extrabold text-slate-800">🔑 الربط السري مع المنصة:</p>
                  <p className="mt-1 text-slate-600">
                    من إدارة الموقع في مودل: انتقل إلى (قواعد وصول الاختبارات &gt; مراقب الامتحانات) وضع رابط المنصة ومفتاح الحساب السري الخاص بك.
                  </p>
                </div>
              </div>
            </div>
          </div>

          <div className="mt-10 text-center">
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
              بعد التسجيل ستحصل على مفتاح الربط الخاص بك ودليل تفاعلي متكامل خطوة بخطوة.
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
              جرّب المنصة بكامل ميزاتها لمدة 30 يوماً، ثم فعّلها بمفتاح ترخيص.
            </p>
          </div>
          <div className="mx-auto mt-12 grid max-w-4xl grid-cols-1 gap-5 md:grid-cols-2">
            <div className="rounded-3xl border-2 border-brand-500 bg-white p-7 shadow-xl shadow-brand-600/10 transition-all hover:shadow-2xl hover:-translate-y-1">
              <span className="rounded-full bg-brand-50 px-3 py-1 text-xs font-bold text-brand-700">الأكثر شعبية</span>
              <h3 className="mt-4 text-xl font-extrabold text-slate-900">نسخة تجريبية</h3>
              <p className="mt-1 text-3xl font-extrabold text-slate-900">
                مجاناً
                <span className="text-sm font-semibold text-slate-400"> / 30 يوماً</span>
              </p>
              <ul className="mt-5 space-y-2.5 text-sm font-semibold text-slate-600">
                {['كامل الميزات والرصد والمحركات الأربعة', 'إجراءات الردع الآلية وتقليص الوقت والقفل', 'معادلة الغش قابلة للتعديل', 'تقارير وبيانات غير محدودة', 'بدون بطاقة ائتمان'].map((li) => (
                  <li key={li} className="flex items-center gap-2.5">
                    <span className="flex h-5 w-5 items-center justify-center rounded-full bg-emerald-100 text-emerald-600">
                      <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="3" strokeLinecap="round"><path d="M20 6 9 17l-5-5" /></svg>
                    </span>
                    {li}
                  </li>
                ))}
              </ul>
              <Link to="/register" className="mt-7 block rounded-xl bg-gradient-to-l from-brand-600 to-violet-600 py-3 text-center text-sm font-extrabold text-white shadow-lg shadow-brand-600/25 transition-all hover:shadow-xl hover:-translate-y-0.5 active:scale-[.98]">
                ابدأ التجربة المجانية (30 يوماً)
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
                {['استخدام مؤسسي وتجاري بلا قيود', 'تحديثات مستمرة للإضافة والخادم', 'مفتاح خاص بكل مؤسسة تعليمية', 'دعم فني واستشاري مباشر'].map((li) => (
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
