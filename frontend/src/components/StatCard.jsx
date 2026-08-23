import { useEffect, useRef, useState } from 'react'
import { Reveal, Tilt } from './motion'
import { fmtNum } from '../lib/format'

function useCountUp(target, duration = 900) {
 const [value, setValue] = useState(target)
 const prev = useRef(target)

 useEffect(() => {
 if (target === prev.current) return
 prev.current = target
 const t0 = performance.now()
 const from = value
 let raf
 const step = (now) => {
 const p = Math.min(1, (now - t0) / duration)
 const eased = 1 - Math.pow(1 - p, 3)
 setValue(Math.round(from + (target - from) * eased))
 if (p < 1) raf = requestAnimationFrame(step)
 }
 raf = requestAnimationFrame(step)
 return () => cancelAnimationFrame(raf)
 }, [target, duration])

 return value
}

const ACCENTS = {
 brand: { icon: 'bg-brand-100 text-brand-600', glow: 'bg-brand-400', ring: 'from-brand-500 to-brand-600' },
 violet: { icon: 'bg-violet-100 text-violet-600', glow: 'bg-violet-400', ring: 'from-violet-500 to-violet-600' },
 cyan: { icon: 'bg-cyan-100 text-cyan-600', glow: 'bg-cyan-400', ring: 'from-cyan-500 to-cyan-600' },
 amber: { icon: 'bg-amber-100 text-amber-600', glow: 'bg-amber-400', ring: 'from-amber-500 to-amber-600' },
 emerald: { icon: 'bg-emerald-100 text-emerald-600', glow: 'bg-emerald-400', ring: 'from-emerald-500 to-emerald-600' },
 rose: { icon: 'bg-rose-100 text-rose-600', glow: 'bg-rose-400', ring: 'from-rose-500 to-rose-600' },
}

export default function StatCard({ title, value, icon, accent = 'brand', delay = 0 }) {
 const count = useCountUp(value)
 const a = ACCENTS[accent] || ACCENTS.brand

 return (
 <Reveal delay={delay}>
 <Tilt>
 <div
 className="group relative h-full overflow-hidden rounded-2xl bg-white p-5 ring-1 ring-slate-200/70 shadow-[0_1px_2px_rgba(16,24,40,.04),0_8px_24px_-12px_rgba(16,24,40,.08)]"
 role="region"
 aria-label={title}
 >
 <div
 className={`pointer-events-none absolute -left-10 -top-10 h-32 w-32 rounded-full opacity-[.08] blur-2xl transition-opacity duration-500 group-hover:opacity-25 group-hover:scale-110 ${a.glow}`}
 />
 <div className="absolute inset-x-0 bottom-0 h-1 bg-gradient-to-r opacity-0 transition-opacity duration-300 group-hover:opacity-100"style={{ backgroundImage: `linear-gradient(to right, var(--tw-gradient-stops))` }}>
 <div className={`h-full bg-gradient-to-r ${a.ring}`} />
 </div>
 <div className="flex items-start justify-between gap-3">
 <div className="min-w-0">
 <p className="text-sm font-semibold text-slate-500">{title}</p>
 <p className="mt-2 text-3xl font-extrabold tabular-nums tracking-tight text-slate-800"aria-live="polite">
 {fmtNum(count)}
 </p>
 </div>
 <div className={`flex h-11 w-11 shrink-0 items-center justify-center rounded-xl ${a.icon} transition-transform duration-300 group-hover:scale-110 group-hover:rotate-3`} aria-hidden="true">
 {icon}
 </div>
 </div>
 </div>
 </Tilt>
 </Reveal>
 )
}
