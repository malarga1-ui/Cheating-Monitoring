<?php
/**
 * Setup wizard endpoints: per-account onboarding progress (install the
 * Moodle plugin, update Moodle, connect the platform, enable monitoring).
 */
final class SetupController
{
    /** The ordered onboarding steps (keys map to the frontend wizard). */
    private const STEPS = ['download', 'update', 'connect', 'enable'];

    public static function status(): void
    {
        Auth::requireLogin();
        $id = Auth::accountId();
        $account = Accounts::findById($id);
        if ($account === null) {
            Response::error('الحساب غير موجود', 404);
        }

        $progress = Accounts::setupProgress($id);
        Response::ok([
            'progress' => $progress,
            'steps'    => self::STEPS,
            'complete' => self::isComplete($progress),
            'api_secret' => Auth::isSupervisor() ? '' : $account['api_secret'],
            'site_domain' => $account['site_domain'],
        ]);
    }

    public static function mark(string $step): void
    {
        Auth::requireLogin();
        Auth::guardStateChangingRequest();
        $step = self::normalizeStep($step);
        if ($step === null) {
            Response::error('خطوة غير معروفة', 422);
        }
        $progress = Accounts::setSetupStep(Auth::accountId(), $step, true);
        Response::ok([
            'progress' => $progress,
            'complete' => self::isComplete($progress),
        ]);
    }

    public static function unmark(string $step): void
    {
        Auth::requireLogin();
        Auth::guardStateChangingRequest();
        $step = self::normalizeStep($step);
        if ($step === null) {
            Response::error('خطوة غير معروفة', 422);
        }
        $progress = Accounts::setSetupStep(Auth::accountId(), $step, false);
        Response::ok([
            'progress' => $progress,
            'complete' => self::isComplete($progress),
        ]);
    }

    private static function normalizeStep(string $step): ?string
    {
        $step = strtolower(trim($step));
        return in_array($step, self::STEPS, true) ? $step : null;
    }

    private static function isComplete(array $progress): bool
    {
        foreach (self::STEPS as $s) {
            if (empty($progress[$s])) {
                return false;
            }
        }
        return true;
    }
}
