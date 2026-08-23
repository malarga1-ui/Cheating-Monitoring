import { useState, useEffect } from 'react'

const STEPS = [
  {
    title: 'مرحباً بك في منصة مراقبة الامتحانات',
    content: 'هذه هي لوحة التحكم الرئيسية. تظهر لك جميع البيانات والإحصائيات في مكان واحد، وتتحدث تلقائياً كل بضع ثوانٍ.',
    emoji: '👋',
  },
  {
    title: 'تنبيهات فورية',
    content: 'إذا كان هناك طالب يُظهر سلوكاً مشبوهاً أثناء الامتحان، سيظهر لك تنبيه هنا مباشرة مع تفاصيل ما حدث.',
    emoji: '🔔',
  },
  {
    title: 'مؤشرات المراقبة الأربعة',
    content: 'المنصة تراقب 4 مؤشرات رئيسية: السلوك (نسخ/لصق)، الشبكة (تغيير IP)، الذكاء الاصطناعي، والتشابه بين الإجابات.',
    emoji: '🔍',
  },
  {
    title: 'لوحة تحكم المدرّس',
    content: 'من بوابة المدرّس يمكنك اتخاذ إجراءات مباشرة: إرسال رسالة تحذيرية للطالب، تقليص وقت الامتحان، أو قفل الامتحان بالكامل.',
    emoji: '👨‍🏫',
  },
  {
    title: 'جاهز للبدء!',
    content: 'هذه كل ما تحتاج معرفته. استكشف الصفحات براحتك — كل زر له شرح عند مرور الماوس عليه. بالتوفيق!',
    emoji: '🚀',
  },
]

export default function GuidedTour() {
  const [show, setShow] = useState(false)
  const [step, setStep] = useState(0)

  useEffect(() => {
    const tourDone = localStorage.getItem('exammonitor_tour_done')
    if (!tourDone) {
      setTimeout(() => setShow(true), 1200)
    }
  }, [])

  function finish() {
    localStorage.setItem('exammonitor_tour_done', '1')
    setShow(false)
  }

  if (!show) return null

  const current = STEPS[step]
  const isLast = step === STEPS.length - 1

  return (
    <div className="fixed top-0 inset-x-0 z-[9999] p-4 md:p-6">
      <div className="mx-auto w-full max-w-2xl rounded-2xl border border-brand-200 bg-white p-5 shadow-2xl ring-1 ring-brand-100">
        <div className="mb-3 flex items-center gap-1.5">
          {STEPS.map((_, i) => (
            <div
              key={i}
              className={`h-1.5 flex-1 rounded-full transition-all duration-300 ${
                i < step
                  ? 'bg-brand-500'
                  : i === step
                    ? 'bg-brand-400'
                    : 'bg-slate-200'
              }`}
            />
          ))}
        </div>

        <div className="flex items-start gap-3">
          <span className="mt-0.5 text-3xl">{current.emoji}</span>
          <div className="flex-1">
            <h3 className="text-sm font-extrabold text-slate-800">{current.title}</h3>
            <p className="mt-1 text-xs leading-relaxed text-slate-500">{current.content}</p>
          </div>
        </div>

        <div className="mt-4 flex items-center gap-2 border-t border-slate-100 pt-3">
          <button
            onClick={finish}
            className="rounded-lg px-3 py-1.5 text-xs font-bold text-slate-400 transition-colors hover:bg-slate-50 hover:text-slate-600"
          >
            تخطَّ الجولة
          </button>

          <div className="ms-auto flex items-center gap-2">
            {step > 0 && (
              <button
                onClick={() => setStep((s) => s - 1)}
                className="rounded-lg border border-slate-200 px-3 py-1.5 text-xs font-bold text-slate-600 transition-colors hover:bg-slate-50"
              >
                السابق
              </button>
            )}
            <button
              onClick={() => (isLast ? finish() : setStep((s) => s + 1))}
              className="rounded-lg bg-brand-600 px-4 py-1.5 text-xs font-extrabold text-white transition-all hover:bg-brand-700 active:scale-[.98]"
            >
              {isLast ? 'ابدأ الاستخدام' : 'التالي'}
            </button>
          </div>
        </div>
      </div>
    </div>
  )
}
