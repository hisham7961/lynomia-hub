<?php

namespace App\Http\Middleware;

use App\Models\EndpointDevice;
use App\Support\Api;
use App\Support\Es256;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

/**
 * **وسيطُ توقيع النقاط الطرفية** — Work OS · الطور J · WP-J.1 · §43.
 *
 * يفرض **عقدَ التوقيع** المكتوبَ مرةً واحدةً في docblock ‏`App\Support\Es256`
 * (وكيلُ الطور K يعكسه حرفياً) على كل مسارِ جهازٍ (heartbeat/أحداث/أوامر —
 * تُوصَل في WP-J.2) — **قبل أيّ منطقِ معالج**:
 *
 *  ١) الترويساتُ الأربع كاملةً وإلا 401 — `X-Endpoint-Id` (معرّفُ الجهاز الصادرُ
 *     لحظةَ التسجيل) و`X-Endpoint-Timestamp` و`X-Endpoint-Nonce` و`X-Endpoint-Signature`.
 *  ٢) الطابعُ داخل **±300 ثانية** (انضباطُ replay الواحد — نمطُ InboundHook) وإلا 401.
 *  ٣) الجهازُ معروفٌ وغيرُ محذوف وإلا 401 (لا تسريبَ وجود)؛ وغيرُ active
 *     (suspended/locked/retired) يُرَدّ 403 ولو صحَّ توقيعُه.
 *  ٤) التوقيعُ base64(DER) يصحّ على السلسلة القانونية `Es256::canonical` بالمفتاح
 *     **العامّ** المخزَّن وإلا 401 — مفتاحٌ آخر = تزويرٌ يُرصَد.
 *  ٥) الـnonce يُدَّعى ذرّياً في `endpoint_nonces` (‏`insertOrIgnore` على
 *     UNIQUE(device_id,nonce)) **بعد** صحّة التوقيع — فلا يُسمِّم طارقٌ بلا مفتاحٍ
 *     ذاكرةَ جهازٍ؛ والإعادةُ بحذافيرها 409.
 *
 * وبوّاباتُ المنشأة تسبق كلَّ ذلك: قفلُ الطوارئ (`security.lockdown`) يصدّ
 * الأجهزةَ كلَّها 503 — الجهازُ ليس مالكاً فلا يُستثنى (نظيرُ ApiAuth).
 *
 * الجمهورُ **داخليّ**: هذه هويّاتُ آلاتٍ لا حساباتُ عملاء — ولا جلسةَ ولا
 * ApiAuth هنا؛ التوقيعُ هو المصادقة كلُّها، والتنطيقُ على شركة الجهاز يقع في
 * المعالجات عبر `endpoint_device` المثبَّت في attributes.
 */
class EndpointSignature
{
    /** نافذةُ الطابع الزمنيّ بالثواني — البند ٣ من العقد (±300) */
    protected const WINDOW = 300;

    /** صيغةُ الـnonce المقبولة — البند ١ من العقد (8–64 من [A-Za-z0-9._-]) */
    protected const NONCE_RE = '/^[A-Za-z0-9._-]{8,64}$/';

