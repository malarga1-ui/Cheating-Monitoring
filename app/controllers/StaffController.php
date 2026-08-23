<?php
/**
 * Staff management endpoints (per-account admin / supervisor users).
 * Guarded by Auth::requireAccountAdmin() — the university account holder or
 * an admin staff member. Course grants for supervisors live in course_access.
 */
final class StaffController
{
    /** GET /api/staff — all staff of the current account. */
    public static function list(): void
    {
        Auth::requireAccountAdmin();
        $rows = Staff::list(Auth::accountId());
        Response::ok(array_map(function ($r) {
            $r['id'] = (int)$r['id'];
            $r['is_active'] = (int)$r['is_active'];
            $r['courses_count'] = (int)$r['courses_count'];
            return $r;
        }, $rows));
    }

    /** POST /api/staff — create a staff member. */
    public static function create(): void
    {
        Auth::requireAccountAdmin();
        Auth::guardStateChangingRequest();

        $body = em_body_json() ?? [];
        try {
            $staff = Staff::create(Auth::accountId(), $body);
        } catch (RuntimeException $e) {
            Response::error($e->getMessage(), 422);
        }
        if ($staff === []) {
            Response::error('تعذر إنشاء الموظف', 500);
        }

        Audit::log('staff.create', 'staff', (int)$staff['id'], [
            'username' => (string)($staff['username'] ?? ''),
            'fullname' => (string)($staff['fullname'] ?? ''),
            'email'    => (string)($staff['email'] ?? ''),
            'role'     => (string)($staff['role'] ?? ''),
        ]);

        Response::ok(['staff' => self::shape($staff)]);
    }

    /** POST /api/staff/{id} — update name / email / role / password. */
    public static function update(int $id): void
    {
        Auth::requireAccountAdmin();
        Auth::guardStateChangingRequest();

        $body = em_body_json() ?? [];
        try {
            $staff = Staff::update(Auth::accountId(), $id, $body);
        } catch (RuntimeException $e) {
            Response::error($e->getMessage(), $e->getCode() ?: 422);
        }

        Audit::log('staff.update', 'staff', $id, [
            'username'     => (string)($staff['username'] ?? ''),
            'role'         => (string)($staff['role'] ?? ''),
            'password_set' => !empty($body['password']),
        ]);

        Response::ok(['staff' => self::shape($staff)]);
    }

    /** POST /api/staff/{id}/toggle — suspend / re-activate a staff member. */
    public static function toggle(int $id): void
    {
        Auth::requireAccountAdmin();
        Auth::guardStateChangingRequest();

        if (Auth::isStaff() && Auth::staffId() === $id) {
            Response::error('لا يمكنك إيقاف حسابك الخاص', 422);
        }

        $body = em_body_json() ?? [];
        $active = (bool)($body['active'] ?? false);

        $staff = Staff::findById(Auth::accountId(), $id);
        if ($staff === null) {
            Response::error('الموظف غير موجود', 404);
        }

        Staff::setActive(Auth::accountId(), $id, $active);
        Audit::log('staff.toggle', 'staff', $id, [
            'username' => (string)($staff['username'] ?? ''),
            'active'   => $active,
        ]);
        Response::ok(['staff' => self::shape(Staff::findById(Auth::accountId(), $id) ?? [])]);
    }

    /** POST /api/staff/{id}/delete — permanently remove a staff member. */
    public static function delete(int $id): void
    {
        Auth::requireAccountAdmin();
        Auth::guardStateChangingRequest();

        if (Auth::isStaff() && Auth::staffId() === $id) {
            Response::error('لا يمكنك حذف حسابك الخاص', 422);
        }

        $staff = Staff::findById(Auth::accountId(), $id);
        if ($staff === null) {
            Response::error('الموظف غير موجود', 404);
        }

        Staff::remove(Auth::accountId(), $id);
        Audit::log('staff.delete', 'staff', $id, [
            'username' => (string)($staff['username'] ?? ''),
            'fullname' => (string)($staff['fullname'] ?? ''),
        ]);
        Response::ok(['ok' => true]);
    }

    /** GET /api/staff/{id}/courses — course ids granted to a supervisor. */
    public static function courses(int $id): void
    {
        Auth::requireAccountAdmin();

        $staff = Staff::findById(Auth::accountId(), $id);
        if ($staff === null) {
            Response::error('الموظف غير موجود', 404);
        }

        Response::ok([
            'staff_id' => (int)$id,
            'role' => $staff['role'],
            'granted_course_ids' => Staff::courseIds(Auth::accountId(), $id),
        ]);
    }

    /** POST /api/staff/{id}/courses — replace the supervisor's granted courses. */
    public static function setCourses(int $id): void
    {
        Auth::requireAccountAdmin();
        Auth::guardStateChangingRequest();

        $staff = Staff::findById(Auth::accountId(), $id);
        if ($staff === null) {
            Response::error('الموظف غير موجود', 404);
        }
        if ($staff['role'] !== 'supervisor') {
            Response::error('الصلاحيات مخصصة لدور المشرف فقط', 422);
        }

        $body = em_body_json() ?? [];
        $courseIds = array_values(array_unique(array_filter(array_map(
            'intval',
            (array)($body['course_ids'] ?? [])
        ))));

        Staff::setCourses(Auth::accountId(), $id, $courseIds);
        Audit::log('staff.courses.set', 'staff', $id, [
            'username' => (string)($staff['username'] ?? ''),
            'count'    => count($courseIds),
        ]);
        Response::ok(['granted_course_ids' => $courseIds]);
    }

    private static function shape(array $s): array
    {
        $s['id'] = (int)($s['id'] ?? 0);
        $s['is_active'] = (int)($s['is_active'] ?? 0);
        return $s;
    }
}
