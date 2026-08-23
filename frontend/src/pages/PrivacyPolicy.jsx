import { SITE } from '../site.config'
import { Link } from 'react-router-dom'
import { Reveal } from '../components/motion'

const SECTION = 'mx-auto w-full max-w-4xl px-5 lg:px-8'

const WHAT_WE_READ = [
  { icon: '🖥️', title: 'أحداث المتصفح', desc: 'مغادرة التبويب، النقر الأيمن، النسخ واللصق، أدوات المطوّر، لقطات الشاشة.' },
  { icon: '⌨️', title: 'سلوك الكتابة', desc: 'سرعة الكتابة، فترات التوقف، عدد الضغطات على المفاتيح.' },
  { icon: '📝', title: 'سجل الإجابات', desc: 'daeeyael text المُرسل من نموذج الإجابة، عدد الكلمات، عدد التعديلات.' },
  { icon: '🌐', title: 'عنوان IP', desc: 'يُستخدم فقط لكشف تلامح الطلاب على نفس العنوان (شبكة واحدة).' },
]

const WHAT_WE_DONT = [
  { icon: '🔒', title: 'كلمات المرور', desc: 'لا نقرأ أو نخزن أي كلمة مرور — لا لمدرّس ولا لطالب.' },
  { icon: '📊', title: 'الدرجات', desc: 'لا نستطيع قراءة أو تعديل أي درجة في Moodle. الإضافة للرصد فقط.' },
  { icon: '📂', title: 'ملفات شخصية', desc: 'لا نقرأ ملفات الطالب أو الملفات المرفوعة على Moodle.' },
  { icon: '🛡️', title: 'بيانات الدخول', desc: 'لا نخزّن جلسات ولا رموز CSRF ولا أي بيانات حساسة.' },
]

const SECURITY = [
  { icon: '🔐', title: 'تشفير الاتصال', desc: 'جميع البيانات تُرسل عبر HTTPS (TLS 1.3). لا توجد بيانات تُرسل بدون تشفير.' },
  { icon: '🔑', title: 'مفتاح API مُشفّر', desc: 'كل حساب يحصل على مفتاح فريد (api_secret). لا يُخزّن كنص عادي — بل يُشفر بـ bcrypt.' },
  { icon: '🏢', title: 'عزل البيانات', desc: 'كل جامعة في حساب منفصل. لا يمكن لأي حساب رؤية بيانات حساب آخر — حتىadministrator المنصة لا يستطيع ذلك.' },
  { icon: '📝', title: 'سجل التدقيق', desc: 'كل عملية إدخال أو تعديل تُسجَّل في audit_log مع التاريخ والمستخدم والإجراء.' },
  { icon: '⏰', title: 'انتهاء الجلسة', desc: 'جلسات المدرّسين تنتهي تلقائياً بعد مدة عدم النشاط. لا توجد جلسات دائمة.' },
  { icon: '🔄', title: ' ratified', desc: 'الكود مفتوح المصدر على GitHub. أي شخص يمكنه مراجعة الكود والتأكد من أمانه.' },
]

