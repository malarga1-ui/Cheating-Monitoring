import { useEffect, useState } from 'react'
import { api } from '../api'
import Card from '../components/Card'
import Spinner from '../components/Spinner'
import { Reveal } from '../components/motion'

const CATEGORY_META = {
  behavioral: {
    label: 'سلوكي (B)',
    color: 'brand',
    icon: '⚡',
    badge: 'bg-brand-50 text-brand-700 border-brand-200',
    bar: 'bg-brand-500',
    desc: 'مغادرة التبويب، أدوات المطور، النسخ واللصق، والسرعة الإدراكية الخارقة.',
  },
  ai: {
    label: 'ذكاء اصطناعي (A)',
    color: 'cyan',
    icon: '🤖',
    badge: 'bg-cyan-50 text-cyan-700 border-cyan-200',
    bar: 'bg-cyan-500',
    desc: 'كشف نصوص ChatGPT والمحتوى المولد تلقائياً (مع حماية الطالب من FPR).',
  },
  similarity: {
    label: 'التشابه والتواطؤ (S)',
    color: 'amber',
    icon: '🔗',
    badge: 'bg-amber-50 text-amber-700 border-amber-200',
    bar: 'bg-amber-500',
    desc: 'مقارنة الإجابات المقالية بين الطلاب واكتشاف التطابق والنسخ المتبادل.',
  },
  network: {
    label: 'الشبكة والجلسة (N)',
    color: 'violet',
    icon: '🌐',
    badge: 'bg-violet-50 text-violet-700 border-violet-200',
    bar: 'bg-violet-500',
    desc: 'تجمع نفس الـ IP، تغيير الدولة أثناء الامتحان، وتعدد الأجهزة والجلسات.',
  },
}

