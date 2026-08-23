import { useEffect, useState } from 'react'
import { api } from '../api'
import Card from '../components/Card'
import Spinner from '../components/Spinner'
import { Reveal } from '../components/motion'

const CATEGORIES = [
  { key: 'behavioral', label: 'سلوكي', weight: 50, color: 'brand', icon: '⚡', desc: 'نسخ، لصق، إخفاء تبويب، أدوات مطوّر — يتناسب بعدد التكرارات' },
  { key: 'network', label: 'الشبكة', weight: 15, color: 'violet', icon: '🌐', desc: 'تجمع بنفس الـ IP، تغيير IP، أجهزة متعددة' },
  { key: 'ai', label: 'ذكاء اصطناعي', weight: 20, color: 'cyan', icon: '🤖', desc: 'كشف إجابات مولّدة بالذكاء الاصطناعي' },
  { key: 'similarity', label: 'التشابه', weight: 15, color: 'amber', icon: '🔗', desc: 'مقارنة الإجابات بين الطلاب واكتشاف التطابق' },
]

const COLOR_MAP = {
  brand: { bg: 'bg-brand-50', text: 'text-brand-700', ring: 'ring-brand-200', bar: 'bg-brand-500' },
  violet: { bg: 'bg-violet-50', text: 'text-violet-700', ring: 'ring-violet-200', bar: 'bg-violet-500' },
  cyan: { bg: 'bg-cyan-50', text: 'text-cyan-700', ring: 'ring-cyan-200', bar: 'bg-cyan-500' },
  amber: { bg: 'bg-amber-50', text: 'text-amber-700', ring: 'ring-amber-200', bar: 'bg-amber-500' },
}