export default function PrivacyPolicy() {
  return (
    <div className="min-h-screen bg-gradient-to-b from-slate-50 to-white">
      {/* Header */}
      <header className="bg-white/80 backdrop-blur-md border-b border-slate-200/70 sticky top-0 z-50">
        <div className={`${SECTION} flex items-center justify-between py-3`}>
          <Link to="/" className="flex items-center gap-2.5">
            <div className="flex h-9 w-9 items-center justify-center rounded-xl bg-gradient-to-br from-brand-500 to-violet-600 text-white">
              <svg width="17" height="17" viewBox="0 0 24 24" fill="none">
                <path d="M7 3h10a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2Z" stroke="currentColor" strokeWidth="2" strokeLinecap="round" />
                <path d="M12 8v8M8 11v2" stroke="currentColor" strokeWidth="2" strokeLinecap="round" />
              </svg>
            </div>
            <p className="text-sm font-extrabold text-slate-800">{SITE.name}</p>
          </Link>
          <Link to="/" className="text-xs font-bold text-brand-600 hover:text-brand-700">العودة للرئيسية</Link>
        </div>
      </header>

      {/* Hero */}
      <section className="py-16 text-center">
        <Reveal>
          <div className={SECTION}>
            <div className="mx-auto mb-6 flex h-20 w-20 items-center justify-center rounded-3xl bg-gradient-to-br from-emerald-500 to-teal-600 text-white text-4xl shadow-xl">🛡️</div>
            <h1 className="text-4xl font-extrabold text-slate-900">سياسة الأمان والخصوصية</h1>
            <p className="mt-4 max-w-2xl mx-auto text-lg text-slate-500 leading-relaxed">
              نحن ملتزمون بأعلى معايير أمان البيانات وخصوصية المستخدمين.
              هذه الصفحة توضح بالضبط ما تقرأه الإضافة، وما لا تقرأه، وكيف نحمي بياناتك.
            </p>
          </div>
        </Reveal>
      </section>

      {/* Open Source Badge */}
      <section className="pb-12">
        <Reveal>
          <div className={SECTION}>
            <a href={SITE.repoUrl} target="_blank" rel="noreferrer"
              className="flex items-center gap-4 rounded-2xl border-2 border-dashed border-emerald-300 bg-emerald-50 p-6 transition-colors hover:bg-emerald-100">
              <div className="flex h-12 w-12 items-center justify-center rounded-xl bg-emerald-600 text-white">
                <svg width="22" height="22" viewBox="0 0 24 24" fill="currentColor">
                  <path d="M12 .3a12 12 0 0 0-3.8 23.4c.6.1.8-.3.8-.6v-2c-3.3.7-4-1.6-4-1.6-.6-1.4-1.4-1.8-1.4-1.8-1-.7.1-.7.1-.7 1.2 0 1.9 1.2 1.9 1.2 1 1.8 2.8 1.3 3.5 1 0-.8.4-1.3.7-1.6-2.7-.3-5.5-1.3-5.5-6 0-1.2.5-2.3 1.3-3.1-.2-.4-.6-1.6.1-3.2 0 0 1-.3 3.3 1.2a11.5 11.5 0 0 1 6 0C17.3 4.6 18.3 5 18.3 5c.7 1.6.2 2.8.1 3.2.8.8 1.3 1.9 1.3 3.2 0 4.6-2.8 5.6-5.5 5.9.4.4.8 1.1.8 2.2v3.3c0 .3.2.7.8.6A12 12 0 0 0 12 .3" />
                </svg>
              </div>
              <div>
                <p className="text-base font-extrabold text-emerald-800">كود مفتوح المصدر على GitHub</p>
                <p className="text-sm text-emerald-600">يمكنك مراجعة الكود بالكامل والتأكد من أمان الإضافة. لا أسرار مخفية.</p>
              </div>
              <svg className="mr-auto" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2"><path d="M9 18l6-6-6-6" /></svg>
            </a>
          </div>
        </Reveal>
      </section>

      {/* What We Read */}
      <section className="pb-16">
        <Reveal>
          <div className={SECTION}>
            <h2 className="text-2xl font-extrabold text-slate-800 text-center mb-2">📋 ما تقرأه الإضافة</h2>
            <p className="text-center text-slate-400 mb-8 text-sm">هذه البيانات فقط — لا غير</p>
            <div className="grid gap-4 md:grid-cols-2">
              {WHAT_WE_READ.map((item, i) => (
                <Reveal key={i} delay={i * 60}>
                  <div className="flex gap-4 rounded-2xl border border-emerald-200 bg-emerald-50/50 p-5">
                    <div className="text-2xl">{item.icon}</div>
                    <div>
                      <h3 className="text-sm font-extrabold text-slate-800">{item.title}</h3>
                      <p className="mt-1 text-xs leading-relaxed text-slate-500">{item.desc}</p>
                    </div>
                  </div>
                </Reveal>
              ))}
            </div>
          </div>
        </Reveal>
      </section>

      {/* What We Don't Read */}
      <section className="pb-16">
        <Reveal>
          <div className={SECTION}>
            <h2 className="text-2xl font-extrabold text-slate-800 text-center mb-2">🚫 ما لا تقرأه الإضافة</h2>
            <p className="text-center text-slate-400 mb-8 text-sm">هذه البيانات لا تُلامس أبداً</p>
            <div className="grid gap-4 md:grid-cols-2">
              {WHAT_WE_DONT.map((item, i) => (
                <Reveal key={i} delay={i * 60}>
                  <div className="flex gap-4 rounded-2xl border border-rose-200 bg-rose-50/50 p-5">
                    <div className="text-2xl">{item.icon}</div>
                    <div>
                      <h3 className="text-sm font-extrabold text-slate-800">{item.title}</h3>
                      <p className="mt-1 text-xs leading-relaxed text-slate-500">{item.desc}</p>
                    </div>
                  </div>
                </Reveal>
              ))}
            </div>
          </div>
        </Reveal>
      </section>

      {/* Security Measures */}
      <section className="pb-16">
        <Reveal>
          <div className={SECTION}>
            <h2 className="text-2xl font-extrabold text-slate-800 text-center mb-2">🔐 إجراءات الأمان</h2>
            <p className="text-center text-slate-400 mb-8 text-sm">كيف نحمي بياناتك</p>
            <div className="grid gap-4 md:grid-cols-2 lg:grid-cols-3">
              {SECURITY.map((item, i) => (
                <Reveal key={i} delay={i * 60}>
                  <div className="rounded-2xl border border-slate-200 bg-white p-5 ring-1 ring-slate-100 transition-all hover:shadow-md">
                    <div className="text-2xl mb-3">{item.icon}</div>
                    <h3 className="text-sm font-extrabold text-slate-800">{item.title}</h3>
                    <p className="mt-1.5 text-xs leading-relaxed text-slate-500">{item.desc}</p>
                  </div>
                </Reveal>
              ))}
            </div>
          </div>
        </Reveal>
      </section>

      {/* Data Flow */}
      <section className="pb-16">
        <Reveal>
          <div className={SECTION}>
            <h2 className="text-2xl font-extrabold text-slate-800 text-center mb-2">🔄 مسار البيانات</h2>
            <p className="text-center text-slate-400 mb-8 text-sm">كيف تنتقل البيانات من متصفح الطالب إلى المنصة</p>
            <div className="flex flex-col items-center gap-3">
              {[
                { step: '١', text: 'طالب يفتح صفحة الامتحان', color: 'bg-blue-500' },
                { step: '٢', text: 'الإضافة تراقب الأحداث في المتصفح فقط', color: 'bg-cyan-500' },
                { step: '٣', text: 'الأحداث تُرسل مشفرة عبر HTTPS إلى المنصة', color: 'bg-violet-500' },
                { step: '٤', text: 'المنصة تحسب نسبة الخطر بناءً على السلوك', color: 'bg-amber-500' },
                { step: '٥', text: 'المدرّس يرى النتائج في لوحة التحكم', color: 'bg-emerald-500' },
              ].map((s, i) => (
                <div key={i} className="flex items-center gap-4 w-full max-w-lg">
                  <div className={`flex h-10 w-10 shrink-0 items-center justify-center rounded-full ${s.color} text-white text-sm font-extrabold`}>{s.step}</div>
                  <div className="flex-1 rounded-xl border border-slate-200 bg-white px-4 py-3 text-sm font-bold text-slate-700">{s.text}</div>
                </div>
              ))}
            </div>
          </div>
        </Reveal>
      </section>

      {/* Our Promise */}
      <section className="pb-16">
        <Reveal>
          <div className={SECTION}>
            <div className="rounded-3xl bg-gradient-to-br from-slate-900 to-slate-800 p-8 text-center text-white">
              <h2 className="text-2xl font-extrabold">وعدنا لك</h2>
              <div className="mt-6 grid gap-6 md:grid-cols-3 text-sm">
                <div>
                  <div className="text-3xl mb-2">🎓</div>
                  <p className="font-extrabold">للجامعات</p>
                  <p className="mt-1 text-slate-300 text-xs leading-relaxed">لن نستخدم بياناتك لأي غرض آخر. منصتنا مخصصة لراقبة الامتحانات فقط.</p>
                </div>
                <div>
                  <div className="text-3xl mb-2">👨‍🏫</div>
                  <p className="font-extrabold">للمدرّسين</p>
                  <p className="mt-1 text-slate-300 text-xs leading-relaxed">لا نقرأ درجاتك ولا نعدّل أي شيء في حسابك. نحن فقط نراقب سلوك الامتحان.</p>
                </div>
                <div>
                  <div className="text-3xl mb-2">👨‍🎓</div>
                  <p className="font-extrabold">للطلاب</p>
                  <p className="mt-1 text-slate-300 text-xs leading-relaxed">لا نسرق بياناتك ولا نخزّن كلمات مرورك. إذا كنت لا تغش، فلن تتأثر.</p>
                </div>
              </div>
            </div>
          </div>
        </Reveal>
      </section>

      {/* Footer */}
      <footer className="bg-slate-900 py-8 text-slate-400">
        <div className={`${SECTION} text-center text-xs`}>
          <p>{SITE.name} — مشروع تخرج</p>
        </div>
      </footer>
    </div>
  )
}
