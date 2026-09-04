import { useState, useMemo } from 'react'

export default function TeacherAdvisor() {
  const [activeTab, setActiveTab] = useState('tactics')

  // Interactive Calculator State
  const [mcqCount, setMcqCount] = useState(3)
  const [shortCount, setShortCount] = useState(1)
  const [essayCount, setEssayCount] = useState(1)
  const [durationMinutes, setDurationMinutes] = useState(15)
  const [hasQuestionBank, setHasQuestionBank] = useState(false)
  const [isSequential, setIsSequential] = useState(false)

  // Live Exam Immunity & Balance Calculations
  const analysis = useMemo(() => {
    const totalQ = mcqCount + shortCount + essayCount
    if (totalQ === 0) {
      return {
        immunityScore: 50,
        status: 'warning',
        avgTimePerQ: 0,
        riskFactors: ['يرجى تحديد عدد الأسئلة'],
        recommendations: []
      }
    }

    const totalSeconds = durationMinutes * 60
    const avgSecPerQ = Math.round(totalSeconds / totalQ)

    // Expected required thinking & typing seconds
    const expectedSecMCQ = mcqCount * 45
    const expectedSecShort = shortCount * 75
    const expectedSecEssay = essayCount * 240
    const realisticRequiredSec = expectedSecMCQ + expectedSecShort + expectedSecEssay

    const timeSlackRatio = totalSeconds / Math.max(1, realisticRequiredSec)

    // Calculate Immunity Score (0 - 100)
    let score = 50

    // Essay ratio (analytic depth)
    const essayRatio = essayCount / totalQ
    if (essayRatio >= 0.25 && essayRatio <= 0.50) score += 15
    else if (essayRatio > 0.50) score += 10
    else score -= 10 // pure MCQ is easy to search

    // Time Slack evaluation
    if (timeSlackRatio >= 0.85 && timeSlackRatio <= 1.25) {
      score += 15 // perfectly balanced
    } else if (timeSlackRatio > 1.6) {
      score -= 20 // excessively generous time allows searching ChatGPT
    } else if (timeSlackRatio < 0.70) {
      score -= 10 // overly harsh pressure leads to panic
    }

    // Security configurations
    if (hasQuestionBank) score += 12
    if (isSequential) score += 8

    score = Math.max(15, Math.min(98, Math.round(score)))

    // Risk factors & recommendations
    const riskFactors = []
    const recommendations = []

    if (timeSlackRatio > 1.5) {
      riskFactors.push('الوقت المتاح واسع جداً مقارنة بحجم الأسئلة، مما يوفر للطالب متسعاً كافياً لفتح نوافذ خارجية والبحث في الذكاء الاصطناعي دون خوف من نفاد الوقت.')
      recommendations.push(`تقليص مدة الامتحان المقترحة من ${durationMinutes} دقيقة إلى حوالي ${Math.round(realisticRequiredSec / 60)} - ${Math.round(realisticRequiredSec / 60) + 3} دقيقة لإبقاء تركيز الطالب داخل الشاشة.`)
    } else if (timeSlackRatio < 0.75) {
      riskFactors.push('الوقت ضيق جداً وغير كافٍ لصياغة الإجابات المقالية والتفكير السليم، مما قد يظلم الطلاب المتميزين.')
      recommendations.push(`زيادة مدة الامتحان لتصل إلى حوالي ${Math.round(realisticRequiredSec / 60)} دقيقة لضمان العدالة الأكاديمية.`)
    }

    if (mcqCount > 0 && essayCount === 0) {
      riskFactors.push('الامتحان خالي تماماً من الأسئلة التحليلية أو المقالية، مما يجعله عرضة لتبادل الإجابات والبحث السريع.')
      recommendations.push('إضافة سؤال مقالي تحليلي أو مسألة تطبيقية واحدة على الأقل لقياس الفهم الحقيقي، حيث يصعب استنساخها آلياً.')
    }

    if (!hasQuestionBank) {
      recommendations.push('تفعيل بنك الأسئلة والترتيب العشوائي (Shuffle) لضمان ظهور نماذج مختلفة لكل طالب ومنع التواطؤ الشبكي.')
    }

    if (!isSequential && totalQ >= 10) {
      recommendations.push('تفعيل التنقل المتسلسل (Sequential Navigation) في الامتحانات الحساسة لمنع العودة وتوزيع وقت الغش بين الأسئلة.')
    }

    return {
      immunityScore: score,
      avgTimePerQ: Math.round(avgSecPerQ),
      timeSlackRatio: Math.round(timeSlackRatio * 100),
      totalQ,
      realisticMinutes: Math.round(realisticRequiredSec / 60),
      riskFactors,
      recommendations
    }
  }, [mcqCount, shortCount, essayCount, durationMinutes, hasQuestionBank, isSequential])

  return (
    <div className="space-y-8 select-text">
      {/* Hero Header */}
      <div className="relative overflow-hidden rounded-3xl border border-sky-200/80 bg-gradient-to-r from-sky-50/90 via-white to-indigo-50/70 p-6 sm:p-8 shadow-sm ring-1 ring-sky-100/50">
        <div className="pointer-events-none absolute -left-12 -top-12 h-44 w-44 rounded-full bg-sky-500/10 blur-3xl" />
        <div className="pointer-events-none absolute -right-12 -bottom-12 h-44 w-44 rounded-full bg-indigo-500/10 blur-3xl" />
        
        <div className="relative flex flex-col md:flex-row items-start md:items-center justify-between gap-6">
          <div className="flex items-start gap-4">
            <div className="flex h-14 w-14 shrink-0 items-center justify-center rounded-2xl bg-gradient-to-br from-sky-500 to-indigo-600 text-3xl text-white shadow-lg shadow-sky-500/25">
              💡
            </div>
            <div>
              <div className="flex flex-wrap items-center gap-2.5">
                <h1 className="text-xl sm:text-2xl font-black text-slate-800">
                  دليل الأستاذ واستراتيجيات الامتحانات الآمنة
                </h1>
                <span className="rounded-full bg-sky-100 px-3 py-1 text-xs font-extrabold text-sky-700 ring-1 ring-sky-200">
                  SOAR Pedagogical Advisor
                </span>
              </div>
              <p className="mt-1.5 text-xs sm:text-sm font-medium leading-relaxed text-slate-600 max-w-2xl">
                بوابتك الاستشارية الذكية لتصميم امتحانات إلكترونية محصنة، وفهم سلوكيات الذكاء الاصطناعي، ومساعدة المعلم في اتخاذ قرارات تقييم عادلة مبنية على الأدلة الرقمية الموثوقة.
              </p>
            </div>
          </div>

          <div className="flex flex-wrap gap-2 w-full md:w-auto">
            <button
              onClick={() => setActiveTab('calculator')}
              className="inline-flex items-center justify-center gap-2 rounded-xl bg-gradient-to-r from-indigo-600 to-brand-600 px-4 py-2.5 text-xs font-extrabold text-white shadow-md shadow-indigo-600/20 hover:from-indigo-700 hover:to-brand-700 transition-all cursor-pointer w-full sm:w-auto"
            >
              <span>🧮</span>
              <span>فاحص مناعة وتوازن الامتحان</span>
            </button>
          </div>
        </div>

        {/* Guide Navigation Tabs */}
        <div className="mt-6 flex gap-2 overflow-x-auto border-t border-sky-100/80 pt-4 text-xs font-extrabold">
          <button
            onClick={() => setActiveTab('tactics')}
            className={`flex items-center gap-2 rounded-xl px-3.5 py-2 transition-all cursor-pointer ${
              activeTab === 'tactics'
                ? 'bg-brand-600 text-white shadow-sm'
                : 'bg-white text-slate-600 hover:bg-slate-100'
            }`}
          >
            <span>🛡️</span>
            <span>أساليب وتكتيكات الغش الحديثة</span>
          </button>

          <button
            onClick={() => setActiveTab('architecture')}
            className={`flex items-center gap-2 rounded-xl px-3.5 py-2 transition-all cursor-pointer ${
              activeTab === 'architecture'
                ? 'bg-brand-600 text-white shadow-sm'
                : 'bg-white text-slate-600 hover:bg-slate-100'
            }`}
          >
            <span>📐</span>
            <span>الهندسة الآمنة لتوزيع الأسئلة</span>
          </button>

          <button
            onClick={() => setActiveTab('decision')}
            className={`flex items-center gap-2 rounded-xl px-3.5 py-2 transition-all cursor-pointer ${
              activeTab === 'decision'
                ? 'bg-brand-600 text-white shadow-sm'
                : 'bg-white text-slate-600 hover:bg-slate-100'
            }`}
          >
            <span>⚖️</span>
            <span>مصفوفة اتخاذ القرار والتحقق العادل</span>
          </button>

          <button
            onClick={() => setActiveTab('calculator')}
            className={`flex items-center gap-2 rounded-xl px-3.5 py-2 transition-all cursor-pointer ${
              activeTab === 'calculator'
                ? 'bg-indigo-600 text-white shadow-sm'
                : 'bg-white text-slate-600 hover:bg-slate-100'
            }`}
          >
            <span>🧮</span>
            <span>فاحص مناعة الامتحان التفاعلي</span>
          </button>
        </div>
      </div>

      {/* TAB 1: Modern Cheating Tactics */}
      {activeTab === 'tactics' && (
        <div className="grid grid-cols-1 md:grid-cols-2 gap-6">
          <div className="rounded-3xl border border-slate-200/80 bg-white p-6 shadow-xs space-y-4">
            <div className="flex items-center gap-3">
              <span className="flex h-10 w-10 items-center justify-center rounded-xl bg-violet-100 text-violet-700 text-xl font-bold">
                🤖
              </span>
              <div>
                <h3 className="text-sm font-black text-slate-800">1. غش الذكاء الاصطناعي التوليدي (AI & LLMs)</h3>
                <p className="text-[11px] font-semibold text-slate-500">ChatGPT, Claude, Copilot, Gemini</p>
              </div>
            </div>
            <p className="text-xs leading-relaxed text-slate-600">
              <strong>كيف يتم:</strong> يقوم الطالب بنسخ نص السؤال حرفياً (Copy) ثم التبديل لتبويب آخر للصقه في شات الذكاء الاصطناعي، ثم نسخ الإجابة المولّدة ولصقها في ثوانٍ داخل حقل المقال دون كتابة تدريجية.
            </p>
            <div className="rounded-2xl bg-slate-50 p-3.5 border border-slate-100 text-xs space-y-2">
              <p className="font-extrabold text-slate-700">🔍 كيف تكشفه منصة SOAR؟</p>
              <ul className="list-disc pr-4 space-y-1 text-slate-600 text-[11px]">
                <li>رصد أحداث النسخ (Copy Event) من نص السؤال بالمللي ثانية.</li>
                <li>رصد خروج النافذة وفقدان التركيز (Blur / Tab Hidden).</li>
                <li>رصد حدث اللصق السريع (Paste Detection) ومقارنة طول النص بزمن الطباعة (Typing Biometrics).</li>
              </ul>
            </div>
          </div>

          <div className="rounded-3xl border border-slate-200/80 bg-white p-6 shadow-xs space-y-4">
            <div className="flex items-center gap-3">
              <span className="flex h-10 w-10 items-center justify-center rounded-xl bg-amber-100 text-amber-700 text-xl font-bold">
                📱
              </span>
              <div>
                <h3 className="text-sm font-black text-slate-800">2. الشاشات والأجهزة الجانبية (Dual Screen & Phone)</h3>
                <p className="text-[11px] font-semibold text-slate-500">الهاتف الذكي بجانب الحاسوب أو الشاشات المزدوجة</p>
              </div>
            </div>
            <p className="text-xs leading-relaxed text-slate-600">
              <strong>كيف يتم:</strong> يبقى الطالب في صفحة الامتحان على اللابتوب دون تبديل التبويب، لكنه يقرأ السؤال ويبحث عنه عبر هاتفه المحمول أو عبر شاشة ثانية متصلة.
            </p>
            <div className="rounded-2xl bg-slate-50 p-3.5 border border-slate-100 text-xs space-y-2">
              <p className="font-extrabold text-slate-700">🔍 كيف تكشفه منصة SOAR؟</p>
              <ul className="list-disc pr-4 space-y-1 text-slate-600 text-[11px]">
                <li>تحليل فترات الخمول غير الطبيعية (Idle Anomaly) قبل الإجابة.</li>
                <li>تحليل المقاييس الإدراكية (Cognitive Chronometry)؛ فإذا استغرق الطالب وقتاً طويلاً على سؤال سهل، ثم أجاب فجأة، يرصد المحرك ذلك كشبهة بحث خارجي.</li>
              </ul>
            </div>
          </div>

          <div className="rounded-3xl border border-slate-200/80 bg-white p-6 shadow-xs space-y-4">
            <div className="flex items-center gap-3">
              <span className="flex h-10 w-10 items-center justify-center rounded-xl bg-rose-100 text-rose-700 text-xl font-bold">
                🌐
              </span>
              <div>
                <h3 className="text-sm font-black text-slate-800">3. التواطؤ الشبكي والتجمع الجغرافي (Network Collusion)</h3>
                <p className="text-[11px] font-semibold text-slate-500">تقديم الامتحان معاً في نفس الغرفة أو عبر شبكة واحدة</p>
              </div>
            </div>
            <p className="text-xs leading-relaxed text-slate-600">
              <strong>كيف يتم:</strong> يجتمع طالبان أو أكثر في نفس المكان متصلين بشبكة Wi-Fi واحدة، ويتبادلون الإجابات شفوياً أو عبر واتساب وتيليغرام.
            </p>
            <div className="rounded-2xl bg-slate-50 p-3.5 border border-slate-100 text-xs space-y-2">
              <p className="font-extrabold text-slate-700">🔍 كيف تكشفه منصة SOAR؟</p>
              <ul className="list-disc pr-4 space-y-1 text-slate-600 text-[11px]">
                <li>خوارزمية رصد تطابق الـ IP (Same-IP Cluster Analysis).</li>
                <li>مقارنة التوقيت الزمني الدقيق لتسليم الإجابات المتزامنة (Time-Correlation).</li>
                <li>فحص التشابه النصي المقالي بين الطلاب (TF-IDF & Jaccard Similarity).</li>
              </ul>
            </div>
          </div>

          <div className="rounded-3xl border border-slate-200/80 bg-white p-6 shadow-xs space-y-4">
            <div className="flex items-center gap-3">
              <span className="flex h-10 w-10 items-center justify-center rounded-xl bg-emerald-100 text-emerald-700 text-xl font-bold">
                🛠️
              </span>
              <div>
                <h3 className="text-sm font-black text-slate-800">4. التحايل البرمجي وأدوات المطور (DevTools & Scripts)</h3>
                <p className="text-[11px] font-semibold text-slate-500">فتح F12 والتلاعب بكود المتصفح لمنع القيود</p>
              </div>
            </div>
            <p className="text-xs leading-relaxed text-slate-600">
              <strong>كيف يتم:</strong> محاولة الطالب فتح أدوات المطور (Inspect Element) لتعطيل سكربت الحظر أو محاولة قراءة خيارات الأسئلة من DOM أو تصوير الشاشة.
            </p>
            <div className="rounded-2xl bg-slate-50 p-3.5 border border-slate-100 text-xs space-y-2">
              <p className="font-extrabold text-slate-700">🔍 كيف تكشفه منصة SOAR؟</p>
              <ul className="list-disc pr-4 space-y-1 text-slate-600 text-[11px]">
                <li>فخ أدوات المطور اللحظي (DevTools Trap) واحتسابها كتهديد سلوكي مباشر.</li>
                <li>رصد اختصارات تصوير الشاشة (Snipping Tool, PrtScn) وإرسال تنبيه فوري.</li>
              </ul>
            </div>
          </div>
        </div>
      )}

      {/* TAB 2: Exam Architecture */}
      {activeTab === 'architecture' && (
        <div className="space-y-6">
          <div className="grid grid-cols-1 md:grid-cols-3 gap-5">
            <div className="rounded-3xl border border-indigo-200/80 bg-gradient-to-br from-indigo-50/50 via-white to-sky-50/30 p-6 shadow-xs">
              <div className="text-3xl mb-3">⚖️</div>
              <h3 className="text-sm font-black text-slate-800">النسبة الذهبية لأنواع الأسئلة</h3>
              <p className="mt-2 text-xs leading-relaxed text-slate-600">
                الامتحان النموذجي المحصن يتكون من:
              </p>
              <div className="mt-3 space-y-2 text-xs font-bold text-slate-700">
                <div className="flex justify-between border-b border-slate-100 pb-1">
                  <span>40% اختيار من متعدد (MCQ)</span>
                  <span className="text-indigo-600 font-extrabold">استيعاب ومفاهيم</span>
                </div>
                <div className="flex justify-between border-b border-slate-100 pb-1">
                  <span>40% أسئلة مقالية تحليلية</span>
                  <span className="text-violet-600 font-extrabold">تطبيق وفهم عميق</span>
                </div>
                <div className="flex justify-between">
                  <span>20% مسائل أو إكمال فراغات</span>
                  <span className="text-emerald-600 font-extrabold">تركيز ودقة</span>
                </div>
              </div>
            </div>

            <div className="rounded-3xl border border-sky-200/80 bg-gradient-to-br from-sky-50/50 via-white to-blue-50/30 p-6 shadow-xs">
              <div className="text-3xl mb-3">⏱️</div>
              <h3 className="text-sm font-black text-slate-800">قاعدة "الزمن الذهبي للسؤال"</h3>
              <p className="mt-2 text-xs leading-relaxed text-slate-600">
                الوقت هو أكبر مانع للغش. إذا منحت الطالب وقتاً مفرطاً، فأنت تدعوه للبحث الخارجي:
              </p>
              <ul className="mt-3 space-y-1.5 text-xs text-slate-700 list-disc pr-4">
                <li><strong>سؤال الاختيار من متعدد:</strong> 45 إلى 60 ثانية فقط.</li>
                <li><strong>السؤال المقالي القصير:</strong> 2 إلى 3 دقائق.</li>
                <li><strong>المقال التحليلي الطويل:</strong> 4 إلى 5 دقائق، مع حساب معدل سرعة الكتابة الطبيعية (30 كلمة بالدقيقة).</li>
              </ul>
            </div>

            <div className="rounded-3xl border border-purple-200/80 bg-gradient-to-br from-purple-50/50 via-white to-violet-50/30 p-6 shadow-xs">
              <div className="text-3xl mb-3">🎲</div>
              <h3 className="text-sm font-black text-slate-800">بنوك الأسئلة العشوائية</h3>
              <p className="mt-2 text-xs leading-relaxed text-slate-600">
                أقوى تدبير لمنع تواطؤ الزملاء وتسريب الأسئلة:
              </p>
              <ul className="mt-3 space-y-1.5 text-xs text-slate-700 list-disc pr-4">
                <li>إنشاء بنك من 30 سؤالاً، ويظهر لكل طالب 10 أسئلة عشوائية.</li>
                <li>تفعيل خيار "ترتيب الخيارات عشوائياً" (Shuffle choices).</li>
                <li>تفعيل "سؤال واحد لكل صفحة" مع منع الرجوع العكسي إن أمكن.</li>
              </ul>
            </div>
          </div>

          <div className="rounded-3xl border border-slate-200 bg-white p-6 shadow-xs space-y-4">
            <h3 className="text-sm font-black text-slate-800 flex items-center gap-2">
              <span>✍️</span>
              <span>كيف تصيغ أسئلة مقالية يستحيل حلها بنسخ الذكاء الاصطناعي مباشرة؟</span>
            </h3>
            <div className="grid grid-cols-1 md:grid-cols-2 gap-4 text-xs">
              <div className="rounded-2xl bg-rose-50/70 border border-rose-200 p-4 space-y-2">
                <span className="font-extrabold text-rose-800">❌ صياغات تقليدية يسهل حلها بنقرة واحدة:</span>
                <p className="text-slate-600 font-medium">"عرف مفهوم الـ Polymorphism واذكر ثلاثة أنواع له؟"</p>
                <p className="text-[11px] text-rose-700">هذا السؤال يحلّه ChatGPT في ثانية واحدة بنص عام مكرر دون أي جهد من الطالب.</p>
              </div>

              <div className="rounded-2xl bg-emerald-50/70 border border-emerald-200 p-4 space-y-2">
                <span className="font-extrabold text-emerald-800">✓ صياغات ذكية تتطلب إسقاطاً وتطبيقاً شخصياً:</span>
                <p className="text-slate-600 font-medium">"في المشروع العملي الذي أنجزته، كيف طبقت الـ Polymorphism؟ وما المشكلة البرمجية التي واجهتك لو لم تستخدمه؟"</p>
                <p className="text-[11px] text-emerald-700">الذكاء الاصطناعي لا يعرف مشروع الطالب ولا تفاصيل الكود الخاص به، فيضطر الطالب للتفكير والصياغة الذاتية.</p>
              </div>
            </div>
          </div>
        </div>
      )}

      {/* TAB 3: Decision Matrix */}
      {activeTab === 'decision' && (
        <div className="space-y-6">
          <div className="rounded-3xl border border-slate-200 bg-white p-6 shadow-xs space-y-5">
            <div className="flex items-center gap-3">
              <span className="text-2xl">⚖️</span>
              <div>
                <h3 className="text-sm font-black text-slate-800">مصفوفة اتخاذ القرار الأكاديمي العادل (Evidence-Based Decision Matrix)</h3>
                <p className="text-xs text-slate-500 font-medium">دليلك لاتخاذ الإجراء المناسب مع كل مستوى خطورة لضمان العدالة وعدم ظلم الطالب</p>
              </div>
            </div>

            <div className="space-y-4 text-xs">
              {/* Safe */}
              <div className="rounded-2xl border border-emerald-200 bg-emerald-50/50 p-4 flex flex-col md:flex-row items-start md:items-center justify-between gap-4">
                <div className="space-y-1">
                  <div className="flex items-center gap-2">
                    <span className="rounded-full bg-emerald-600 px-2.5 py-0.5 text-[10px] font-extrabold text-white">0% - 20% (آمن / منخفض)</span>
                    <strong className="font-extrabold text-emerald-950">نشاط طبيعي ونزاهة مكتملة</strong>
                  </div>
                  <p className="text-slate-600">الطالب يركز داخل الامتحان. قد يكون هناك فقدان تركيز عارض واحد بسبب إشعار نظام. لا توجد أي أدلة غش.</p>
                </div>
                <div className="rounded-xl bg-white px-3 py-1.5 font-extrabold text-emerald-800 border border-emerald-200 shrink-0">
                  الإجراء: اعتماد النتيجة بالكامل فوراً ✓
                </div>
              </div>

              {/* Medium */}
              <div className="rounded-2xl border border-amber-200 bg-amber-50/50 p-4 flex flex-col md:flex-row items-start md:items-center justify-between gap-4">
                <div className="space-y-1">
                  <div className="flex items-center gap-2">
                    <span className="rounded-full bg-amber-500 px-2.5 py-0.5 text-[10px] font-extrabold text-white">21% - 79% (متوسط / شبهة سلوكية)</span>
                    <strong className="font-extrabold text-amber-950">شبهة استعانة بمصادر خارجية أو تردد ملحوظ</strong>
                  </div>
                  <p className="text-slate-600">وجود عدة عمليات خروج من الشاشة (3 إلى 6 مرات) أو نسخ لأحد الأسئلة دون لصق كبير. قد يكون الطالب مشتتاً.</p>
                </div>
                <div className="rounded-xl bg-white px-3 py-1.5 font-extrabold text-amber-800 border border-amber-200 shrink-0">
                  الإجراء: توجيه تنبيه هادئ أثناء الامتحان ومراجعة تقرير الأدلة ⚠️
                </div>
              </div>

              {/* High / Critical */}
              <div className="rounded-2xl border border-rose-200 bg-rose-50/50 p-4 flex flex-col md:flex-row items-start md:items-center justify-between gap-4">
                <div className="space-y-1">
                  <div className="flex items-center gap-2">
                    <span className="rounded-full bg-rose-600 px-2.5 py-0.5 text-[10px] font-extrabold text-white">80% - 100% (مرتفع / حرج)</span>
                    <strong className="font-extrabold text-rose-950">أدلة قاطعة على الغش المنظم أو استخدام الذكاء الاصطناعي</strong>
                  </div>
                  <p className="text-slate-600">خروج متكرر من الشاشة (10+ مرات)، لصق نصوص مقالية كاملة من مصادر خارجية، تطابق نصوص مع الزملاء أو بصمة AI واضحة.</p>
                </div>
                <div className="rounded-xl bg-white px-3 py-1.5 font-extrabold text-rose-800 border border-rose-200 shrink-0">
                  الإجراء: قفل الامتحان أو استدعاء الطالب لمقابلة شفوية لمناقشة إجابته ⚖️
                </div>
              </div>
            </div>
          </div>

          <div className="rounded-3xl border border-violet-200 bg-violet-50/60 p-6 space-y-3">
            <h4 className="text-sm font-black text-violet-950 flex items-center gap-2">
              <span>🛡️</span>
              <span>بروتوكول التحقق الشفوي العادل (Oral Defense Protocol)</span>
            </h4>
            <p className="text-xs leading-relaxed text-slate-700">
              في حال رصدت المنصة خطورة مرتفعة لطالب معين، فإن أفضل ممارسة أكاديمية عالمية هي استدعاء الطالب لخمس دقائق وسؤاله:
              <br />
              <span className="font-bold text-violet-900">"اشرح لي كيف كتبت هذه الفقرة في السؤال الثاني؟ وما هي الفكرة الأساسية منها؟"</span>
              <br />
              إذا كان الطالب قد نسخ من ChatGPT دون فهم، فسيعجز فوراً عن شرح المصطلحات، مما يمنحك حجة وبرهاناً حاسماً وعادلاً بنسبة 100% دون أي مجال للشك.
            </p>
          </div>
        </div>
      )}

      {/* TAB 4: Interactive Calculator */}
      {activeTab === 'calculator' && (
        <div className="space-y-6">
          <div className="grid grid-cols-1 lg:grid-cols-12 gap-6">
            {/* Input Controls */}
            <div className="lg:col-span-5 rounded-3xl border border-slate-200/80 bg-white p-6 shadow-xs space-y-5">
              <div className="flex items-center gap-2.5 border-b border-slate-100 pb-3">
                <span className="text-xl">⚙️</span>
                <h3 className="text-sm font-black text-slate-800">بيانات الامتحان المقترح</h3>
              </div>

              <div className="space-y-4 text-xs font-bold text-slate-700">
                <div>
                  <div className="flex justify-between mb-1.5">
                    <label>أسئلة الاختيار من متعدد (MCQ / صح وخطأ):</label>
                    <span className="text-brand-600 font-extrabold">{mcqCount} أسئلة</span>
                  </div>
                  <input
                    type="range"
                    min="0"
                    max="30"
                    value={mcqCount}
                    onChange={(e) => setMcqCount(parseInt(e.target.value) || 0)}
                    className="w-full accent-brand-600 cursor-pointer"
                  />
                </div>

                <div>
                  <div className="flex justify-between mb-1.5">
                    <label>أسئلة إكمال الفراغ / إجابات قصيرة:</label>
                    <span className="text-emerald-600 font-extrabold">{shortCount} أسئلة</span>
                  </div>
                  <input
                    type="range"
                    min="0"
                    max="15"
                    value={shortCount}
                    onChange={(e) => setShortCount(parseInt(e.target.value) || 0)}
                    className="w-full accent-emerald-600 cursor-pointer"
                  />
                </div>

                <div>
                  <div className="flex justify-between mb-1.5">
                    <label>أسئلة مقالية تحليلية / كود برمجي:</label>
                    <span className="text-violet-600 font-extrabold">{essayCount} أسئلة</span>
                  </div>
                  <input
                    type="range"
                    min="0"
                    max="10"
                    value={essayCount}
                    onChange={(e) => setEssayCount(parseInt(e.target.value) || 0)}
                    className="w-full accent-violet-600 cursor-pointer"
                  />
                </div>

                <div>
                  <div className="flex justify-between mb-1.5">
                    <label>مدة الامتحان الإجمالية (بالدقائق):</label>
                    <span className="text-indigo-600 font-extrabold">{durationMinutes} دقيقة</span>
                  </div>
                  <input
                    type="range"
                    min="5"
                    max="120"
                    step="5"
                    value={durationMinutes}
                    onChange={(e) => setDurationMinutes(parseInt(e.target.value) || 5)}
                    className="w-full accent-indigo-600 cursor-pointer"
                  />
                </div>

                <div className="pt-2 border-t border-slate-100 space-y-2.5">
                  <label className="flex items-center gap-2.5 cursor-pointer">
                    <input
                      type="checkbox"
                      checked={hasQuestionBank}
                      onChange={(e) => setHasQuestionBank(e.target.checked)}
                      className="rounded text-brand-600 focus:ring-brand-500 h-4 w-4"
                    />
                    <span className="text-xs font-bold text-slate-700">تفعيل بنك أسئلة ونماذج عشوائية (Random Question Pool)</span>
                  </label>

                  <label className="flex items-center gap-2.5 cursor-pointer">
                    <input
                      type="checkbox"
                      checked={isSequential}
                      onChange={(e) => setIsSequential(e.target.checked)}
                      className="rounded text-brand-600 focus:ring-brand-500 h-4 w-4"
                    />
                    <span className="text-xs font-bold text-slate-700">تفعيل التنقل المتسلسل دون رجوع (Sequential Navigation)</span>
                  </label>
                </div>
              </div>
            </div>

            {/* Results & Live Diagnostic */}
            <div className="lg:col-span-7 rounded-3xl border border-slate-200/80 bg-white p-6 shadow-xs space-y-6">
              <div className="flex items-center justify-between border-b border-slate-100 pb-3">
                <div className="flex items-center gap-2.5">
                  <span className="text-xl">📊</span>
                  <h3 className="text-sm font-black text-slate-800">مؤشرات الأمان ومناعة الامتحان</h3>
                </div>
                <span className={`rounded-full px-3 py-1 text-xs font-black ${
                  analysis.immunityScore >= 75
                    ? 'bg-emerald-100 text-emerald-800 border border-emerald-200'
                    : analysis.immunityScore >= 50
                    ? 'bg-amber-100 text-amber-800 border border-amber-200'
                    : 'bg-rose-100 text-rose-800 border border-rose-200'
                }`}>
                  {analysis.immunityScore >= 75 ? '🛡️ محصن ومحكم ضد الغش' : analysis.immunityScore >= 50 ? '⚠️ متوسط المناعة' : '❌ معرض لثغرات الغش'}
                </span>
              </div>

              {/* Immunity Gauge Bar */}
              <div>
                <div className="flex justify-between items-center text-xs font-bold mb-1.5">
                  <span className="text-slate-600">مؤشر مناعة الامتحان (Cheating Immunity Index):</span>
                  <span className="text-base font-black text-slate-900">{analysis.immunityScore} / 100</span>
                </div>
                <div className="h-3 w-full rounded-full bg-slate-100 overflow-hidden">
                  <div
                    className={`h-full transition-all duration-500 rounded-full ${
                      analysis.immunityScore >= 75
                        ? 'bg-gradient-to-r from-emerald-500 to-teal-500'
                        : analysis.immunityScore >= 50
                        ? 'bg-gradient-to-r from-amber-500 to-orange-500'
                        : 'bg-gradient-to-r from-rose-500 to-red-600'
                    }`}
                    style={{ width: `${analysis.immunityScore}%` }}
                  />
                </div>
              </div>

              {/* Stats Breakdown */}
              <div className="grid grid-cols-3 gap-3 text-center">
                <div className="rounded-2xl bg-slate-50 p-3 border border-slate-100">
                  <p className="text-[10px] font-extrabold text-slate-500">إجمالي الأسئلة</p>
                  <p className="text-lg font-black text-slate-800">{analysis.totalQ}</p>
                </div>
                <div className="rounded-2xl bg-slate-50 p-3 border border-slate-100">
                  <p className="text-[10px] font-extrabold text-slate-500">متوسط وقت السؤال</p>
                  <p className="text-lg font-black text-slate-800">{analysis.avgTimePerQ} ثانية</p>
                </div>
                <div className="rounded-2xl bg-slate-50 p-3 border border-slate-100">
                  <p className="text-[10px] font-extrabold text-slate-500">الوقت المنطقي للحل</p>
                  <p className="text-lg font-black text-slate-800">~{analysis.realisticMinutes} دقيقة</p>
                </div>
              </div>

              {/* Recommendations Box */}
              <div className="space-y-3 pt-2">
                <h4 className="text-xs font-black text-slate-800 flex items-center gap-1.5">
                  <span>💡</span>
                  <span>توصيات الذكاء الاصطناعي لتحصين هذا الامتحان:</span>
                </h4>

                {analysis.recommendations.length > 0 ? (
                  <ul className="space-y-2 text-xs">
                    {analysis.recommendations.map((rec, i) => (
                      <li key={i} className="rounded-xl bg-sky-50/70 border border-sky-100 p-3 text-slate-700 font-medium flex items-start gap-2">
                        <span className="text-sky-600 font-bold shrink-0">✓</span>
                        <span>{rec}</span>
                      </li>
                    ))}
                  </ul>
                ) : (
                  <p className="text-xs font-bold text-emerald-700 bg-emerald-50 p-3 rounded-xl border border-emerald-200">
                    توزيع الأسئلة والوقت الحالي مثالي ومحكم تماماً! سيصعب جداً على أي طالب الاستعانة بمصادر خارجية دون أن ينفد وقته.
                  </p>
                )}

                {analysis.riskFactors.length > 0 && (
                  <div className="mt-2 space-y-1">
                    {analysis.riskFactors.map((rf, i) => (
                      <p key={i} className="text-[11px] font-bold text-rose-700 bg-rose-50/80 p-2.5 rounded-xl border border-rose-100">
                        ⚠️ <strong>نقطة ضعف:</strong> {rf}
                      </p>
                    ))}
                  </div>
                )}
              </div>
            </div>
          </div>
        </div>
      )}
    </div>
  )
}
