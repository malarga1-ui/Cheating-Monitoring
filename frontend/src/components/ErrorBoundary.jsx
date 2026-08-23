import { Component } from 'react'

export default class ErrorBoundary extends Component {
  state = { error: null }

  static getDerivedStateFromError(error) {
    return { error }
  }

  render() {
    if (this.state.error) {
      return (
        <div className="flex min-h-screen items-center justify-center bg-[#f5f6f8] p-6">
          <div className="w-full max-w-md rounded-2xl bg-white p-8 text-center shadow-[0_24px_60px_-20px_rgba(16,24,40,.25)] ring-1 ring-slate-200/70 animate-fade-up">
            <div className="mx-auto flex h-16 w-16 items-center justify-center rounded-2xl bg-rose-50 text-rose-500">
              <svg width="30" height="30" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round">
                <path d="M10.3 3.9 1.8 18a2 2 0 0 0 1.7 3h17a2 2 0 0 0 1.7-3L13.7 3.9a2 2 0 0 0-3.4 0Z" />
                <path d="M12 9v4m0 4h.01" />
              </svg>
            </div>
            <h1 className="mt-4 text-lg font-extrabold text-slate-800">حدث خطأ غير متوقع</h1>
            <p className="mt-1 text-sm text-slate-500">
              تعذر عرض هذه الصفحة. جرّب إعادة التحميل أو العودة إلى لوحة التحكم.
            </p>
            <p className="mt-3 rounded-lg bg-slate-50 px-3 py-2 text-left text-[11px] font-mono text-slate-400" dir="ltr">
              {this.state.error?.message}
            </p>
            <div className="mt-5 flex justify-center gap-3">
              <button
                onClick={() => window.location.reload()}
                className="rounded-xl bg-brand-600 px-5 py-2.5 text-sm font-extrabold text-white shadow-lg shadow-brand-600/25 transition-all hover:bg-brand-700 active:scale-[.98]"
              >
                إعادة التحميل
              </button>
              <button
                onClick={() => {
                  this.setState({ error: null })
                  window.location.href = '/admin'
                }}
                className="rounded-xl bg-slate-100 px-5 py-2.5 text-sm font-bold text-slate-600 transition-colors hover:bg-slate-200"
              >
                الصفحة الرئيسية
              </button>
            </div>
          </div>
        </div>
      )
    }
    return this.props.children
  }
}
