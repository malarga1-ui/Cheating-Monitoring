import { useRef } from 'react'

export default function Card({ children, className = '', style, hover = false, glow = false }) {
 const ref = useRef(null)

 function onMove(e) {
 if (!glow || !ref.current) return
 const r = ref.current.getBoundingClientRect()
 ref.current.style.setProperty('--mx', `${((e.clientX - r.left) / r.width) * 100}%`)
 ref.current.style.setProperty('--my', `${((e.clientY - r.top) / r.height) * 100}%`)
 }

 return (
 <div
 ref={ref}
 onMouseMove={onMove}
 style={style}
 className={`group relative overflow-hidden rounded-2xl bg-white ring-1 ring-slate-200/70 shadow-[0_1px_2px_rgba(16,24,40,.04),0_8px_24px_-12px_rgba(16,24,40,.08)] ${
 hover ? 'transition-all duration-300 hover:shadow-[0_12px_32px_-12px_rgba(16,24,40,.16)] hover:-translate-y-0.5:shadow-[0_12px_32px_-12px_rgba(0,0,0,.4)]' : ''
 } ${className}`}
 >
 {glow && (
 <div
 className="pointer-events-none absolute -inset-px rounded-2xl opacity-0 transition-opacity duration-300 group-hover:opacity-100"
 style={{ background: 'radial-gradient(400px circle at var(--mx,50%) var(--my,50%), rgba(43,127,255,.08), transparent 70%)' }}
 />
 )}
 {children}
 </div>
 )
}
