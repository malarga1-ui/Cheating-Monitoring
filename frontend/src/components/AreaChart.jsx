import { useEffect, useMemo, useState } from 'react'
import { fmtNum } from '../lib/format'

export default function AreaChart({ points, height = 220 }) {
  const [drawn, setDrawn] = useState(false)
  useEffect(() => {
    const t = setTimeout(() => setDrawn(true), 120)
    return () => clearTimeout(t)
  }, [])

  const W = 800
  const H = 260
  const PAD = { top: 18, right: 14, bottom: 30, left: 14 }

  const { linePath, areaPath, max } = useMemo(() => {
    if (!points || points.length < 2) return { linePath: '', areaPath: '', max: 0 }
    const values = points.map((p) => p.events)
    const maxV = Math.max(...values, 1)
    const innerW = W - PAD.left - PAD.right
    const innerH = H - PAD.top - PAD.bottom
    const x = (i) => PAD.left + (i / (points.length - 1)) * innerW
    const y = (v) => PAD.top + innerH - (v / maxV) * innerH

    let d = `M ${x(0)} ${y(points[0].events)}`
    for (let i = 1; i < points.length; i++) {
      const x0 = x(i - 1)
      const y0 = y(points[i - 1].events)
      const x1 = x(i)
      const y1 = y(points[i].events)
      const cx = (x0 + x1) / 2
      d += ` C ${cx} ${y0}, ${cx} ${y1}, ${x1} ${y1}`
    }
    const area = `${d} L ${x(points.length - 1)} ${PAD.top + innerH} L ${x(0)} ${PAD.top + innerH} Z`
    return { linePath: d, areaPath: area, max: maxV }
  }, [points])

  if (!points || points.length === 0) {
    return (
      <div className="flex h-44 items-center justify-center text-sm text-slate-400">
        لا توجد بيانات بعد
      </div>
    )
  }

  const last = points[points.length - 1]
  const showTicks = points.length <= 12

  return (
    <div className="relative" style={{ height }} role="img" aria-label={`رسم بياني: ${fmtNum(last.events)} حدث`}>
      <svg viewBox={`0 0 ${W} ${H}`} className="h-full w-full" preserveAspectRatio="none" aria-hidden="true">
        <title>توزيع الأحداث الزمني</title>
        <defs>
          <linearGradient id="areaFill" x1="0" y1="0" x2="0" y2="1">
            <stop offset="0%" stopColor="#2b7fff" stopOpacity="0.28" />
            <stop offset="100%" stopColor="#2b7fff" stopOpacity="0" />
          </linearGradient>
        </defs>
        {[0.25, 0.5, 0.75].map((f) => (
          <line
            key={f}
            x1={PAD.left}
            x2={W - PAD.right}
            y1={PAD.top + (H - PAD.top - PAD.bottom) * f}
            y2={PAD.top + (H - PAD.top - PAD.bottom) * f}
            stroke="#eef1f6"
            strokeWidth="1"
          />
        ))}
        <path
          d={areaPath}
          fill="url(#areaFill)"
          className="transition-opacity duration-700"
          style={{ opacity: drawn ? 1 : 0 }}
        />
        <path
          d={linePath}
          fill="none"
          stroke="#2b7fff"
          strokeWidth="3"
          strokeLinecap="round"
          strokeLinejoin="round"
          pathLength={1000}
          style={{
            strokeDasharray: 1000,
            strokeDashoffset: drawn ? 0 : 1000,
            transition: 'stroke-dashoffset 1.2s cubic-bezier(.4,0,.2,1)',
          }}
        />
        <circle cx={W - PAD.right} cy={PAD.top} r="4" fill="#2b7fff" className="animate-pulse" />
      </svg>
      <div className="pointer-events-none absolute -top-1 left-1/2 -translate-x-1/2 rounded-full bg-brand-600 px-3 py-1 text-xs font-bold text-white shadow-lg animate-fade-in">
        {fmtNum(last.events)} حدث
      </div>
      {showTicks && (
        <div className="mt-1 flex justify-between text-[10px] text-slate-400" dir="rtl">
          <span>{points[0]?.time}</span>
          <span>{last?.time}</span>
        </div>
      )}
    </div>
  )
}
