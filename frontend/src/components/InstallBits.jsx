import { useState } from 'react'

// Copyable terminal block (LTR)
export function Terminal({ lines }) {
  const [copied, setCopied] = useState(false)
  const text = lines.join('\n')
  const copy = async () => {
    try {
      await navigator.clipboard.writeText(text)
      setCopied(true)
      setTimeout(() => setCopied(false), 2000)
    } catch {
      /* ignore */
    }
  }
  return (
    <div className="overflow-hidden rounded-2xl bg-slate-900 shadow-2xl ring-1 ring-white/10" dir="ltr">
      <div className="flex items-center justify-between border-b border-white/10 px-4 py-3">
        <div className="flex items-center gap-1.5">
          <span className="h-3 w-3 rounded-full bg-rose-400" />
          <span className="h-3 w-3 rounded-full bg-amber-400" />
          <span className="h-3 w-3 rounded-full bg-emerald-400" />
        </div>
        <button
          onClick={copy}
          className="flex items-center gap-1.5 rounded-lg bg-white/10 px-3 py-1.5 text-[11px] font-bold text-slate-200 transition-colors hover:bg-white/20"
        >
          {copied ? '✓ تم النسخ' : 'نسخ الأوامر'}
        </button>
      </div>
      <div className="px-4 py-4 font-mono text-[12px] leading-relaxed text-emerald-300">
        {lines.map((l, i) => (
          <div key={i} className={l.startsWith('#') ? 'text-slate-500' : ''}>
            {l.startsWith('#') ? l : <span className="text-sky-400">$ </span>}
            {l.startsWith('#') ? '' : l}
          </div>
        ))}
      </div>
    </div>
  )
}

// Visual folder tree of the standard Moodle plugin path (LTR)
export function PathTree() {
  const rows = [
    { depth: 0, label: '<moodle_root>', highlight: false, note: '← مجلد مودل الرئيسي (مثال: /var/www/moodle)' },
    { depth: 1, label: 'mod', highlight: false },
    { depth: 2, label: 'quiz', highlight: false },
    { depth: 3, label: 'accessrule', highlight: false, note: '← مجلد قواعد وصول الاختبارات' },
    { depth: 4, label: 'exammonitor', highlight: true, note: '← الإضافة تستقر هنا وتعمل تلقائياً' },
  ]
  return (
    <div dir="ltr" className="overflow-hidden rounded-2xl bg-slate-900 font-mono text-[12px] ring-1 ring-white/10">
      <div className="border-b border-white/10 px-4 py-2.5 text-[11px] text-slate-400">Standard Moodle Directory Tree</div>
      <div className="px-4 py-3 leading-relaxed">
        {rows.map((r) => (
          <div
            key={r.depth}
            className={`flex items-center ${r.highlight ? 'rounded-lg bg-emerald-500/15 px-2 py-1 ring-1 ring-emerald-400/30' : 'py-0.5'}`}
            style={{ paddingLeft: `${r.depth * 22}px` }}
          >
            <span className="text-slate-500">└─</span>
            <span className={`ml-2 ${r.highlight ? 'font-extrabold text-emerald-300' : 'text-slate-300'}`}>{r.label}/</span>
            {r.note && <span className="ml-3 text-[10px] text-slate-500">{r.note}</span>}
          </div>
        ))}
      </div>
    </div>
  )
}

// Expected final contents of the plugin folder (LTR)
export function FileTree() {
  const files = [
    { name: 'amd/', type: 'dir', color: 'text-sky-300' },
    { name: 'classes/', type: 'dir', color: 'text-sky-300' },
    { name: 'db/', type: 'dir', color: 'text-sky-300' },
    { name: 'lang/', type: 'dir', color: 'text-sky-300' },
    { name: 'rule.php', type: 'file', color: 'text-slate-300' },
    { name: 'settings.php', type: 'file', color: 'text-slate-300' },
    { name: 'teacher.php', type: 'file', color: 'text-slate-300' },
    { name: 'version.php', type: 'file', color: 'text-slate-300' },
  ]
  return (
    <div dir="ltr" className="overflow-hidden rounded-2xl bg-slate-900 font-mono text-[12px] ring-1 ring-white/10">
      <div className="border-b border-white/10 px-4 py-2.5 text-[11px] text-slate-400">mod/quiz/accessrule/</div>
      <div className="px-4 py-3 leading-relaxed">
        <div className="font-extrabold text-emerald-300">exammonitor/</div>
        {files.map((f) => (
          <div key={f.name} className="flex items-center" style={{ paddingLeft: '22px' }}>
            <span className="text-slate-500">├──</span>
            <span className={`ml-2 ${f.color}`}>{f.name}</span>
            <span className="ml-2 text-[10px] text-slate-600">{f.type === 'dir' ? 'مجلد' : 'ملف'}</span>
          </div>
        ))}
        <div className="text-slate-600">└── ...</div>
      </div>
    </div>
  )
}
