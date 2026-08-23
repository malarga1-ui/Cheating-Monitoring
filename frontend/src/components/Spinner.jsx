export default function Spinner({ label = 'جارٍ التحميل…' }) {
  return (
    <div className="flex flex-col items-center justify-center gap-3 py-16">
      <div className="h-8 w-8 animate-spin rounded-full border-[3px] border-slate-200 border-t-brand-600" />
      <p className="text-sm text-slate-400">{label}</p>
    </div>
  )
}
