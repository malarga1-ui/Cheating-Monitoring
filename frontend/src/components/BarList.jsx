import { useEffect, useState } from 'react'
import { fmtNum } from '../lib/format'

export default function BarList({ items, valueKey = 'count', labelKey = 'label', colorKey = null, max = null }) {
  const [on, setOn] = useState(false)
  useEffect(() => {
    const t = setTimeout(() => setOn(true), 150)
    return () => clearTimeout(t)
  }, [])

  if (!items || items.length === 0) {
    return <p className="py-8 text-center text-sm text-slate-400">لا توجد بيانات</p>
  }

  const top = max ?? Math.max(...items.map((i) => i[valueKey]), 1)

  return (
    <ul className="space-y-3">
      {items.map((item, idx) => {
        const pct = Math.max(2, Math.round((item[valueKey] / top) * 100))
        return (
          <li key={idx}>
            <div className="mb-1 flex items-center justify-between gap-2 text-sm">
              <span className="truncate font-semibold text-slate-600">{item[labelKey]}</span>
              <span className="tabular-nums font-bold text-slate-800">{fmtNum(item[valueKey])}</span>
            </div>
            <div className="h-2 overflow-hidden rounded-full bg-slate-100">
              <div
                className={`h-full rounded-full ${colorKey ? item[colorKey] : 'bg-gradient-to-l from-brand-500 to-violet-500'}`}
                style={{
                  width: on ? `${pct}%` : '0%',
                  transition: 'width 1s cubic-bezier(.16,1,.3,1)',
                  transitionDelay: `${idx * 60}ms`,
                }}
              />
            </div>
          </li>
        )
      })}
    </ul>
  )
}
