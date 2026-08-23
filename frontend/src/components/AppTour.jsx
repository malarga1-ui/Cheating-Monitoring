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
    <div className="fixed inset-0 z-[100] flex items-center justify-center bg-slate-900/80 p-4 backdrop-blur-md sm:p-6 md:p-12">
      <div className="relative flex h-full w-full max-w-6xl flex-col overflow-hidden rounded-3xl bg-white shadow-2xl ring-1 ring-white/10 md:flex-row">
        
        {/* Right side - Visual/Gradient */}
        <div className="relative flex w-full flex-col justify-center bg-gradient-to-br from-brand-600 via-violet-600 to-brand-800 p-8 text-white md:w-2/5 lg:w-1/3">
          <div className="absolute inset-0 bg-[url('/grid.svg')] opacity-20 bg-center" />
          <div className="absolute -top-24 -right-24 h-48 w-48 rounded-full bg-white/10 blur-3xl" />
          <div className="absolute -bottom-24 -left-24 h-64 w-64 rounded-full bg-brand-400/20 blur-3xl" />
          
          <div className="relative z-10 flex flex-col items-center text-center">
            <div className="mb-8 flex h-24 w-24 items-center justify-center rounded-3xl bg-white/10 shadow-inner backdrop-blur-xl ring-1 ring-white/20 animate-fade-up">
              {current.icon}
            </div>
            
            <div className="flex gap-2 mb-8">
              {STEPS.map((_, i) => (
                <div
                  key={i}
                  className={`h-1.5 rounded-full transition-all duration-500 ${
                    i === step ? 'w-8 bg-white' : i < step ? 'w-2 bg-white/60' : 'w-2 bg-white/20'
                  }`}
                />
              ))}
            </div>
          </div>
        </div>

        {/* Left side - Content */}
        <div className="flex w-full flex-col justify-center bg-white p-8 md:w-3/5 md:p-12 lg:w-2/3">
          <div className="animate-fade-up" key={step}>
            <h2 className="text-3xl font-black text-slate-800 md:text-4xl lg:text-5xl">
              {current.title}
            </h2>
            <p className="mt-6 text-base font-medium leading-relaxed text-slate-500 md:text-lg">
              {current.desc}
            </p>
          </div>

          <div className="mt-12 flex items-center justify-between border-t border-slate-100 pt-8 animate-fade-up" style={{ animationDelay: '100ms' }}>
            {!isLast ? (
              <button
                onClick={onFinish}
                className="text-sm font-bold text-slate-400 transition-colors hover:text-slate-600"
              >
                تخطي الجولة
              </button>
            ) : (
              <div />
            )}

            <div className="flex items-center gap-3">
              {step > 0 && (
                <button
                  onClick={() => setStep(step - 1)}
                  className="rounded-xl border border-slate-200 px-6 py-3 text-sm font-bold text-slate-600 transition-all hover:bg-slate-50 active:scale-95"
                >
                  السابق
                </button>
              )}
              <button
                onClick={() => (isLast ? onFinish() : setStep(step + 1))}
                className="rounded-xl bg-brand-600 px-8 py-3 text-sm font-extrabold text-white shadow-lg shadow-brand-500/30 transition-all hover:bg-brand-700 hover:shadow-xl hover:shadow-brand-500/40 active:scale-95"
              >
                {isLast ? 'ابدأ الاستخدام' : 'التالي'}
              </button>
            </div>
          </div>
        </div>

      </div>
    </div>
  )
}
