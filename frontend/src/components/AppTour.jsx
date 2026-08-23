import { useState } from 'react'

const STEPS = [
  {
    icon: (
      <svg width="36" height="36" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.5" strokeLinecap="round" strokeLinejoin="round">
        <rect x="3" y="3" width="18" height="18" rx="2" />
        <path d="M3 9h18M9 21V9" />
      </svg>
    ),
    title: 'لوحة التحكم',
    desc: 'هذه صفحتك الرئيسية. تُظهر ملخصاً سريعاً لدوراتك وامتحاناتك وعدد الطلاب المُشكَك فيهم.',
  },
  {
    icon: (
      <svg width="36" height="36" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.5" strokeLinecap="round" strokeLinejoin="round">
        <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8Z" />
        <path d="M14 2v6h6M16 13H8M16 17H8M10 9H8" />
      </svg>
    ),
    title: 'الامتحانات',
    desc: 'من هنا تتصفح امتحاناتك. اضغط على أي امتحان لرؤية تفاصيل الطلاب والأحداث والنتائج.',
  },
  {
    icon: (
      <svg width="36" height="36" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.5" strokeLinecap="round" strokeLinejoin="round">
        <circle cx="12" cy="12" r="10" />
        <path d="M12 6v6l4 2" />
      </svg>
    ),
    title: 'مراقبة مباشرة',
    desc: 'تتابع أحداث الامتحان لحظة بلحظة — نسخ ولصق، تغيير نافذة، فتح أدوات المطور — كلها تظهر هنا.',
  },
  {
    icon: (
      <svg width="36" height="36" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.5" strokeLinecap="round" strokeLinejoin="round">
        <path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z" />
      </svg>
    ),
    title: 'الإجراءات المباشرة',
    desc: 'يمكنك إرسال رسالة تحذيرية للطالب، أو تقليص وقت الامتحان، أو قفل الامتحان مباشرة.',
  },
  {
    icon: (
      <svg width="36" height="36" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.5" strokeLinecap="round" strokeLinejoin="round">
        <path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z" />
        <path d="m9 12 2 2 4-4" />
      </svg>
    ),
    title: 'نسبة الخطر',
    desc: 'كل طالب له نسبة خطر مئوية تُحسب تلقائياً. الأحمر = عالي، البرتقالي = متوسط، الأخضر = طبيعي.',
  },
]

export default function AppTour({ onFinish }) {
  const [step, setStep] = useState(0)
  const current = STEPS[step]
  const isLast = step === STEPS.length - 1

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center bg-gradient-to-br from-brand-600 via-violet-600 to-brand-700 p-5">
      <div className="relative w-full max-w-sm text-center">
        <div className="mb-6 flex items-center justify-center gap-1.5">
          {STEPS.map((_, i) => (
            <div
              key={i}
              className={`h-2 rounded-full transition-all duration-300 ${
                i === step ? 'w-8 bg-white' : i < step ? 'w-2 bg-white/60' : 'w-2 bg-white/25'
              }`}
            />
          ))}
        </div>

        <div className="mb-5 flex justify-center">
          <div className="flex h-20 w-20 items-center justify-center rounded-3xl bg-white/15 text-white backdrop-blur-sm animate-fade-up">
            {current.icon}
          </div>
        </div>

        <h2 className="text-2xl font-extrabold text-white animate-fade-up" style={{ animationDelay: '50ms' }}>
          {current.title}
        </h2>
        <p className="mt-3 text-sm leading-relaxed text-white/80 animate-fade-up" style={{ animationDelay: '100ms' }}>
          {current.desc}
        </p>

        <div className="mt-8 flex gap-3 animate-fade-up" style={{ animationDelay: '150ms' }}>
          {step > 0 && (
            <button
              onClick={() => setStep(step - 1)}
              className="flex-1 rounded-xl border border-white/25 px-4 py-3 text-sm font-bold text-white transition-colors hover:bg-white/10"
            >
              السابق
            </button>
          )}
          <button
            onClick={() => (isLast ? onFinish() : setStep(step + 1))}
            className="flex-1 rounded-xl bg-white px-4 py-3 text-sm font-extrabold text-brand-700 shadow-lg transition-all hover:shadow-xl active:scale-[.98]"
          >
            {isLast ? 'ابدأ باستخدام المنصة' : 'التالي'}
          </button>
        </div>

        {!isLast && (
          <button
            onClick={onFinish}
            className="mt-4 text-xs font-bold text-white/50 transition-colors hover:text-white/80"
          >
           تخطي الجولة
          </button>
        )}
      </div>
    </div>
  )
}
