import { riskMeta } from '../lib/risk'

export default function RiskBadge({ level, score, className = '' }) {
  const meta = riskMeta(level)
  return (
    <span
      className={`inline-flex items-center gap-1.5 rounded-full px-2.5 py-0.5 text-xs font-bold ${meta.badge} ${className}`}
    >
      <span className={`h-1.5 w-1.5 rounded-full ${meta.bar}`} />
      {meta.label}
      {score !== undefined && <span className="font-semibold opacity-80">· {score}</span>}
    </span>
  )
}
