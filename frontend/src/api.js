let csrfToken = null
let onUnauthorized = null

export function setCsrfToken(token) {
  csrfToken = token || null
}

export function setOnUnauthorized(fn) {
  onUnauthorized = fn
}

function getErrorMessage(status, data, networkError) {
  // Network errors (offline, DNS, connection refused)
  if (networkError) {
    return 'لا يوجد اتصال بالإنترنت. تحقق من اتصالك وأعد المحاولة.'
  }

  // Server responded with error status
  switch (status) {
    case 400:
      return data?.error || 'طلب غير صحيح. تحقق من البيانات المدخلة.'
    case 401:
      return data?.error || 'انتهت الجلسة أو أنت غير مسجّل. سجّل الدخول من جديد.'
    case 403:
      return data?.error || 'غير مصرح لك بهذا الإجراء. الحساب منتهي أو غير نشط.'
    case 404:
      return data?.error || 'المورد المطلوب غير موجود.'
    case 422:
      return data?.error || 'بيانات غير صالحة. تحقق من الحقول المطلوبة.'
    case 429:
      return data?.error || 'تم تجاوز الحد المسموح. انتظر قليلاً وأعد المحاولة.'
    case 500:
      return data?.error || 'خطأ في الخادم. حاول مرة أخرى لاحقاً.'
    case 503:
      return 'الخدمة غير متاحة حالياً. حاول لاحقاً.'
    default:
      return data?.error || `خطأ غير متوقع (كود: ${status})`
  }
}

async function request(path, options = {}) {
  const headers = { 'Content-Type': 'application/json', ...(options.headers || {}) }
  if (csrfToken) headers['X-CSRF-Token'] = csrfToken

  let res
  try {
    res = await fetch(path, {
      headers,
      credentials: 'same-origin',
      ...options,
    })
  } catch (e) {
    // Network error (offline, DNS, CORS, etc.)
    const err = new Error(getErrorMessage(0, null, true))
    err.status = 0
    err.isNetworkError = true
    throw err
  }

  let data = null
  try {
    data = await res.json()
  } catch {
    /* no body */
  }

  if (!res.ok) {
    const isAuthRoute = path.startsWith('/api/auth/login') ||
                        path.startsWith('/api/auth/teacher-login') ||
                        path.startsWith('/api/auth/staff-login') ||
                        path === '/api/auth/me'
    if (res.status === 401 && onUnauthorized && !isAuthRoute) {
      onUnauthorized()
    }
    const message = getErrorMessage(res.status, data, false)
    const err = new Error(message)
    err.status = res.status
    err.data = data
    throw err
  }

  // Backend wraps responses as { ok, data }. Unwrap so callers receive payload directly.
  return data && typeof data === 'object' && 'data' in data ? data.data : data
}

export const api = {
  get: (path) => request(path),
  post: (path, body) => request(path, { method: 'POST', body: JSON.stringify(body) }),
  put: (path, body) => request(path, { method: 'PUT', body: JSON.stringify(body) }),
  delete: (path) => request(path, { method: 'DELETE' }),
}