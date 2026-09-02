import { useEffect, useState } from 'react'
import { api } from '../api'
import Card from '../components/Card'
import Spinner from '../components/Spinner'
import { Reveal } from '../components/motion'

export default function TeacherActionCenter() {
  const [actions, setActions] = useState([])
  const [stats, setStats] = useState({ total: 0, pending: 0, delivered: 0, acknowledged: 0, active_locks: 0 })
  const [exams, setExams] = useState([])
  const [selectedExamId, setSelectedExamId] = useState('')
  const [loading, setLoading] = useState(true)
  const [busy, setBusy] = useState(false)
  const [notice, setNotice] = useState('')
  const [error, setError] = useState('')

  // Broadcast modal state
  const [showBroadcast, setShowBroadcast] = useState(false)
  const [broadcastMessage, setBroadcastMessage] = useState('')
  const [broadcastType, setBroadcastType] = useState('send_message')
  const [broadcastMinutes, setBroadcastMinutes] = useState(5)

  // Direct student action modal state
  const [directActionModal, setDirectActionModal] = useState(null) // { studentId, studentName, examId, type }
  const [directMsg, setDirectMsg] = useState('')
  const [directMin, setDirectMin] = useState(5)

  const loadData = async (silent = false) => {
    if (!silent) setLoading(true)
    try {
      const examParam = selectedExamId ? `?exam_id=${selectedExamId}` : ''
      const [actData, exData] = await Promise.all([
        api.get(`/api/teacher/actions/history${examParam}`),
        api.get('/api/teacher/exams'),
      ])
      setActions(actData.actions || [])
      setStats(actData.stats || { total: 0, pending: 0, delivered: 0, acknowledged: 0, active_locks: 0 })
      setExams(Array.isArray(exData) ? exData : [])
    } catch (e) {
      if (!silent) setError(e.message || 'فشل تحميل سجل الأوامر')
    } finally {
      if (!silent) setLoading(false)
    }
  }

  useEffect(() => {
    loadData()
    const timer = setInterval(() => loadData(true), 3000)
    return () => clearInterval(timer)
  }, [selectedExamId])

  const handleBroadcast = async () => {
    if (!selectedExamId) {
      setError('يرجى تحديد الامتحان للبث العام')
      return
    }
    if (broadcastType === 'send_message' && !broadcastMessage.trim()) {
      setError('يرجى كتابة نص الرسالة')
      return
    }

    setBusy(true)
    setError('')
    setNotice('')
    try {
      const res = await api.post('/api/teacher/actions/broadcast', {
        exam_id: selectedExamId,
        action_type: broadcastType,
        message: broadcastMessage,
        minutes: broadcastMinutes,
      })
      setNotice(res.message || 'تم إرسال البث بنجاح')
      setShowBroadcast(false)
      setBroadcastMessage('')
      await loadData(true)
    } catch (e) {
      setError(e.message || 'فشل إرسال البث العام')
    } finally {
      setBusy(false)
    }
  }

  const handleExecuteDirect = async () => {
    if (!directActionModal) return
    const { studentId, examId, type } = directActionModal

    setBusy(true)
    setError('')
    setNotice('')
    try {
      if (type === 'message') {
        if (!directMsg.trim()) return
        await api.post('/api/teacher/actions/message', { student_id: studentId, exam_id: examId, message: directMsg })
        setNotice('تم إرسال الرسالة للطالب')
      } else if (type === 'lock') {
        await api.post('/api/teacher/actions/lock', { student_id: studentId, exam_id: examId })
        setNotice('تم قفل الامتحان على الطالب')
      } else if (type === 'unlock') {
        await api.post('/api/teacher/actions/unlock', { student_id: studentId, exam_id: examId })
        setNotice('تم إلغاء قفل الامتحان')
      } else if (type === 'reduce-time') {
        await api.post('/api/teacher/actions/reduce-time', { student_id: studentId, exam_id: examId, minutes: directMin })
        setNotice(`تم تقليص ${directMin} دقائق من وقت الطالب`)
      }
      setDirectActionModal(null)
      setDirectMsg('')
      await loadData(true)
    } catch (e) {
      setError(e.message || 'فشل تنفيذ الإجراء')
    } finally {
      setBusy(false)
    }
  }

  const getStatusBadge = (status) => {
    switch (status) {
      case 'acknowledged':
        return <span className="inline-flex items-center gap-1 rounded-lg bg-emerald-50 px-2.5 py-1 text-xs font-bold text-emerald-700 border border-emerald-200">✓ أكّد الطالب</span>
      case 'delivered':
        return <span className="inline-flex items-center gap-1 rounded-lg bg-blue-50 px-2.5 py-1 text-xs font-bold text-blue-700 border border-blue-200">⚡ وصل للمتصفح</span>
      default:
        return <span className="inline-flex items-center gap-1 rounded-lg bg-amber-50 px-2.5 py-1 text-xs font-bold text-amber-700 border border-amber-200">⏳ قيد الإرسال</span>
    }
  }

  const getActionTypeLabel = (type, action) => {
    switch (type) {
      case 'send_message':
        return <span className="font-bold text-brand-700">💬 رسالة: "{action.message}"</span>
      case 'lock_exam':
        return <span className="font-bold text-rose-700">🔒 قفل الامتحان</span>
      case 'unlock_exam':
        return <span className="font-bold text-emerald-700">🔓 إلغاء القفل</span>
      case 'reduce_time':
        return <span className="font-bold text-violet-700">⏱ تقليص وقت (-{action.minutes_to_reduce || 5} د)</span>
      default:
        return <span className="font-bold text-slate-700">{type}</span>
    }
  }

  return (
    <div className="space-y-6">
      {/* Page Header */}
      <header className="flex flex-wrap items-center justify-between gap-4 animate-fade-up">
        <div>
          <div className="flex items-center gap-2">
            <span className="flex h-8 w-8 items-center justify-center rounded-xl bg-violet-600 text-white shadow-md shadow-violet-600/20 font-extrabold text-sm">⚡</span>
            <h1 className="text-2xl font-black text-slate-800">مركز إجراءات المدرس الفورية (Action Control Hub)</h1>
          </div>
          <p className="mt-1 text-xs font-bold text-slate-500">تحكم مباشر، بث تحذيرات جماعية، ومتابعة دورة حياة وصول الأوامر لمتصفح الطالب</p>
        </div>

        <div className="flex flex-wrap items-center gap-2.5">
          <select
            value={selectedExamId}
            onChange={(e) => setSelectedExamId(e.target.value)}
            className="rounded-xl border border-slate-200 bg-white px-3.5 py-2 text-xs font-extrabold text-slate-700 outline-none shadow-sm focus:border-brand-500"
          >
            <option value="">جميع الامتحانات</option>
            {exams.map((ex) => (
              <option key={ex.id} value={ex.id}>
                {ex.name}
              </option>
            ))}
          </select>

          <button
            onClick={() => setShowBroadcast(true)}
            disabled={busy || !selectedExamId}
            title={!selectedExamId ? 'يرجى تحديد امتحان للبث' : 'بث عام لجميع طلاب الامتحان'}
            className="flex items-center gap-2 rounded-xl bg-brand-600 px-4 py-2 text-xs font-black text-white shadow-lg shadow-brand-600/20 transition-all hover:bg-brand-700 disabled:opacity-50"
          >
            <span>📢</span>
            <span>بث إشعار لجميع الطلاب</span>
          </button>
        </div>
      </header>

      {notice && (
        <div className="rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-xs font-extrabold text-emerald-800 flex items-center justify-between">
          <span>{notice}</span>
          <button onClick={() => setNotice('')} className="text-emerald-600 hover:text-emerald-900 font-black">×</button>
        </div>
      )}

      {error && (
        <div className="rounded-xl border border-rose-200 bg-rose-50 px-4 py-3 text-xs font-extrabold text-rose-800 flex items-center justify-between">
          <span>{error}</span>
          <button onClick={() => setError('')} className="text-rose-600 hover:text-rose-900 font-black">×</button>
        </div>
      )}

      {/* KPI Cards */}
      <div className="grid grid-cols-2 gap-3 sm:grid-cols-4">
        <Card className="p-4 border-slate-100">
          <p className="text-[11px] font-extrabold text-slate-400">إجمالي الأوامر الصادرة</p>
          <p className="mt-1 text-2xl font-black text-slate-800">{stats.total}</p>
        </Card>
        <Card className="p-4 border-blue-100 bg-blue-50/30">
          <p className="text-[11px] font-extrabold text-blue-600">وصلت لمتصفح الطالب</p>
          <p className="mt-1 text-2xl font-black text-blue-700">{stats.delivered}</p>
        </Card>
        <Card className="p-4 border-emerald-100 bg-emerald-50/30">
          <p className="text-[11px] font-extrabold text-emerald-600">أكد الطالب قراءتها</p>
          <p className="mt-1 text-2xl font-black text-emerald-700">{stats.acknowledged}</p>
        </Card>
        <Card className="p-4 border-rose-100 bg-rose-50/30">
          <p className="text-[11px] font-extrabold text-rose-600">امتحانات مقفلة نشطة</p>
          <p className="mt-1 text-2xl font-black text-rose-700">{stats.active_locks}</p>
        </Card>
      </div>

      {/* Live Actions Stream Table */}
      <Reveal>
        <Card className="overflow-hidden p-0">
          <div className="flex items-center justify-between border-b border-slate-100 bg-slate-50/60 px-5 py-3.5">
            <div className="flex items-center gap-2">
              <span className="relative flex h-2.5 w-2.5">
                <span className="absolute inline-flex h-full w-full animate-ping rounded-full bg-emerald-400 opacity-75" />
                <span className="relative inline-flex h-2.5 w-2.5 rounded-full bg-emerald-500" />
              </span>
              <h2 className="text-xs font-black text-slate-800">سجل تدفق الأوامر الحي (Live Action Lifecycle)</h2>
            </div>
            <span className="text-[11px] font-bold text-slate-400">تحديث تلقائي كل 3 ثوانٍ</span>
          </div>

          {loading ? (
            <div className="py-16 text-center">
              <Spinner />
              <p className="mt-2 text-xs font-bold text-slate-400">جاري تحميل سجل الأوامر...</p>
            </div>
          ) : actions.length === 0 ? (
            <div className="py-16 text-center">
              <span className="text-4xl">📭</span>
              <p className="mt-2 text-sm font-extrabold text-slate-700">لا توجد أوامر صادرة حتى الآن</p>
              <p className="text-xs font-semibold text-slate-400">يمكنك إصدار تحذيرات أو قفل أو تقليص وقت لأي طالب من بطاقته في لوحة المراقبة</p>
            </div>
          ) : (
            <div className="overflow-x-auto">
              <table className="w-full text-right text-xs">
                <thead>
                  <tr className="border-b border-slate-100 bg-slate-50/40 text-slate-500">
                    <th className="px-5 py-3 font-extrabold">المعرّف</th>
                    <th className="px-5 py-3 font-extrabold">الطالب</th>
                    <th className="px-5 py-3 font-extrabold">الامتحان</th>
                    <th className="px-5 py-3 font-extrabold">نوع الإجراء والتفاصيل</th>
                    <th className="px-5 py-3 font-extrabold">الحالة</th>
                    <th className="px-5 py-3 font-extrabold">وقت الإصدار</th>
                    <th className="px-5 py-3 font-extrabold">إجراء سريع</th>
                  </tr>
                </thead>
                <tbody className="divide-y divide-slate-100">
                  {actions.map((act) => (
                    <tr key={act.id} className="transition-colors hover:bg-slate-50/60">
                      <td className="px-5 py-3.5 font-bold text-slate-400">#{act.id}</td>
                      <td className="px-5 py-3.5">
                        <p className="font-black text-slate-800">{act.student_name}</p>
                        <p className="text-[10px] font-semibold text-slate-400">{act.student_username}</p>
                      </td>
                      <td className="px-5 py-3.5 font-bold text-slate-600">{act.exam_name}</td>
                      <td className="px-5 py-3.5">{getActionTypeLabel(act.action_type, act)}</td>
                      <td className="px-5 py-3.5">{getStatusBadge(act.status)}</td>
                      <td className="px-5 py-3.5 font-semibold text-slate-500">
                        {act.created_at ? new Date(act.created_at).toLocaleTimeString('ar-EG') : '—'}
                      </td>
                      <td className="px-5 py-3.5">
                        {act.action_type === 'lock_exam' && act.status !== 'revoked' ? (
                          <button
                            onClick={() => {
                              setDirectActionModal({
                                studentId: act.student_id,
                                studentName: act.student_name,
                                examId: act.exam_id,
                                type: 'unlock',
                              })
                            }}
                            className="rounded-lg bg-emerald-50 px-2.5 py-1 text-[11px] font-bold text-emerald-700 hover:bg-emerald-100 transition-colors"
                          >
                            🔓 إلغاء القفل
                          </button>
                        ) : (
                          <button
                            onClick={() => {
                              setDirectActionModal({
                                studentId: act.student_id,
                                studentName: act.student_name,
                                examId: act.exam_id,
                                type: 'message',
                              })
                            }}
                            className="rounded-lg bg-slate-100 px-2.5 py-1 text-[11px] font-bold text-slate-600 hover:bg-slate-200 transition-colors"
                          >
                            💬 إرسال رسالة
                          </button>
                        )}
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          )}
        </Card>
      </Reveal>

      {/* Broadcast Announcement Modal */}
      {showBroadcast && (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/60 p-4 backdrop-blur-sm animate-fade-in">
          <div className="w-full max-w-lg rounded-2xl bg-white p-6 shadow-2xl space-y-4">
            <div className="flex items-center justify-between border-b border-slate-100 pb-3">
              <h3 className="text-base font-black text-slate-800">📢 بث إشعار عام لجميع طلاب الامتحان</h3>
              <button onClick={() => setShowBroadcast(false)} className="text-slate-400 hover:text-slate-600 font-bold text-lg">×</button>
            </div>

            <div className="space-y-3">
              <div>
                <label className="text-xs font-extrabold text-slate-700">نوع الإجراء الجماعي:</label>
                <div className="mt-1.5 flex gap-2">
                  <button
                    type="button"
                    onClick={() => setBroadcastType('send_message')}
                    className={`flex-1 rounded-xl py-2 text-xs font-black transition-all ${broadcastType === 'send_message' ? 'bg-brand-600 text-white shadow-md' : 'bg-slate-100 text-slate-600'}`}
                  >
                    💬 رسالة تنبيهية
                  </button>
                  <button
                    type="button"
                    onClick={() => setBroadcastType('reduce_time')}
                    className={`flex-1 rounded-xl py-2 text-xs font-black transition-all ${broadcastType === 'reduce_time' ? 'bg-violet-600 text-white shadow-md' : 'bg-slate-100 text-slate-600'}`}
                  >
                    ⏱ تقليص وقت جماعي
                  </button>
                  <button
                    type="button"
                    onClick={() => setBroadcastType('lock_exam')}
                    className={`flex-1 rounded-xl py-2 text-xs font-black transition-all ${broadcastType === 'lock_exam' ? 'bg-rose-600 text-white shadow-md' : 'bg-slate-100 text-slate-600'}`}
                  >
                    🔒 إيقاف جماعي طارئ
                  </button>
                </div>
              </div>

              {broadcastType === 'send_message' && (
                <div>
                  <label className="text-xs font-extrabold text-slate-700">نص الرسالة التي ستظهر لجميع الطلاب:</label>
                  <textarea
                    rows={3}
                    value={broadcastMessage}
                    onChange={(e) => setBroadcastMessage(e.target.value)}
                    placeholder="مثال: تنبيه لجميع الطلاب: تبقى 10 دقائق على نهاية وقت الامتحان، نرجو مراجعة الإجابات وتسليمها..."
                    className="mt-1.5 w-full rounded-xl border border-slate-200 bg-slate-50 p-3 text-xs font-semibold text-slate-800 outline-none focus:border-brand-500"
                  />
                </div>
              )}

              {broadcastType === 'reduce_time' && (
                <div>
                  <label className="text-xs font-extrabold text-slate-700">عدد الدقائق المراد خصمها من الجميع:</label>
                  <div className="mt-1.5 flex gap-2">
                    {[1, 3, 5, 10, 15].map((m) => (
                      <button
                        key={m}
                        type="button"
                        onClick={() => setBroadcastMinutes(m)}
                        className={`flex-1 rounded-xl py-2 text-xs font-black ${broadcastMinutes === m ? 'bg-violet-600 text-white' : 'bg-slate-100 text-slate-600'}`}
                      >
                        {m} د
                      </button>
                    ))}
                  </div>
                </div>
              )}

              {broadcastType === 'lock_exam' && (
                <div className="rounded-xl border border-rose-200 bg-rose-50 p-3 text-xs font-bold text-rose-700">
                  ⚠️ تحذير: هذا الإجراء سيقفل شاشة الامتحان فوراً لجميع الطلاب المتصلين في هذا الامتحان!
                </div>
              )}
            </div>

            <div className="flex gap-2.5 pt-2">
              <button
                type="button"
                onClick={() => setShowBroadcast(false)}
                className="flex-1 rounded-xl border border-slate-200 py-2.5 text-xs font-extrabold text-slate-600"
              >
                إلغاء
              </button>
              <button
                type="button"
                onClick={handleBroadcast}
                disabled={busy}
                className="flex-1 rounded-xl bg-brand-600 py-2.5 text-xs font-black text-white hover:bg-brand-700 shadow-md shadow-brand-600/20 disabled:opacity-50"
              >
                {busy ? 'جاري الإرسال...' : 'تأكيد وإرسال البث'}
              </button>
            </div>
          </div>
        </div>
      )}

      {/* Direct Student Action Modal */}
      {directActionModal && (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/60 p-4 backdrop-blur-sm animate-fade-in">
          <div className="w-full max-w-md rounded-2xl bg-white p-6 shadow-2xl space-y-4">
            <h3 className="text-sm font-black text-slate-800">
              إجراء مباشر للطالب: <span className="text-brand-600">{directActionModal.studentName}</span>
            </h3>

            {directActionModal.type === 'message' && (
              <div>
                <label className="text-xs font-bold text-slate-600">نص الرسالة التنبيهية:</label>
                <textarea
                  rows={3}
                  value={directMsg}
                  onChange={(e) => setDirectMsg(e.target.value)}
                  placeholder="مثال: يرجى التركيز في صفحة الامتحان وعدم تبديل النوافذ..."
                  className="mt-1.5 w-full rounded-xl border border-slate-200 bg-slate-50 p-3 text-xs font-semibold text-slate-800 outline-none focus:border-brand-500"
                />
              </div>
            )}

            {directActionModal.type === 'reduce-time' && (
              <div>
                <label className="text-xs font-bold text-slate-600">دقائق التقليص:</label>
                <div className="mt-1.5 flex gap-2">
                  {[1, 3, 5, 10, 15].map((m) => (
                    <button
                      key={m}
                      type="button"
                      onClick={() => setDirectMin(m)}
                      className={`flex-1 rounded-xl py-2 text-xs font-black ${directMin === m ? 'bg-violet-600 text-white' : 'bg-slate-100 text-slate-600'}`}
                    >
                      {m} د
                    </button>
                  ))}
                </div>
              </div>
            )}

            {directActionModal.type === 'lock' && (
              <div className="rounded-xl border border-rose-200 bg-rose-50 p-3 text-xs font-bold text-rose-700">
                ⚠️ سيتم قفل شاشة الامتحان للطالب ومنعه من التفاعل حتى يتم إلغاء القفل.
              </div>
            )}

            {directActionModal.type === 'unlock' && (
              <div className="rounded-xl border border-emerald-200 bg-emerald-50 p-3 text-xs font-bold text-emerald-700">
                ✓ سيتم إلغاء قفل الامتحان والسماح للطالب بمتابعة الحل فوراً.
              </div>
            )}

            <div className="flex gap-2.5 pt-2">
              <button
                type="button"
                onClick={() => setDirectActionModal(null)}
                className="flex-1 rounded-xl border border-slate-200 py-2 text-xs font-extrabold text-slate-600"
              >
                إلغاء
              </button>
              <button
                type="button"
                onClick={handleExecuteDirect}
                disabled={busy || (directActionModal.type === 'message' && !directMsg.trim())}
                className="flex-1 rounded-xl bg-brand-600 py-2 text-xs font-black text-white hover:bg-brand-700 shadow-md shadow-brand-600/20 disabled:opacity-50"
              >
                تأكيد التنفيذ
              </button>
            </div>
          </div>
        </div>
      )}
    </div>
  )
}
