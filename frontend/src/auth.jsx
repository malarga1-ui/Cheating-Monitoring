import { createContext, useContext, useEffect, useState, useCallback } from 'react'
import { api, setCsrfToken, setOnUnauthorized } from './api'

const AuthContext = createContext(null)

export function AuthProvider({ children }) {
  const [user, setUser] = useState(null)
  const [status, setStatus] = useState(null)
  const [csrf, setCsrf] = useState(null)
  const [loading, setLoading] = useState(true)
  const [mustChangePassword, setMustChangePassword] = useState(false)

  const applySession = useCallback((res) => {
    setUser(res.user)
    setStatus(res.status ?? null)
    setCsrf(res.csrf)
    setCsrfToken(res.csrf)
    setMustChangePassword(res.must_change_password ?? false)
  }, [])

  const check = useCallback(async () => {
    try {
      const res = await api.get('/api/auth/me')
      applySession(res)
    } catch {
      setUser(null)
      setStatus(null)
      setCsrf(null)
      setCsrfToken(null)
    } finally {
      setLoading(false)
    }
  }, [applySession])

  useEffect(() => {
    setOnUnauthorized(() => () => {
      setUser(null)
      setStatus(null)
      setCsrf(null)
      setCsrfToken(null)
      window.location.href = '/login'
    })
  }, [])

  useEffect(() => {
    check()
  }, [check])

  const login = async (email, password) => {
    const res = await api.post('/api/auth/login', { email, password })
    applySession(res)
    return res
  }

  const teacherLogin = async (account_id, username, password) => {
    const res = await api.post('/api/auth/teacher-login', { account_id, username, password })
    applySession(res)
    return res
  }

  const teacherTokenLogin = async (token) => {
    const res = await api.post('/api/auth/teacher-token-login', { token })
    applySession(res)
    return res
  }

  const teacherChangePassword = async (new_password, confirm_password) => {
    const res = await api.post('/api/auth/teacher-change-password', { new_password, confirm_password })
    setMustChangePassword(false)
    return res
  }

  const staffLogin = async (account_id, username, password) => {
    const res = await api.post('/api/auth/staff-login', { account_id, username, password })
    applySession(res)
    return res
  }

  const register = async ({ email, password, org_name, username }) => {
    const res = await api.post('/api/accounts/register', { email, password, org_name, username })
    applySession(res)
    return res
  }

  const refresh = async () => {
    try {
      const res = await api.get('/api/auth/me')
      applySession(res)
      return res
    } catch {
      return null
    }
  }

  const logout = async () => {
    try {
      await api.post('/api/auth/logout', {})
    } finally {
      setUser(null)
      setStatus(null)
      setCsrf(null)
      setCsrfToken(null)
      setMustChangePassword(false)
    }
  }

  return (
    <AuthContext.Provider
      value={{ user, status, csrf, loading, mustChangePassword, login, register, teacherLogin, teacherTokenLogin, teacherChangePassword, staffLogin, refresh, logout }}
    >
      {children}
    </AuthContext.Provider>
  )
}

export function useAuth() {
  return useContext(AuthContext)
}
