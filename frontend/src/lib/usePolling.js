import { useEffect, useRef } from 'react'

/**
 * Poll a load function on an interval, pausing while the tab is hidden and
 * resuming on visibility change. Guards against overlapping calls when the
 * interval fires while a previous request is still in flight.
 */
export function usePolling(load, intervalMs, deps = []) {
  const loadRef = useRef(load)
  const busyRef = useRef(false)

  useEffect(() => {
    loadRef.current = load
  })

  useEffect(() => {
    let alive = true
    let timer = null

    const tick = () => {
      if (!alive || busyRef.current || document.hidden) return
      busyRef.current = true
      Promise.resolve(loadRef.current()).finally(() => {
        busyRef.current = false
      })
    }

    const onVisible = () => {
      if (!document.hidden) tick()
    }

    tick()
    timer = setInterval(tick, intervalMs)
    document.addEventListener('visibilitychange', onVisible)

    return () => {
      alive = false
      if (timer) clearInterval(timer)
      document.removeEventListener('visibilitychange', onVisible)
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, deps)
}
