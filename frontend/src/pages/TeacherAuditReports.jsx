import { useEffect, useState } from 'react'
import { api } from '../api'
import Card from '../components/Card'
import Spinner from '../components/Spinner'
import { Reveal } from '../components/motion'

export default function TeacherAuditReports() {
  const [exams, setExams] = useState([])
  const [selectedExamId, setSelectedExamId] = useState('')
  const [reportData, setReportData] = useState(null)
  const [loading, setLoading] = useState(false)
  const [error, setError] = useState('')
  const [search, setSearch] = useState('')
  const [filterLevel, setFilterLevel] = useState('all')

  const [autoRefresh, setAutoRefresh] = useState(true)

  useEffect(() => {
    api.get('/api/teacher/exams')
      .then((data) => {
        const list = Array.isArray(data) ? data : []
        setExams(list)
        if (list.length > 0 && !selectedExamId) {
          setSelectedExamId(list[0].id)
        }
      })
      .catch((e) => setError(e.message || 'فشل تحميل الامتحانات'))
  }, [])

  useEffect(() => {
    if (!selectedExamId) return
    let active = true

    function fetchReport(showSpinner = false) {
      if (showSpinner) setLoading(true)
      api.get(`/api/teacher/reports/exam/${selectedExamId}`)
        .then((data) => {
          if (active) {
            setReportData(data)
            setError('')
          }
        })
        .catch((e) => {
          if (active && !reportData) setError(e.message || 'فشل تحميل تقرير الامتحان')
        })
        .finally(() => {
          if (active && showSpinner) setLoading(false)
        })
    }

    fetchReport(true)

    let timer = null
    if (autoRefresh) {
      timer = setInterval(() => fetchReport(false), 4000)
    }

    return () => {
      active = false
      if (timer) clearInterval(timer)
    }
  }, [selectedExamId, autoRefresh])

  const formatDateTime = (dtStr) => {
    if (!dtStr) return '—'
    try {
      const d = new Date(dtStr.replace(' ', 'T'))
      if (isNaN(d.getTime())) return dtStr
      return d.toLocaleTimeString('ar-EG', { hour: '2-digit', minute: '2-digit', second: '2-digit' })
    } catch {
      return dtStr
    }
  }

  const formatDuration = (sec) => {
    if (!sec || sec <= 0) return '—'
    const m = Math.floor(sec / 60)
    const s = sec % 60
    if (m === 0) return `${s} ث`
    return `${m} د ${s > 0 ? `${s}ث` : ''}`
  }

  const exportCSV = () => {
    if (!reportData || !reportData.students) return
    const headers = [
      'Student ID',
      'Full Name',
      'Username',
      'Start Time',
      'End Time',
      'Duration (Seconds)',
      'Duration (Formatted)',
      'Risk Score (%)',
      'Risk Level',
      'Behavioral (B %)',
      'AI Suspect (A %)',
      'Similarity (S %)',
      'Network (N %)',
      'Focus Lost Count',
      'Away Duration (s)',
      'Paste Count',
      'Same IP Count',
    ]

    const rows = reportData.students.map((s) => [
      s.student_id,
      `"${s.fullname}"`,
      s.username,
      s.start_time || '',
      s.end_time || '',
      s.duration_seconds || 0,
      `"${formatDuration(s.duration_seconds)}"`,
      s.risk_score,
      s.risk_level,
      s.behavioral_risk_score,
      s.ai_suspect_score,
      s.similarity_max_score,
      s.same_ip_risk_score,
      s.focus_lost_count || 0,
      s.tab_hidden_seconds || 0,
      s.paste_count || 0,
      s.same_ip_student_count || 0,
    ])

    const csvContent = 'data:text/csv;charset=utf-8,\uFEFF' + [headers.join(','), ...rows.map(r => r.join(','))].join('\n')
    const encodedUri = encodeURI(csvContent)
    const link = document.createElement('a')
    link.setAttribute('href', encodedUri)
    link.setAttribute('download', `exam_audit_report_${selectedExamId}_${new Date().toISOString().slice(0, 10)}.csv`)
    document.body.appendChild(link)
    link.click()
    document.body.removeChild(link)
  }

  const handlePrint = () => {
    window.print()
  }

  const students = (reportData?.students || []).filter((s) => {
    const matchSearch =
      (s.fullname && s.fullname.toLowerCase().includes(search.toLowerCase())) ||
      (s.username && s.username.toLowerCase().includes(search.toLowerCase()))
    const matchLevel = filterLevel === 'all' || s.risk_level === filterLevel
    return matchSearch && matchLevel
  })

  const getRiskBadge = (level, score) => {
    switch (level) {
      case 'critical':
        return <span className="inline-flex items-center justify-center whitespace-nowrap rounded-lg bg-rose-100 px-2.5 py-1 text-xs font-black text-rose-800 border border-rose-200">🚨 حرج ({score}%)</span>
      case 'high':
        return <span className="inline-flex items-center justify-center whitespace-nowrap rounded-lg bg-orange-100 px-2.5 py-1 text-xs font-black text-orange-800 border border-orange-200">⚠️ مرتفع ({score}%)</span>
      case 'medium':
        return <span className="inline-flex items-center justify-center whitespace-nowrap rounded-lg bg-amber-100 px-2.5 py-1 text-xs font-black text-amber-800 border border-amber-200">⚡ متوسط ({score}%)</span>
      case 'low':
        return <span className="inline-flex items-center justify-center whitespace-nowrap rounded-lg bg-blue-100 px-2.5 py-1 text-xs font-black text-blue-800 border border-blue-200">ℹ️ منخفض ({score}%)</span>
      default:
        return <span className="inline-flex items-center justify-center whitespace-nowrap rounded-lg bg-emerald-100 px-2.5 py-1 text-xs font-black text-emerald-800 border border-emerald-200">✓ آمن ({score}%)</span>
    }
  }

  return (
    <div className="space-y-6 print:space-y-4">
      {/* Header */}
      <header className="flex flex-wrap items-center justify-between gap-4 animate-fade-up print:hidden">
        <div>
          <div className="flex items-center gap-2">
            <span className="flex h-8 w-8 items-center justify-center rounded-xl bg-indigo-600 text-white shadow-md shadow-indigo-600/20 font-extrabold text-sm">📑</span>
            <h1 className="text-2xl font-black text-slate-800">سجل الأدلة والتقارير الأكاديمية (Forensic Dossier)</h1>
          </div>
          <p className="mt-1 text-xs font-bold text-slate-500">
            ملفات جنائية رقمية متكاملة لتقديمها للجان النزاهة والتحقيق الأكاديمي مع إمكانية التصدير والطباعة
          </p>
        </div>

        <div className="flex flex-wrap items-center gap-2.5">
          <select
            value={selectedExamId}
            onChange={(e) => setSelectedExamId(e.target.value)}
            className="rounded-xl border border-slate-200 bg-white px-3.5 py-2 text-xs font-extrabold text-slate-700 outline-none shadow-sm focus:border-brand-500"
          >
            {exams.map((ex) => (
              <option key={ex.id} value={ex.id}>
                {ex.name}
              </option>
            ))}
          </select>

          <button
            onClick={exportCSV}
            disabled={!reportData}
            className="flex items-center gap-1.5 rounded-xl border border-slate-200 bg-white px-3.5 py-2 text-xs font-black text-slate-700 hover:bg-slate-50 shadow-sm transition-all disabled:opacity-50"
          >
            <span>📊</span>
            <span>تصدير CSV</span>
          </button>

          <button
            onClick={() => window.open(`/api/teacher/reports/exam/${selectedExamId}/export-raw-json`, '_blank')}
            disabled={!reportData}
            className="flex items-center gap-1.5 rounded-xl border border-indigo-200 bg-indigo-50/80 px-3.5 py-2 text-xs font-black text-indigo-700 hover:bg-indigo-100 shadow-sm transition-all disabled:opacity-50"
            title="تنزيل حزمة الأحداث والمؤشرات الخام بالكامل بصيغة JSON"
          >
            <span>💾</span>
            <span>تصدير JSON الخام</span>
          </button>

          <button
            onClick={handlePrint}
            disabled={!reportData}
            className="flex items-center gap-1.5 rounded-xl bg-indigo-600 px-4 py-2 text-xs font-black text-white hover:bg-indigo-700 shadow-md shadow-indigo-600/20 transition-all disabled:opacity-50"
          >
            <span>🖨️</span>
            <span>طباعة التقرير الرسمي</span>
          </button>
        </div>
      </header>

      {/* Printable Report Header */}
      <div className="hidden print:block border-b-2 border-slate-800 pb-4">
        <div className="flex justify-between items-center">
          <div>
            <h1 className="text-xl font-black text-slate-900">تقرير النزاهة الأكاديمية والأدلة الرقمية</h1>
            <p className="text-xs font-bold text-slate-600 mt-1">الامتحان: {reportData?.exam?.name} | المساق: {reportData?.exam?.course_name}</p>
          </div>
          <div className="text-left text-xs font-bold text-slate-500">
            <p>تاريخ التقرير: {new Date().toLocaleDateString('ar-EG')}</p>
            <p>المعيار الأمني: NIST SP 800-30</p>
          </div>
        </div>
      </div>

      {error && (
        <div className="rounded-xl border border-rose-200 bg-rose-50 px-4 py-3 text-xs font-extrabold text-rose-800">
          {error}
        </div>
      )}

      {loading ? (
        <div className="py-20 text-center">
          <Spinner />
          <p className="mt-2 text-xs font-bold text-slate-400">جاري تجميع وتحليل بيانات الامتحان...</p>
        </div>
      ) : reportData ? (
        <div className="space-y-6 print:space-y-4">
          {/* Summary KPI Cards */}
          <div className="grid grid-cols-2 gap-3 sm:grid-cols-4 print:grid-cols-4">
            <Card className="p-4 border-slate-100">
              <p className="text-[11px] font-extrabold text-slate-400">إجمالي طلاب الامتحان</p>
              <p className="mt-1 text-2xl font-black text-slate-800">{reportData.stats?.total_students || 0}</p>
            </Card>
            <Card className="p-4 border-rose-100 bg-rose-50/30">
              <p className="text-[11px] font-extrabold text-rose-600">حالات الخطر المرتفع والحرج</p>
              <p className="mt-1 text-2xl font-black text-rose-700">{reportData.stats?.high_critical_count || 0}</p>
            </Card>
            <Card className="p-4 border-amber-100 bg-amber-50/30">
              <p className="text-[11px] font-extrabold text-amber-600">حالات الخطر المتوسط (مراجعة)</p>
              <p className="mt-1 text-2xl font-black text-amber-700">{reportData.stats?.medium_count || 0}</p>
            </Card>
            <Card className="p-4 border-emerald-100 bg-emerald-50/30">
              <p className="text-[11px] font-extrabold text-emerald-600">طلاب بسلوك طبيعي آمن</p>
              <p className="mt-1 text-2xl font-black text-emerald-700">{reportData.stats?.low_safe_count || 0}</p>
            </Card>
          </div>

          {/* Filter and Search Bar */}
          <div className="flex flex-wrap items-center justify-between gap-3 print:hidden">
            <div className="flex flex-wrap items-center gap-2">
              <input
                type="text"
                value={search}
                onChange={(e) => setSearch(e.target.value)}
                placeholder="البحث باسم الطالب أو رقم القيد..."
                className="w-64 rounded-xl border border-slate-200 bg-white px-3.5 py-2 text-xs font-extrabold text-slate-700 outline-none shadow-sm focus:border-brand-500"
              />
              <select
                value={filterLevel}
                onChange={(e) => setFilterLevel(e.target.value)}
                className="rounded-xl border border-slate-200 bg-white px-3 py-2 text-xs font-extrabold text-slate-700 outline-none shadow-sm"
              >
                <option value="all">جميع مستويات الخطورة</option>
                <option value="critical">حرج (Critical)</option>
                <option value="high">مرتفع (High)</option>
                <option value="medium">متوسط (Moderate)</option>
                <option value="low">منخفض (Low)</option>
                <option value="safe">آمن (Safe)</option>
              </select>
              <button
                onClick={() => setAutoRefresh(!autoRefresh)}
                className={`inline-flex items-center gap-1.5 rounded-xl px-3 py-2 text-xs font-bold transition-all shadow-sm ${
                  autoRefresh
                    ? 'bg-emerald-50 text-emerald-700 border border-emerald-200'
                    : 'bg-slate-100 text-slate-500 border border-slate-200'
                }`}
              >
                <span className="relative flex h-2 w-2">
                  {autoRefresh && <span className="absolute inline-flex h-full w-full animate-ping rounded-full bg-emerald-400 opacity-75" />}
                  <span className={`relative inline-flex h-2 w-2 rounded-full ${autoRefresh ? 'bg-emerald-500' : 'bg-slate-400'}`} />
                </span>
                {autoRefresh ? 'تحديث لحظي نشط' : 'تحديث يدوي'}
              </button>
            </div>
            <span className="text-xs font-bold text-slate-400">عدد الطلاب المسجلين: ({students.length})</span>
          </div>

          {/* Student Dossier Table */}
          <Reveal>
            <Card className="overflow-hidden p-0 border-slate-200">
              <div className="overflow-x-auto">
                <table className="w-full text-right text-xs">
                  <thead>
                    <tr className="border-b border-slate-100 bg-slate-50/60 text-slate-600 font-extrabold">
                      <th className="px-4 py-3.5">الطالب</th>
                      <th className="px-4 py-3.5 text-center">وقت البدء</th>
                      <th className="px-4 py-3.5 text-center">وقت الانتهاء</th>
                      <th className="px-4 py-3.5 text-center">المدة المستغرقة</th>
                      <th className="px-4 py-3.5">مستوى الخطر الإجمالي</th>
                      <th className="px-4 py-3.5 text-center">السلوك (B)</th>
                      <th className="px-4 py-3.5 text-center">الذكاء (A)</th>
                      <th className="px-4 py-3.5 text-center">التشابه (S)</th>
                      <th className="px-4 py-3.5 text-center">الشبكة (N)</th>
                      <th className="px-4 py-3.5">المؤشرات المسجلة</th>
                    </tr>
                  </thead>
                  <tbody className="divide-y divide-slate-100">
                    {students.map((st) => (
                      <tr key={st.id || st.session_id} className="transition-colors hover:bg-slate-50/60">
                        <td className="px-4 py-3.5">
                          <p className="font-black text-slate-800">{st.fullname}</p>
                          <p className="text-[10px] font-bold text-slate-400">ID: {st.moodle_user_id || st.student_id} | {st.username}</p>
                        </td>
                        <td className="px-4 py-3.5 text-center font-bold text-slate-700 whitespace-nowrap" dir="ltr">
                          {formatDateTime(st.start_time)}
                        </td>
                        <td className="px-4 py-3.5 text-center font-bold text-slate-700 whitespace-nowrap" dir="ltr">
                          {formatDateTime(st.end_time)}
                        </td>
                        <td className="px-4 py-3.5 text-center font-bold text-indigo-700 whitespace-nowrap">
                          <span className="rounded-md bg-indigo-50 px-2 py-1 border border-indigo-100 text-[11px]">
                            {formatDuration(st.duration_seconds)}
                          </span>
                        </td>
                        <td className="px-4 py-3.5 text-center whitespace-nowrap">
                          {getRiskBadge(st.risk_level, st.risk_score)}
                        </td>
                        <td className="px-4 py-3.5 text-center font-bold text-brand-700">
                          {st.behavioral_risk_score}%
                        </td>
                        <td className="px-4 py-3.5 text-center font-bold text-cyan-700">
                          {st.ai_suspect_score}%
                        </td>
                        <td className="px-4 py-3.5 text-center font-bold text-amber-700">
                          {st.similarity_max_score}%
                        </td>
                        <td className="px-4 py-3.5 text-center font-bold text-violet-700">
                          {st.same_ip_risk_score}%
                        </td>
                        <td className="px-4 py-3.5">
                          <div className="flex flex-wrap gap-1 text-[10px] font-bold">
                            {st.focus_lost_count > 0 && (
                              <span className="rounded bg-amber-50 px-1.5 py-0.5 text-amber-700 border border-amber-200">
                                ⚡ فقد تركيز: {st.focus_lost_count}
                              </span>
                            )}
                            {st.tab_hidden_seconds > 0 && (
                              <span className="rounded bg-rose-50 px-1.5 py-0.5 text-rose-700 border border-rose-200">
                                ⏱ غياب: {st.tab_hidden_seconds} ث
                              </span>
                            )}
                            {st.paste_count > 0 && (
                              <span className="rounded bg-purple-50 px-1.5 py-0.5 text-purple-700 border border-purple-200">
                                📋 لصق: {st.paste_count}
                              </span>
                            )}
                            {st.same_ip_student_count > 0 && (
                              <span className="rounded bg-blue-50 px-1.5 py-0.5 text-blue-700 border border-blue-200">
                                🌐 تكرار IP: {st.same_ip_student_count}
                              </span>
                            )}
                            {!st.focus_lost_count && !st.tab_hidden_seconds && !st.paste_count && (
                              <span className="text-slate-400 font-normal">لا توجد مخالفات مرصودة</span>
                            )}
                          </div>
                        </td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
            </Card>
          </Reveal>
        </div>
      ) : (
        <div className="py-16 text-center">
          <p className="text-sm font-bold text-slate-500">اختر امتحاناً لعرض تقرير الأدلة والنزاهة الأكاديمية</p>
        </div>
      )}
    </div>
  )
}