export default function TeacherRiskFormula() {
  const [data, setData] = useState(null)
  const [catWeights, setCatWeights] = useState({ behavioral: 50, ai: 20, similarity: 15, network: 15 })
  const [indicators, setIndicators] = useState([])
  const [exams, setExams] = useState([])
  const [selectedExamId, setSelectedExamId] = useState('')
  const [loading, setLoading] = useState(true)
  const [busy, setBusy] = useState(false)
  const [notice, setNotice] = useState('')
  const [error, setError] = useState('')
  const [activePreset, setActivePreset] = useState('standard')

  const loadFormula = async () => {
    setLoading(true)
    try {
      const [res, exList] = await Promise.all([
        api.get('/api/teacher/risk-formula'),
        api.get('/api/teacher/exams'),
      ])
      setData(res)
      setIndicators(res.indicators || [])
      setExams(Array.isArray(exList) ? exList : [])
      if (res.category_totals) {
        setCatWeights({
          behavioral: Math.round(res.category_totals.behavioral || 50),
          ai: Math.round(res.category_totals.ai || 20),
          similarity: Math.round(res.category_totals.similarity || 15),
          network: Math.round(res.category_totals.network || 15),
        })
      }
    } catch (e) {
      setError(e.message || 'فشل تحميل إعدادات معادلة الغش')
    } finally {
      setLoading(false)
    }
  }

  useEffect(() => {
    loadFormula()
  }, [])

  const totalSum = Object.values(catWeights).reduce((a, b) => a + Number(b), 0)

  const applyPreset = (preset) => {
    setActivePreset(preset.key)
    setCatWeights({ ...preset.weights })
    setNotice(`تم تجهيز قالب: ${preset.name} — اضغط على "حفظ وتطبيق الأوزان" لتفعيله.`)
  }

  const handleSave = async () => {
    if (totalSum !== 100) {
      setError(`مجموع الأوزان الحالية هو ${totalSum}%، يجب أن يكون المجموع 100% بالضبط`)
      return
    }

    setBusy(true)
    setError('')
    setNotice('')
    try {
      await api.post('/api/teacher/risk-formula', {
        category_weights: catWeights,
      })
      setNotice('✓ تم حفظ أوزان معادلة الغش بنجاح!')
      await loadFormula()
    } catch (e) {
      setError(e.message || 'فشل حفظ الأوزان')
    } finally {
      setBusy(false)
    }
  }

  const handleRecompute = async () => {
    if (!window.confirm('هل تريد إعادة حساب درجات الغش لجميع جلسات هذا الامتحان بناءً على المعادلة الحالية؟')) {
      return
    }

    setBusy(true)
    setError('')
    setNotice('')
    try {
      const res = await api.post('/api/teacher/risk-formula/recompute', {
        exam_id: selectedExamId ? parseInt(selectedExamId, 10) : 0,
      })
      setNotice(res.message || 'تمت إعادة حساب درجات الغش بنجاح!')
    } catch (e) {
      setError(e.message || 'فشل إعادة الحساب')
    } finally {
      setBusy(false)
    }
  }

  const toggleIndicator = (id) => {
    setIndicators(prev => prev.map(ind => ind.id === id ? { ...ind, enabled: ind.enabled ? 0 : 1 } : ind))
  }

  if (loading) {
    return (
      <div className="py-20 text-center">
        <Spinner />
        <p className="mt-2 text-xs font-bold text-slate-400">جاري تحميل معادلة الغش ومعايير التقييم...</p>
      </div>
    )
  }

  return (
    <div className="space-y-6">
      {/* Header */}
      <header className="flex flex-wrap items-center justify-between gap-4 animate-fade-up">
        <div>
          <div className="flex items-center gap-2">
            <span className="flex h-8 w-8 items-center justify-center rounded-xl bg-brand-600 text-white shadow-md shadow-brand-600/20 font-extrabold text-sm">⚖️</span>
            <h1 className="text-2xl font-black text-slate-800">معادلة تقييم الغش والأوزان الأكاديمية (Risk Formula)</h1>
          </div>
          <p className="mt-1 text-xs font-bold text-slate-500">
            نموذج التحليل متعدد المعايير (MCDA - SAW) الموثق في أطروحة الماجستير وفق معيار NIST SP 800-30
          </p>
        </div>

        <div className="flex flex-wrap items-center gap-2.5">
          <select
            value={selectedExamId}
            onChange={(e) => setSelectedExamId(e.target.value)}
            className="rounded-xl border border-slate-200 bg-white px-3.5 py-2 text-xs font-extrabold text-slate-700 outline-none shadow-sm focus:border-brand-500"
          >
            <option value="">جميع الامتحانات</option>
            {exams.map((ex) => (
              <option key={ex.id} value={ex.id}>
                {ex.name}
              </option>
            ))}
          </select>

          <button
            onClick={handleRecompute}
            disabled={busy}
            className="flex items-center gap-2 rounded-xl bg-violet-600 px-4 py-2 text-xs font-black text-white shadow-lg shadow-violet-600/20 hover:bg-violet-700 transition-all disabled:opacity-50"
          >
            <span>🔄</span>
            <span>إعادة حساب درجات الغش</span>
          </button>

          <button
            onClick={handleSave}
            disabled={busy || totalSum !== 100}
            className="flex items-center gap-2 rounded-xl bg-brand-600 px-5 py-2 text-xs font-black text-white shadow-lg shadow-brand-600/20 hover:bg-brand-700 transition-all disabled:opacity-50"
          >
            <span>💾</span>
            <span>حفظ وتطبيق الأوزان</span>
          </button>
        </div>
      </header>

      {notice && (
        <div className="rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-xs font-extrabold text-emerald-800 flex items-center justify-between">
          <span>{notice}</span>
          <button onClick={() => setNotice('')} className="text-emerald-600 hover:text-emerald-900 font-black">×</button>
        </div>
      )}

      {error && (
        <div className="rounded-xl border border-rose-200 bg-rose-50 px-4 py-3 text-xs font-extrabold text-rose-800 flex items-center justify-between">
          <span>{error}</span>
          <button onClick={() => setError('')} className="text-rose-600 hover:text-rose-900 font-black">×</button>
        </div>
      )}

      {/* Formula Mathematical Display */}
      <Reveal>
        <Card className="p-6 border-slate-200">
          <div className="flex flex-wrap items-center justify-between gap-3 border-b border-slate-100 pb-4">
            <div>
              <span className="text-[11px] font-black uppercase tracking-wider text-brand-600">المعادلة الرياضية الشاملة</span>
              <h2 className="text-base font-black text-slate-800">
                نسبة خطر الطالب: <span className="font-mono text-brand-700">R_i = w_B · B_i + w_A · A_i + w_S · S_i + w_N · N_i</span>
              </h2>
            </div>
            <div className={`rounded-xl border px-3.5 py-1.5 text-xs font-black ${totalSum === 100 ? 'border-emerald-200 bg-emerald-50 text-emerald-700' : 'border-rose-200 bg-rose-50 text-rose-700'}`}>
              مجموع الأوزان: {totalSum}% {totalSum === 100 ? '✓ صحيح' : '⚠ يجب أن يكون 100%'}
            </div>
          </div>

          <div className="mt-5 grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
            {Object.entries(catWeights).map(([catKey, weight]) => {
              const meta = CATEGORY_META[catKey] || {}
              return (
                <div key={catKey} className={`rounded-2xl border p-4 transition-all ${meta.badge}`}>
                  <div className="flex items-center justify-between">
                    <span className="text-lg">{meta.icon}</span>
                    <span className="text-xl font-black">{weight}%</span>
                  </div>
                  <h3 className="mt-2 text-xs font-black">{meta.label}</h3>
                  <p className="mt-1 text-[11px] font-semibold opacity-80 leading-relaxed">{meta.desc}</p>
                  <div className="mt-3">
                    <input
                      type="range"
                      min="0"
                      max="100"
                      step="5"
                      value={weight}
                      onChange={(e) => {
                        setActivePreset('custom')
                        setCatWeights(prev => ({ ...prev, [catKey]: parseInt(e.target.value, 10) }))
                      }}
                      className="w-full accent-brand-600 cursor-pointer"
                    />
                  </div>
                </div>
              )
            })}
          </div>
        </Card>
      </Reveal>

      {/* Presets Quick Selector */}
      <Card className="p-5 border-slate-200">
        <h3 className="text-xs font-black text-slate-800 flex items-center gap-1.5">
          <span>⚡</span>
          <span>قوالب أوزان جاهزة حسب طبيعة الامتحان (Exam Presets):</span>
        </h3>
        <p className="mt-1 text-[11px] font-bold text-slate-500">اختر القالب المناسب لامتحانك بضغطة زر لضبط الأوزان بدقة:</p>

        <div className="mt-3.5 grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-5">
          {(data?.presets || []).map((preset) => {
            const isSelected = activePreset === preset.key
            return (
              <button
                key={preset.key}
                type="button"
                onClick={() => applyPreset(preset)}
                className={`rounded-2xl border p-3.5 text-right transition-all ${
                  isSelected
                    ? 'border-brand-500 bg-brand-50/70 shadow-md ring-2 ring-brand-500/20'
                    : 'border-slate-200 bg-white hover:border-slate-300 hover:bg-slate-50'
                }`}
              >
                <div className="flex items-center justify-between">
                  <span className="text-xs font-black text-slate-800">{preset.name}</span>
                  {isSelected && <span className="text-xs text-brand-600 font-black">✓ نشط</span>}
                </div>
                <p className="mt-1 text-[10px] font-semibold text-slate-500 leading-normal">{preset.description}</p>
                <div className="mt-2.5 flex flex-wrap gap-1 text-[9px] font-black">
                  <span className="rounded bg-slate-100 px-1.5 py-0.5 text-slate-600">B: {preset.weights.behavioral}%</span>
                  <span className="rounded bg-cyan-100 px-1.5 py-0.5 text-cyan-800">A: {preset.weights.ai}%</span>
                  <span className="rounded bg-amber-100 px-1.5 py-0.5 text-amber-800">S: {preset.weights.similarity}%</span>
                  <span className="rounded bg-violet-100 px-1.5 py-0.5 text-violet-800">N: {preset.weights.network}%</span>
                </div>
              </button>
            )
          })}
        </div>
      </Card>

      {/* Individual Indicators Fine-tuning */}
      <Reveal>
        <Card className="overflow-hidden p-0 border-slate-200">
          <div className="border-b border-slate-100 bg-slate-50/60 px-5 py-3.5 flex items-center justify-between">
            <div>
              <h3 className="text-xs font-black text-slate-800">المحددات الفرعية وحساسية الرصد (Granular Indicators)</h3>
              <p className="text-[11px] font-bold text-slate-500">يمكنك تعطيل أو تفعيل أي مؤشر فرعي فردي حسب ظروف الامتحان</p>
            </div>
            <span className="text-xs font-bold text-slate-400">({indicators.length}) مؤشرات</span>
          </div>

          <div className="divide-y divide-slate-100">
            {indicators.map((ind) => {
              const meta = CATEGORY_META[ind.category] || CATEGORY_META.behavioral
              return (
                <div key={ind.id} className="flex flex-wrap items-center justify-between gap-3 px-5 py-3.5 transition-colors hover:bg-slate-50/50">
                  <div className="flex items-center gap-3">
                    <button
                      type="button"
                      onClick={() => toggleIndicator(ind.id)}
                      className={`relative inline-flex h-5 w-9 flex-shrink-0 cursor-pointer rounded-full border-2 border-transparent transition-colors duration-200 ease-in-out focus:outline-none ${
                        ind.enabled ? 'bg-brand-600' : 'bg-slate-300'
                      }`}
                    >
                      <span
                        className={`pointer-events-none inline-block h-4 w-4 transform rounded-full bg-white shadow ring-0 transition duration-200 ease-in-out ${
                          ind.enabled ? 'translate-x-4' : 'translate-x-0'
                        }`}
                      />
                    </button>
                    <div>
                      <div className="flex items-center gap-2">
                        <span className="text-xs font-black text-slate-800">{ind.label_ar}</span>
                        <span className={`rounded-md px-2 py-0.5 text-[10px] font-black border ${meta.badge}`}>
                          {meta.label}
                        </span>
                      </div>
                      <p className="mt-0.5 text-[11px] font-semibold text-slate-400">{ind.description || ind.indicator_key}</p>
                    </div>
                  </div>

                  <div className="flex items-center gap-2">
                    <span className="text-xs font-bold text-slate-500">الوزن النسبي:</span>
                    <span className="rounded-lg bg-slate-100 px-2.5 py-1 text-xs font-black text-slate-700">
                      {ind.weight_percent}%
                    </span>
                  </div>
                </div>
              )
            })}
          </div>
        </Card>
      </Reveal>

      {/* NIST SP 800-30 Levels Reference */}
      <Card className="p-5 border-slate-200 bg-slate-50/50">
        <h4 className="text-xs font-black text-slate-800 flex items-center gap-1.5">
          <span>🏛️</span>
          <span>مستويات الخطورة الخمسة المعتمدة (NIST SP 800-30 Risk Levels):</span>
        </h4>
        <div className="mt-3 grid grid-cols-2 gap-2 sm:grid-cols-5 text-center text-xs font-extrabold">
          <div className="rounded-xl border border-emerald-200 bg-emerald-50/80 p-2.5 text-emerald-800">
            <p className="text-[10px] font-bold text-emerald-600">0 - 4%</p>
            <p className="mt-0.5">منخفض جداً (Safe)</p>
          </div>
          <div className="rounded-xl border border-blue-200 bg-blue-50/80 p-2.5 text-blue-800">
            <p className="text-[10px] font-bold text-blue-600">5 - 20%</p>
            <p className="mt-0.5">منخفض (Low)</p>
          </div>
          <div className="rounded-xl border border-amber-200 bg-amber-50/80 p-2.5 text-amber-800">
            <p className="text-[10px] font-bold text-amber-600">21 - 79%</p>
            <p className="mt-0.5">متوسط (Moderate)</p>
          </div>
          <div className="rounded-xl border border-orange-200 bg-orange-50/80 p-2.5 text-orange-800">
            <p className="text-[10px] font-bold text-orange-600">80 - 95%</p>
            <p className="mt-0.5">مرتفع (High)</p>
          </div>
          <div className="rounded-xl border border-rose-200 bg-rose-50/80 p-2.5 text-rose-800">
            <p className="text-[10px] font-bold text-rose-600">96 - 100%</p>
            <p className="mt-0.5">حرج جداً (Critical)</p>
          </div>
        </div>
      </Card>
    </div>
  )
}
