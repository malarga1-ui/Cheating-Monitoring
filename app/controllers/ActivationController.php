<?php
/**
 * Activation endpoints (public — used by the admin entry gate).
 */
final class ActivationController
{
    /** GET /api/activation/status */
    public static function status(): void
    {
        Response::ok(Activation::status());
    }

    /** POST /api/activation/trial — start the 30-day trial. */
    public static function startTrial(): void
    {
        if (!Activation::startTrial()) {
            Response::error('المنصة مفعّلة بالفعل بمفتاح ترخيص', 409);
        }
        Response::ok(Activation::status());
    }

    /** POST /api/activation/activate — unlock with a license key. */
    public static function activate(): void
    {
        $in = em_body_json() ?? [];
        $key = trim((string)($in['key'] ?? ''));
        if ($key === '') {
            Response::error('يرجى إدخال مفتاح الترخيص', 422);
        }
        if (!Activation::verifyKey($key)) {
            Response::error('مفتاح الترخيص غير صالح لهذا الموقع', 422);
        }
        Activation::activate($key);
        Response::ok(Activation::status());
    }
}
