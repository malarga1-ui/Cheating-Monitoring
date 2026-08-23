import { useEffect, useState } from 'react'
import { Navigate, Route, Routes } from 'react-router-dom'
import { useAuth } from './auth'
import { api } from './api'
import Layout from './components/Layout'
import ErrorBoundary from './components/ErrorBoundary'
import Login from './pages/Login'
import Register from './pages/Register'
import ExpiredPage from './pages/ExpiredPage'
import AccountPage from './pages/AccountPage'
import OwnerAccounts from './pages/OwnerAccounts'
import Dashboard from './pages/Dashboard'
import Exams from './pages/Exams'
import ExamDetail from './pages/ExamDetail'
import StudentProfile from './pages/StudentProfile'
import Courses from './pages/Courses'
import CourseDetail from './pages/CourseDetail'
import RawEvents from './pages/RawEvents'
import RiskFormula from './pages/RiskFormula'
import PublicSite from './pages/PublicSite'
import PrivacyPolicy from './pages/PrivacyPolicy'
import SetupWizard from './pages/SetupWizard'
import TeacherLogin from './pages/TeacherLogin'
import TeacherPortal from './pages/TeacherPortal'
import StaffLogin from './pages/StaffLogin'
import Staff from './pages/Staff'
import AuditLog from './pages/AuditLog'
import NetworkAnalysis from './pages/NetworkAnalysis'
import SimilarityDetection from './pages/SimilarityDetection'
import MultiDevice from './pages/MultiDevice'
import { TeachersList, TeacherDetail } from './pages/Teachers'

function FullScreenLoader() {
 return (
 <div className="flex min-h-screen items-center justify-center bg-surface">
 <div className="relative">
 <span className="h-10 w-10 animate-spin rounded-full border-4 border-brand-200/30 border-t-brand-600"/>
 <div className="absolute inset-0 h-10 w-10 animate-ping rounded-full border-2 border-brand-400/20"style={{ animationDuration: '2s' }} />
 </div>
 </div>
 )
}

function OwnerOnly({ children }) {
 const { user } = useAuth()
 if (user?.role !== 'owner') return <Navigate to="/admin"replace />
 return children
}

function StaffOnly({ children }) {
 const { user } = useAuth()
 const canManage = (user?.authType === 'account' && user?.role !== 'owner') || user?.staffRole === 'admin'
 if (!canManage) return <Navigate to="/admin"replace />
 return children
}

function AdminArea() {
 const { user, status, loading } = useAuth()
 const [setup, setSetup] = useState(null)

 const isAccountHolder = user?.authType === 'account' && user?.role !== 'owner'

 useEffect(() => {
 if (isAccountHolder && setup === null) {
 api
 .get('/api/setup')
 .then((d) => setSetup({ complete: !!d?.complete }))
 .catch(() => setSetup({ complete: true }))
 }
 }, [isAccountHolder, setup])

 if (loading) return <FullScreenLoader />
 if (!user) return <Login />
 if (user.authType === 'teacher') return <Navigate to="/teacher/portal"replace />
 if (user.role !== 'owner' && (status?.status === 'expired' || status?.status === 'suspended')) {
 return <ExpiredPage />
 }

 if (isAccountHolder && setup === null) return <FullScreenLoader />
 if (isAccountHolder && !setup.complete) {
 return <SetupWizard onFinish={() => setSetup({ complete: true })} />
 }

 return (
 <Layout>
 <Routes>
 <Route index element={<Dashboard />} />
 <Route path="exams"element={<Exams />} />
 <Route path="exams/:id"element={<ExamDetail />} />
 <Route path="students/:id"element={<StudentProfile />} />
 <Route path="courses"element={<Courses />} />
 <Route path="courses/:id"element={<CourseDetail />} />
 <Route path="teachers"element={<TeachersList />} />
 <Route path="teachers/:id"element={<TeacherDetail />} />
 <Route path="network"element={<NetworkAnalysis />} />
 <Route path="similarity"element={<SimilarityDetection />} />
 <Route path="devices"element={<MultiDevice />} />
 <Route
 path="staff"
 element={
 <StaffOnly>
 <Staff />
 </StaffOnly>
 }
 />
 <Route
 path="audit"
 element={
 <StaffOnly>
 <AuditLog />
 </StaffOnly>
 }
 />
 <Route path="account"element={<AccountPage />} />
 <Route
 path="raw"
 element={
 <OwnerOnly>
 <RawEvents />
 </OwnerOnly>
 }
 />
 <Route
 path="formula"
 element={
 <OwnerOnly>
 <RiskFormula />
 </OwnerOnly>
 }
 />
 <Route
 path="access"
 element={
 <OwnerOnly>
 <Courses manageOnly />
 </OwnerOnly>
 }
 />
 <Route
 path="accounts"
 element={
 <OwnerOnly>
 <OwnerAccounts />
 </OwnerOnly>
 }
 />
 <Route path="*"element={<Navigate to="/admin"replace />} />
 </Routes>
 </Layout>
 )
}

export default function App() {
 return (
 <ErrorBoundary>
 <Routes>
 <Route path="/"element={<PublicSite />} />
 <Route path="/privacy"element={<PrivacyPolicy />} />
 <Route path="/register"element={<Register />} />
 <Route path="/login"element={<Login />} />
 <Route path="/teacher-login"element={<TeacherLogin />} />
 <Route path="/teacher/portal/*"element={<TeacherPortal />} />
 <Route path="/staff-login"element={<StaffLogin />} />
 <Route path="/admin/*"element={<AdminArea />} />
 <Route path="*"element={<Navigate to="/"replace />} />
 </Routes>
 </ErrorBoundary>
 )
}