export default function RiskFormula() {
  const [indicators, setIndicators] = useState([])
  const [busy, setBusy] = useState(false)
  const [notice, setNotice] = useState('')
  const [err, setErr] = useState('')
  const [expanded, setExpanded] = useState(null)

  const load = () =>
    api.get('/api/settings/risk-indicators')
      .then((d) => setIndicators(d.indicators || []))
      .catch((e) => setErr(e.message))

  useEffect(() => { load() }, [])

  const grouped = CATEGORIES.map((cat) => ({
    ...cat,
    items: indicators.filter((i) => i.category === cat.key),
    enabledWeight: cat.weight,
  }))

  const totalWeight = CATEGORIES.reduce((s, g) => s + g.weight, 0)

  const updateIndicator = async (id, patch) => {
    setBusy(true)
    try {
      await api.post(`/api/settings/risk-indicators/${id}`, patch)
      await load()
      setNotice('تم الحفظ')
    } catch (e) {
      setErr(e.message)
    } finally {
      setBusy(false)
    }
  }

  const recompute = async () => {
    if (!window.confirm('إعادة حساب درجات الغش لجميع الجلسات بالمعادلة الجديدة؟')) return
    setBusy(true)
    setErr('')
    setNotice('')
    try {
      const d = await api.post('/api/settings/risk-indicators/recompute', {})
      setNotice(`تمت إعادة الحساب: ${d?.updated ?? 0} جلسة`)
    } catch (e) {
      setErr(e.message)
    } finally {
      setBusy(false)
    }
  }

  return (
    <div className="space-y-6">
      <header className="flex flex-wrap items-center justify-between gap-3 animate-fade-up">
        <div>
          <h1 className="text-2xl font-extrabold text-slate-800">معادلة الغش</h1>
          <p className="mt-1 text-sm text-slate-500">4 فئات — التقييم يتناسب مع التكرار والمدة، مع فلترة الأحداث المترابطة</p>
        </div>
        <button onClick={recompute} disabled={busy} className="flex items-center gap-2 rounded-xl bg-violet-600 px-4 py-2 text-xs font-bold text-white shadow-lg shadow-violet-600/25 transition-colors hover:bg-violet-700 disabled:opacity-60">
          <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2.2" strokeLinecap="round"><path d="M20 6 9 17l-5-5" /></svg>
          إعادة حساب درجات الغش
        </button>
      </header>

      {err && <div className="rounded-xl border border-rose-200 bg-rose-50 px-4 py-3 text-sm font-bold text-rose-700">{err}</div>}
      {notice && <div className="rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm font-bold text-emerald-700">{notice}</div>}

      {/* معادلة مختصرة */}
      <Reveal>
        <Card className="p-6">
          <h2 className="text-sm font-extrabold text-slate-800">المعادلة النهائية</h2>
          <p className="mt-1 text-xs text-slate-500">نسبة غش الطالب = مجموع الفئات الأربعة (حتى 100%)</p>
          <div className="mt-4 flex items-center gap-2 text-sm font-bold text-slate-600 flex-wrap">
            {grouped.map((g, i) => (
              <span key={g.key} className="flex items-center gap-1">
                {i > 0 && <span className="text-slate-300">+</span>}
                <span className={`rounded-lg px-2 py-0.5 ${COLOR_MAP[g.color].bg} ${COLOR_MAP[g.color].text}`}>
                  {g.label} {g.enabledWeight}%
                </span>
              </span>
            ))}
            <span className="text-slate-300 mx-1">=</span>
            <span className={`text-lg ${totalWeight === 100 ? 'text-emerald-600' : 'text-amber-600'}`}>
              {totalWeight}%
            </span>
          </div>
          <div className="mt-4 h-4 w-full overflow-hidden rounded-full bg-slate-100 flex">
            {grouped.map((g) => (
              <div
                key={g.key}
                className={`h-full ${COLOR_MAP[g.color].bar} transition-all duration-500`}
                style={{ width: `${g.enabledWeight}%` }}
                title={`${g.label}: ${g.enabledWeight}%`}
              />
            ))}
          </div>
        </Card>
      </Reveal>

      {/* كيف تعمل المعادلة */}
      <Reveal delay={50}>
        <Card className="p-6">
          <h2 className="text-sm font-extrabold text-slate-800">كيف يعمل الحساب؟</h2>
          <div className="mt-3 grid grid-cols-1 gap-3 sm:grid-cols-3 text-[12px]">
            <div className="rounded-xl bg-emerald-50 p-3 ring-1 ring-emerald-200">
              <p className="font-extrabold text-emerald-700">تناسب مع التكرار</p>
              <p className="mt-1 text-emerald-600">Lصق 30 مرة score أعلى من مرة واحدة. كل مؤشر يحتوي على عتبات (thresholds) تحوّل العدد إلى نسبة 0–1.</p>
            </div>
            <div className="rounded-xl bg-violet-50 p-3 ring-1 ring-violet-200">
              <p className="font-extrabold text-violet-700">تناسب مع المدة</p>
              <p className="mt-1 text-violet-600">إخفاء التبويب 10 دقائق score أعلى بكثير من ثانيتين. المدة تُقيَّم بشكل منفصل.</p>
            </div>
            <div className="rounded-xl bg-amber-50 p-3 ring-1 ring-amber-200">
              <p className="font-extrabold text-amber-700"> فلترة الأحداث المترابطة</p>
              <p className="mt-1 text-amber-600">انتقال واحد بين التبويبات يولّد blur + tab_hidden + duration. المعادلة تضعهم في مجموعة وتُخفّف التكرار.</p>
            </div>
          </div>
        </Card>
      </Reveal>

      {/* 4 بطاقات فئات */}
      <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
        {grouped.map((g, i) => {
          const c = COLOR_MAP[g.color]
          return (
            <Reveal key={g.key} delay={i * 60}>
              <Card className="p-5 cursor-pointer hover:-translate-y-1 transition-all duration-300" hover glow onClick={() => setExpanded(expanded === g.key ? null : g.key)}>
                <div className="flex items-center gap-3">
                  <span className="text-2xl">{g.icon}</span>
                  <div>
                    <p className="text-xs font-bold text-slate-400">{g.label}</p>
                    <p className={`text-2xl font-extrabold ${c.text}`}>{g.enabledWeight}%</p>
                  </div>
                </div>
                <p className="mt-2 text-[11px] text-slate-400">{g.desc}</p>
                <div className="mt-3 h-2 w-full overflow-hidden rounded-full bg-slate-100">
                  <div className={`h-full rounded-full ${c.bar} transition-all duration-500`} style={{ width: `${g.enabledWeight}%` }} />
                </div>
                <p className="mt-2 text-[11px] font-bold text-slate-400">{g.items.filter((x) => Number(x.enabled) === 1).length} محدد مفعّل من {g.items.length}</p>
              </Card>
            </Reveal>
          )
        })}
      </div>

      {/* تفاصيل كل فئة */}
      {grouped.map((g) => {
        if (expanded !== g.key) return null
        const c = COLOR_MAP[g.color]
        return (
          <Reveal key={`detail-${g.key}`}>
            <Card className="overflow-hidden">
              <div className={`flex items-center justify-between px-5 py-3 ${c.bg}`}>
                <div className="flex items-center gap-2">
                  <span className="text-lg">{g.icon}</span>
                  <h3 className={`text-sm font-extrabold ${c.text}`}>فئة {g.label} — {g.enabledWeight}%</h3>
                </div>
                <span className={`rounded-lg bg-white/80 px-2 py-0.5 text-xs font-bold ${c.text} ring-1 ${c.ring}`}>
                  {g.weight}% من الخطورة
                </span>
              </div>
              <div className="divide-y divide-slate-100">
                {g.items.map((ind) => {
                  const on = Number(ind.enabled) === 1
                  return (
                    <div key={ind.id} className={`flex flex-wrap items-center gap-3 px-5 py-3 transition-opacity ${on ? '' : 'opacity-50'}`}>
                      <div className="min-w-0 flex-1">
                        <div className="flex items-center gap-2">
                          <span className="text-sm font-bold text-slate-700">{ind.label_ar}</span>
                          <span className="rounded bg-slate-100 px-1.5 py-0.5 text-[10px] font-bold text-slate-500" dir="ltr">{ind.indicator_key}</span>
                        </div>
                        {ind.description && <p className="mt-0.5 text-[11px] text-slate-400">{ind.description}</p>}
                      </div>
                      <div className="flex items-center gap-3">
                        <input
                          type="number"
                          min="0"
                          max="100"
                          step="0.5"
                          value={ind.weight_percent}
                          disabled={!on || busy}
                          onChange={(e) => updateIndicator(ind.id, { weight: Number(e.target.value) })}
                          className="w-20 rounded-lg border border-slate-200 px-2 py-1.5 text-sm font-extrabold tabular-nums text-slate-700 outline-none focus:border-brand-500"
                        />
                        <button
                          onClick={() => updateIndicator(ind.id, { enabled: !on })}
                          disabled={busy}
                          className={`relative h-6 w-11 shrink-0 rounded-full transition-colors duration-200 ${on ? 'bg-brand-600' : 'bg-slate-300'}`}
                        >
                          <span className={`absolute top-0.5 h-5 w-5 rounded-full bg-white shadow transition-all duration-200 ${on ? 'right-0.5' : 'right-[22px]'}`} />
                        </button>
                      </div>
                    </div>
                  )
                })}
              </div>
            </Card>
          </Reveal>
        )
      })}

      {/* تصنيف الخطورة */}
      <Reveal delay={200}>
        <Card className="p-5">
          <h2 className="text-sm font-extrabold text-slate-800">تصنيف نسبة الغش</h2>
          <div className="mt-3 grid grid-cols-5 gap-2">
            {[
              { min: '0–19%', l: 'آمن', c: 'bg-emerald-100 text-emerald-700' },
              { min: '20–39%', l: 'منخفض', c: 'bg-teal-100 text-teal-700' },
              { min: '40–59%', l: 'متوسط', c: 'bg-amber-100 text-amber-700' },
              { min: '60–79%', l: 'مرتفع', c: 'bg-orange-100 text-orange-700' },
              { min: '80–100%', l: 'حرج', c: 'bg-rose-100 text-rose-700' },
            ].map((b) => (
              <div key={b.l} className={`rounded-xl px-3 py-2.5 text-center ring-1 ring-inset ${b.c}`}>
                <p className="text-xs font-extrabold">{b.l}</p>
                <p className="mt-0.5 text-[10px] font-semibold opacity-70">{b.min}</p>
              </div>
            ))}
          </div>
          <p className="mt-3 text-[11px] text-slate-400">بعد تعديل المعادلة اضغط «إعادة حساب» لتطبيق النِسَب الجديدة.</p>
        </Card>
      </Reveal>
    </div>
  )
}
