export function createSseClient(url, { onState, onClose, onFallback } = {}) {
  let source = null
  let reconnectTimer = null
  let fallbackCleanup = null
  let closed = false
  let closedByServer = false
  let reconnectAttempts = 0
  let waitingForOnline = false

  const handleOnline = () => {
    waitingForOnline = false
    window.removeEventListener('online', handleOnline)
    connect()
  }

  const cleanupSource = () => {
    if (source) {
      source.close()
      source = null
    }
  }

  const clearReconnectTimer = () => {
    if (reconnectTimer) {
      window.clearTimeout(reconnectTimer)
      reconnectTimer = null
    }
  }

  const close = () => {
    closed = true
    clearReconnectTimer()
    cleanupSource()
    if (waitingForOnline) {
      waitingForOnline = false
      window.removeEventListener('online', handleOnline)
    }
    if (typeof fallbackCleanup === 'function') {
      fallbackCleanup()
      fallbackCleanup = null
    }
  }

  const triggerFallback = () => {
    if (closed || fallbackCleanup) {
      return
    }

    cleanupSource()
    clearReconnectTimer()
    console.warn('[learning] SSE unavailable, falling back to polling:', url)

    if (typeof onFallback === 'function') {
      fallbackCleanup = onFallback() || null
    }
  }

  const scheduleReconnect = () => {
    if (closed || closedByServer || fallbackCleanup) {
      return
    }

    if (typeof navigator !== 'undefined' && navigator.onLine === false) {
      if (!waitingForOnline) {
        waitingForOnline = true
        window.addEventListener('online', handleOnline, { once: true })
      }
      return
    }

    if (reconnectAttempts >= 3) {
      triggerFallback()
      return
    }

    const delay = 1000 * Math.pow(2, reconnectAttempts)
    reconnectAttempts += 1
    clearReconnectTimer()
    reconnectTimer = window.setTimeout(() => {
      reconnectTimer = null
      connect()
    }, delay)
  }

  function connect() {
    if (closed || closedByServer || fallbackCleanup) {
      return
    }

    if (typeof window === 'undefined' || typeof window.EventSource === 'undefined') {
      triggerFallback()
      return
    }

    cleanupSource()

    try {
      source = new window.EventSource(url, { withCredentials: true })
    } catch (error) {
      scheduleReconnect()
      return
    }

    source.onopen = () => {
      reconnectAttempts = 0
    }

    source.addEventListener('state', (event) => {
      try {
        const data = JSON.parse(event.data)
        if (typeof onState === 'function') {
          onState(data)
        }
      } catch (error) {
        console.warn('[learning] Ignoring invalid SSE state payload:', error)
      }
    })

    source.addEventListener('close', () => {
      closedByServer = true
      if (typeof onClose === 'function') {
        onClose()
      }
      close()
    })

    source.onerror = () => {
      cleanupSource()
      scheduleReconnect()
    }
  }

  connect()

  return { close }
}
