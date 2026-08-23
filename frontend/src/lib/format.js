export function fmtNum(n) {
  return (Number(n) || 0).toLocaleString('en-US')
}

function pad(n) {
  return String(n).padStart(2, '0')
}

export function fmtTime(iso) {
  if (!iso) return '—'
  const d = new Date(iso)
  if (Number.isNaN(d.getTime())) return String(iso)
  return `${d.getFullYear()}/${pad(d.getMonth() + 1)}/${pad(d.getDate())} ${pad(d.getHours())}:${pad(d.getMinutes())}`
}

export function fmtDuration(ms) {
  if (!ms || ms <= 0) return '—'
  const totalSec = Math.round(ms / 1000)
  const m = Math.floor(totalSec / 60)
  const s = totalSec % 60
  if (m >= 60) {
    const h = Math.floor(m / 60)
    return `${h} س ${m % 60} د`
  }
  if (m > 0) return `${m} د ${s} ث`
  return `${s} ث`
}
