<?php
/**
 * Front controller.
 *
 * - POST /telemetry           -> ingest endpoint used by the Moodle plugin
 * - GET  /telemetry/health    -> health probe
 * - /api/*                    -> JSON API (requires login)
 * - anything else             -> SPA (React build in public/)
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/app/bootstrap.php';

$uri     = $_SERVER['REQUEST_URI'] ?? '/';
$path    = rawurldecode((string)parse_url($uri, PHP_URL_PATH));
$path    = rtrim($path, '/') ?: '/';
$method  = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');

// Let the PHP built-in server serve real static files itself.
if (is_file(__DIR__ . $path)) {
    return false;
}

$router = new Router();

// ---- Telemetry (public, no auth) -----------------------------------------
$router->post('/telemetry', [TelemetryController::class, 'ingest']);
$router->any('/telemetry', [TelemetryController::class, 'options']);
$router->get('/telemetry/health', [TelemetryController::class, 'health']);

// ---- Moodle lifecycle sync (public, shared-secret auth) --------------------
$router->post('/api/sync', [SyncController::class, 'ingest']);
$router->post('/api/sync/bulk', [SyncController::class, 'bulkSync']);
$router->post('/api/sync/trigger', [SyncController::class, 'triggerSync']);
$router->post('/register-teacher', [SyncController::class, 'registerTeacher']);
$router->post('/api/plugin/exam-stats', [PluginStatsController::class, 'examStats']);

// ---- Auth ----------------------------------------------------------------
$router->post('/api/auth/login', [AuthController::class, 'login']);
$router->post('/api/auth/logout', [AuthController::class, 'logout']);
$router->get('/api/auth/me', [AuthController::class, 'me']);
$router->post('/api/auth/password', [AuthController::class, 'changePassword']);

// ---- Teacher auth (portal) -------------------------------------------------
$router->get('/api/public/sites', [TeacherAuthController::class, 'sites']);
$router->post('/api/auth/teacher-login', [TeacherAuthController::class, 'login']);
$router->post('/api/auth/teacher-token-login', [TeacherAuthController::class, 'tokenLogin']);
$router->post('/api/auth/teacher-change-password', [TeacherAuthController::class, 'changePassword']);

// ---- Staff auth (university admin / supervisor) ------------------------------
$router->post('/api/auth/staff-login', [StaffAuthController::class, 'login']);

// ---- Staff management (account holder or admin staff) -------------------------
$router->get('/api/staff', [StaffController::class, 'list']);
$router->post('/api/staff', [StaffController::class, 'create']);
$router->post('/api/staff/{id}', [StaffController::class, 'update']);
$router->post('/api/staff/{id}/toggle', [StaffController::class, 'toggle']);
$router->post('/api/staff/{id}/delete', [StaffController::class, 'delete']);
$router->get('/api/staff/{id}/courses', [StaffController::class, 'courses']);
$router->post('/api/staff/{id}/courses', [StaffController::class, 'setCourses']);

// ---- Teacher portal (strictly teacher-scoped) -------------------------------
$router->get('/api/teacher/summary', [TeacherPortalController::class, 'summary']);
$router->get('/api/teacher/analytics', [TeacherPortalController::class, 'analytics']);
$router->post('/api/teacher/sync-from-events', [TeacherPortalController::class, 'syncFromEvents']);
$router->get('/api/teacher/courses', [TeacherPortalController::class, 'courses']);
$router->get('/api/teacher/courses/{id}', [TeacherPortalController::class, 'courseDetail']);
$router->get('/api/teacher/exams', [TeacherPortalController::class, 'exams']);
// v9: All-exams aggregated analytics (MUST be before {id} routes)
$router->get('/api/teacher/exams/network', [TeacherPortalController::class, 'allNetworkGroups']);
$router->get('/api/teacher/exams/similarity', [TeacherPortalController::class, 'allSimilarityPairs']);
$router->get('/api/teacher/exams/devices', [TeacherPortalController::class, 'allMultiDevice']);
// Students list + detail
$router->get('/api/teacher/students', [TeacherPortalController::class, 'students']);
$router->get('/api/teacher/students/{id}', [TeacherPortalController::class, 'studentDetail']);
// Single-exam analytics
$router->get('/api/teacher/exams/{id}', [TeacherPortalController::class, 'examDetail']);
$router->get('/api/teacher/exams/{id}/students', [TeacherPortalController::class, 'examStudents']);
$router->get('/api/teacher/exams/{id}/network', [TeacherPortalController::class, 'examNetworkGroups']);
$router->get('/api/teacher/exams/{id}/similarity', [TeacherPortalController::class, 'examSimilarityPairs']);
$router->get('/api/teacher/exams/{id}/analytics', [TeacherPortalController::class, 'examDetailV9']);
$router->get('/api/teacher/exams/{id}/devices', [TeacherPortalController::class, 'examMultiDevice']);
$router->get('/api/teacher/exams/{eid}/students/{sid}/ips', [TeacherPortalController::class, 'examStudentIPs']);
$router->get('/api/teacher/exams/{eid}/students/{sid}/answers', [TeacherPortalController::class, 'examStudentAnswers']);

// ---- Teacher real-time actions (message, lock, reduce time) ------------------
$router->post('/api/teacher/actions/message', [TeacherActionController::class, 'sendMessage']);
$router->post('/api/teacher/actions/lock', [TeacherActionController::class, 'lockExam']);
$router->post('/api/teacher/actions/reduce-time', [TeacherActionController::class, 'reduceTime']);
$router->post('/api/teacher/actions/check', [TeacherActionController::class, 'check']);
$router->post('/api/teacher/actions/{id}/ack', [TeacherActionController::class, 'acknowledge']);
$router->get('/api/teacher/actions/{examId}/log', [TeacherActionController::class, 'log']);

// ---- SaaS accounts ---------------------------------------------------------
$router->post('/api/accounts/register', [AccountController::class, 'register']);
$router->get('/api/accounts/me', [AccountController::class, 'me']);
$router->post('/api/accounts/rotate-secret', [AccountController::class, 'rotateSecret']);
$router->post('/api/accounts/set-site-domain', [AccountController::class, 'setSiteDomain']);
$router->post('/api/accounts/activate', [AccountController::class, 'activate']);
$router->get('/api/accounts', [AccountController::class, 'listAll']);

// ---- Setup wizard (onboarding progress) -----------------------------------
$router->get('/api/setup', [SetupController::class, 'status']);
$router->post('/api/setup/{step}', [SetupController::class, 'mark']);
$router->post('/api/setup/{step}/undo', [SetupController::class, 'unmark']);

// ---- Dashboard -----------------------------------------------------------
$router->get('/api/dashboard/summary', [DashboardController::class, 'summary']);
$router->get('/api/dashboard/edu-overview', [DashboardController::class, 'eduOverview']);
$router->get('/api/dashboard/events-over-time', [DashboardController::class, 'eventsOverTime']);
$router->get('/api/dashboard/event-types', [DashboardController::class, 'eventTypes']);
$router->get('/api/dashboard/top-risky', [DashboardController::class, 'topRisky']);

// ---- Exams ---------------------------------------------------------------
$router->get('/api/exams', [ExamController::class, 'list']);
$router->get('/api/exams/{id}', [ExamController::class, 'detail']);
$router->post('/api/exams/{id}', [ExamController::class, 'update']);
$router->get('/api/exams/{id}/students', [ExamController::class, 'students']);

// ---- Students ------------------------------------------------------------
$router->get('/api/students/{id}', [StudentController::class, 'profile']);
$router->get('/api/students/{id}/sessions', [StudentController::class, 'sessions']);
$router->get('/api/students/{id}/events', [StudentController::class, 'events']);
$router->get('/api/students/{id}/answers/{examId}', [StudentController::class, 'examAnswers']);

// ---- Teachers -------------------------------------------------------------
$router->get('/api/teachers', [TeacherController::class, 'list']);
$router->get('/api/teachers/{id}', [TeacherController::class, 'detail']);

// ---- Reports -------------------------------------------------------------
$router->get('/api/reports/exam/{id}', [ReportController::class, 'examReport']);
$router->get('/api/reports/exam/{id}/csv', [ReportController::class, 'examCsv']);

// ---- Audit trail ---------------------------------------------------------
$router->get('/api/audit', [AuditController::class, 'list']);

// ---- SOAR Response Layer (closed-loop) ------------------------------------
$router->get('/api/responses/pending/{examId}', [ResponseController::class, 'pending']);
$router->get('/api/responses/stats', [ResponseController::class, 'stats']);
$router->get('/api/responses/session/{sessionId}', [ResponseController::class, 'forSession']);
$router->post('/api/responses/{id}/acknowledge', [ResponseController::class, 'acknowledge']);
$router->post('/api/responses/exam/{examId}/ack-all', [ResponseController::class, 'acknowledgeExam']);

// ---- Performance Metrics (Chapter 3.4) ------------------------------------
$router->get('/api/performance/system', [PerformanceController::class, 'systemWide']);
$router->get('/api/performance/all', [PerformanceController::class, 'allExams']);
$router->get('/api/performance/exam/{examId}', [PerformanceController::class, 'examMetrics']);
$router->post('/api/performance/verdict', [PerformanceController::class, 'setVerdict']);
$router->post('/api/performance/recompute/{examId}', [PerformanceController::class, 'recompute']);

// ---- Settings ------------------------------------------------------------
$router->get('/api/settings/status', [SettingsController::class, 'status']);
$router->get('/api/settings/risk-model', [SettingsController::class, 'riskModel']);
$router->get('/api/settings/risk-indicators', [SettingsController::class, 'riskIndicators']);
$router->post('/api/settings/risk-indicators', [SettingsController::class, 'createRiskIndicator']);
$router->post('/api/settings/risk-indicators/recompute', [SettingsController::class, 'recomputeRisk']);
$router->post('/api/settings/risk-indicators/{id}', [SettingsController::class, 'updateRiskIndicator']);
$router->post('/api/settings/risk-indicators/{id}/delete', [SettingsController::class, 'deleteRiskIndicator']);

// ---- System health --------------------------------------------------------
$router->get('/api/health/stats', [HealthController::class, 'stats']);

// ---- Raw telemetry viewer --------------------------------------------------
$router->get('/api/raw/events', [RawController::class, 'events']);
$router->get('/api/raw/types', [RawController::class, 'types']);
$router->get('/api/raw/stats', [RawController::class, 'stats']);

// ---- Courses & supervisor access ------------------------------------------
$router->get('/api/courses', [CourseController::class, 'list']);
$router->get('/api/courses/{id}', [CourseController::class, 'detail']);
$router->get('/api/courses/{id}/students', [CourseController::class, 'students']);
$router->post('/api/courses/{id}/name', [CourseController::class, 'updateName']);
$router->get('/api/access', [CourseController::class, 'accessList']);
$router->get('/api/access/{userId}', [CourseController::class, 'accessDetail']);
$router->post('/api/access/{userId}', [CourseController::class, 'setAccess']);

// ---- AI Brain ------------------------------------------------------------
$router->get('/api/ai/status', [AIBrainController::class, 'status']);
$router->post('/api/ai/predict', [AIBrainController::class, 'predict']);
$router->post('/api/ai/batch', [AIBrainController::class, 'batch']);
$router->post('/api/ai/patterns', [AIBrainController::class, 'patterns']);
$router->post('/api/ai/report', [AIBrainController::class, 'report']);
$router->post('/api/ai/train', [AIBrainController::class, 'train']);

// ---- AI Content Detection (RapidAPI failover chain) ----------------------
$router->post('/api/ai/detect-content', [AIDetectionController::class, 'detect']);
$router->post('/api/ai/detect-batch', [AIDetectionController::class, 'detectBatch']);
$router->get('/api/ai/detect-status', [AIDetectionController::class, 'status']);

// ---- SPA fallback --------------------------------------------------------
if (str_starts_with($path, '/api/') || str_starts_with($path, '/telemetry')) {
    $router->dispatch($method, $path);
}

$index = __DIR__ . '/index.html';
if (!is_file($index)) {
    http_response_code(503);
    echo 'Exam Monitor Platform is not built yet. Run the frontend build first.';
    exit;
}
header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store');
readfile($index);