    public function handle(Request $request, Closure $next): Response
    {
        // ردودُ الأجهزة دائماً JSON — كما يفعل ApiAuth حرفياً
        $request->headers->set('Accept', 'application/json');

        // قفلُ الطوارئ يسري على الأجهزة كلِّها — آلةٌ لا تُستثنى استثناءَ مالك
        if (setting('security.lockdown')) {
            return Api::error(Api::LOCKDOWN, 503, 'النظام في قفل طوارئ — أوقفت واجهةُ الأجهزة مؤقتاً');
        }

        $id = trim((string) $request->header('X-Endpoint-Id', ''));
        $ts = trim((string) $request->header('X-Endpoint-Timestamp', ''));
        $nonce = trim((string) $request->header('X-Endpoint-Nonce', ''));
        $sigB64 = trim((string) $request->header('X-Endpoint-Signature', ''));

        if ($id === '' || $ts === '' || $nonce === '' || $sigB64 === '') {
            return $this->deny($request, 'ترويساتُ توقيعٍ ناقصة');
        }

        // ٢) الطابعُ داخل النافذة — طلبٌ ملتقَطٌ لا يُعاد بعدها (عقدُ Es256 البند ٣)
        if (! ctype_digit($ts) || abs(time() - (int) $ts) > self::WINDOW) {
            return $this->deny($request, 'طابعُ الوقت خارج النافذة المسموحة (±٣٠٠ ثانية)');
        }

        if (! preg_match(self::NONCE_RE, $nonce)) {
            return $this->deny($request, 'صيغةُ nonce خارج العقد');
        }

        // ٣) جهازٌ معروف — المجهولُ والمحذوفُ يتلقّيان الردَّ نفسَه (لا تسريبَ وجود)
        $device = EndpointDevice::find($id);
        if (! $device || blank($device->public_key)) {
            return $this->deny($request, 'جهازٌ غيرُ معروف');
        }
        if ($device->status !== 'active') {
            \App\Support\SecurityRadar::record($request, 'وصول مرفوض', 'جهازٌ طرفيّ غيرُ نشط: ' . $device->status);

            return Api::error(Api::ACCOUNT_RESTRICTED, 403,
                'هذا الجهاز ' . $device->status . ' — راجع مركزَ النقاط الطرفية');
        }

        // ٤) التوقيعُ على السلسلة القانونية — العقدُ حرفياً من مصدره الواحد
        $sig = base64_decode($sigB64, true);
        if ($sig === false || $sig === '') {
            return $this->deny($request, 'توقيعٌ ليس base64');
        }
        $canonical = Es256::canonical(
            $request->method(),
            '/' . ltrim($request->path(), '/'),
            $ts, $nonce,
            (string) $request->getContent()
        );
        try {
            $valid = Es256::verify($canonical, $sig, (string) $device->public_key);
        } catch (\RuntimeException $e) {
            $valid = false;   // مفتاحٌ مخزَّنٌ فاسد: يُرفض الطلبُ ويُبلَّغ — لا يمرّ عطلاً صامتاً
            report($e);
        }
        if (! $valid) {
            \App\Support\SecurityRadar::record($request, 'وصول مرفوض', 'توقيعُ جهازٍ طرفيّ لا يصحّ');

            return $this->deny($request, 'التوقيعُ غير صحيح');
        }

        // ٥) ادّعاءُ الـnonce ذرّياً **بعد** صحّة التوقيع — الإعادةُ بحذافيرها 409
        $fresh = (bool) DB::table('endpoint_nonces')->insertOrIgnore([
            'device_id' => $device->id, 'nonce' => $nonce, 'created_at' => now(),
        ]);
        if (! $fresh) {
            \App\Support\SecurityRadar::record($request, 'وصول مرفوض', 'إعادةُ طلبِ جهازٍ طرفيّ (nonce معاد)');

            return Api::error(Api::CONFLICT, 409, 'طلبٌ مُعادٌ بحذافيره — كلُّ طلبٍ بـnonce جديد');
        }

        // تقليمٌ فرصيّ (١ من ٥٠): ما جاوز ٢٠ دقيقةً خارجُ نافذة الطابع أصلاً فلا يلزم
        if (random_int(1, 50) === 1) {
            try {
                DB::table('endpoint_nonces')->where('created_at', '<', now()->subMinutes(20))->delete();
            } catch (\Throwable $e) {
                // تقليمٌ متعثّر لا يوقف طلباً صالحاً
            }
        }

        // الجهازُ المصادَق يثبت للمعالجات — التنطيقُ على شركته يقع هناك (عبرَ شركةٍ ٤٠٤)
        $request->attributes->set('endpoint_device', $device);

        return $next($request);
    }

    /** ردُّ 401 موحَّد — سببٌ للسجل لا تشريحٌ يفيد طارقاً */
    protected function deny(Request $request, string $why): Response
    {
        \App\Support\SecurityRadar::record($request, 'وصول مرفوض', 'نقاطٌ طرفية: ' . $why);

        return Api::error(Api::UNAUTHENTICATED, 401, 'توقيعُ الجهاز مطلوبٌ وصحيح — راجع عقدَ Es256');
    }
}
