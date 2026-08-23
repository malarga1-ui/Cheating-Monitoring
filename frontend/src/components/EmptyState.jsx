export default function EmptyState({ icon, title, hint }) {
  return (
    <div className="flex flex-col items-center justify-center gap-2 py-14 text-center">
      <div className="flex h-14 w-14 items-center justify-center rounded-2xl bg-slate-100 text-slate-400">
        {icon}
      </div>
      <p className="text-sm font-bold text-slate-500">{title}</p>
      {hint && <p className="text-xs text-slate-400">{hint}</p>}
    </div>
  )
}
