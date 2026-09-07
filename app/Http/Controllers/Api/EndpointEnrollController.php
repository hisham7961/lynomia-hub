<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\EndpointDevice;
use App\Models\EnrollmentToken;
use App\Support\Api;
use App\Support\Es256;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * **تسجيلُ النقاط الطرفية اللاتماثليّ** — Work OS · الطور J · WP-J.1 · §43.
 *
 * وجهان لا ثالثَ لهما:
 *  • `mint` (ويب، داخليّ): مالك/مراقب + `hub_require_stepup` يسكّ رمزَ تسجيلٍ
 *    مُسنَداً لشركةٍ (وموظفٍ اختياراً) — النصُّ الصريح يُعرَض مرةً واحدةً ولا
 *    يبلغ القاعدةَ ولا التدقيقَ إلا sha256 (انضباطُ `ApiToken.token_hash`).
 *  • `enroll` (API عامّ بالرمز): الجهازُ يرسل هويّتَه ومفتاحَه **العامَّ** PEM
 *    (عقدُ التوقيع في docblock ‏`App\Support\Es256`) — الخاصُّ يُولَّد على الجهاز
 *    ولا يُرسَل قط؛ حمولةٌ تحمل أيَّ مادةِ مفتاحٍ خاصّ تُرَدّ 422 قبل كل شيء.
 *
 * الإسنادُ من الرمز وحدَه: الجهازُ لا يختار شركتَه — `company_id`/`employee_id`
 * يُقرآن من صفّ الرمز المسكوك، والاستهلاكُ ذرّيٌّ (الثاني 409 — لا جهازين برمز).
 */
class EndpointEnrollController extends Controller
{
    /* ───────── السكّ (ويب · داخليّ · step-up) ───────── */

    public function mint(Request $r)
    {
        // العميلُ ٤٠٤ فوق كل شيء (دفاعٌ في العمق تحت PortalGuard — نمطُ StationController)
        abort_if(hub_is_client(auth()->user()), 404);
        abort_unless(hub_is_owner() || hub_monitor(), 403, 'سكُّ رموز التسجيل للمالك أو المراقب');
        if ($resp = hub_require_stepup()) return $resp;

        $d = $r->validate([
            'companyId' => ['required', 'string', 'exists:companies,id'],
            'employeeId' => ['nullable', 'string', 'exists:users,id'],
        ], [], ['companyId' => 'الشركة', 'employeeId' => 'الموظف']);

        // عبرَ شركةٍ: ٤٠٤ لا تسريبَ وجود — معزولُ شركاتٍ لا يسكّ لغير شركاته
        $cids = hub_company_ids();
        abort_if($cids !== null && ! in_array((string) $d['companyId'], $cids, true), 404);

        [$token, $plain] = EnrollmentToken::mint((string) $d['companyId'], $d['employeeId'] ?? null, (string) auth()->id());

        // القيدُ بلا النصّ الصريح أبداً — البصمةُ المخزَّنة تكفي للمطابقة لاحقاً
        hub_audit('سكُّ رمزِ تسجيلِ جهازٍ طرفيّ', 'endpoints', $token->id,
            'ينتهي ' . $token->expires_at->format('Y-m-d H:i'));

        return back()->with('ok', 'سُكَّ رمزُ التسجيل — انسخه الآن: لن يظهر ثانيةً')
            ->with('enroll_token', $plain)
            ->with('enroll_expires_at', $token->expires_at->toDateTimeString());
    }

    /* ───────── التسجيل (API عامّ بالرمز — لا جلسة) ───────── */

    public function enroll(Request $r)
    {
        /*
         * **الخاصُّ لا يبلغ الخادمَ أبداً**: قبل أيّ تحقّقٍ آخر يُفحص الجسمُ الخام —
         * أيُّ مادةِ «PRIVATE KEY» (بأيّ حقلٍ كانت) تعني وكيلاً معطوباً أو عدائياً
         * يحاول إيداعَ سرّه، فتُرَدّ كاملةً ولا يُخزَّن منها حرف.
         */
        if (stripos((string) $r->getContent(), 'PRIVATE KEY') !== false) {
            return Api::error(Api::VALIDATION_FAILED, 422,
                'المفتاحُ الخاصّ لا يُرسَل إلى الخادم أبداً — أرسل المفتاحَ العامَّ وحدَه');
        }

        $d = $r->validate([
            'token' => ['required', 'string', 'max:120'],
            'device_uuid' => ['required', 'string', 'max:64', 'regex:/^[A-Za-z0-9._:-]{8,64}$/'],
            'hostname' => ['required', 'string', 'max:120'],
            'os' => ['required', 'string', 'in:' . implode(',', EndpointDevice::OSES)],
            'agent_version' => ['nullable', 'string', 'max:30'],
            'hw' => ['nullable', 'array'],
            'public_key' => ['required', 'string', 'max:4000'],
        ]);

        // مفتاحُ العقد حصراً: PEM عامّ على P-256 — RSA/P-384/نصٌّ مهمل = 422
        if (! Es256::isP256PublicKey((string) $d['public_key'])) {
            return Api::error(Api::VALIDATION_FAILED, 422,
                'المفتاحُ العامّ يجب أن يكون ECDSA P-256 بصيغة PEM (عقدُ Es256)');
        }

        // مجهولٌ ومنتهٍ يتلقّيان الردَّ نفسَه — لا تمييزَ يفيد طارقاً بالتخمين
        $token = EnrollmentToken::findByPlain((string) $d['token']);
        if (! $token || $token->expires_at->isPast()) {
            return Api::error(Api::UNAUTHENTICATED, 401, 'رمزُ تسجيلٍ غيرُ صالحٍ أو منتهٍ');
        }
        if ($token->consumed_at !== null) {
            return Api::error(Api::CONFLICT, 409, 'رمزُ التسجيل استُهلك — يُسَكّ رمزٌ جديد لكل جهاز');
        }

        // هويّةٌ مكرَّرة: جهازٌ قائمٌ بالهويّة أو بالمفتاح نفسِه لا يُنشأ ثانيةً
        $fp = Es256::fingerprint((string) $d['public_key']);
        if (EndpointDevice::withTrashed()->where('device_uuid', $d['device_uuid'])->exists()
            || EndpointDevice::withTrashed()->where('pubkey_fp', $fp)->exists()) {
            return Api::error(Api::CONFLICT, 409, 'جهازٌ بهذه الهويّة أو بهذا المفتاح مسجَّلٌ فعلاً');
        }

        $device = DB::transaction(function () use ($token, $d) {
            // الادّعاءُ الذرّيّ أولاً (نمطُ claim في outbox): المتسابقُ الثاني يخسر هنا
            if (! $token->consume()) {
                abort(409, 'رمزُ التسجيل استُهلك للتوّ');
            }

            // **الإسنادُ من الرمز لا من الحمولة** — الجهازُ لا يختار شركتَه.
            // والقيدان الفريدان (device_uuid/pubkey_fp) حاجزُ السباق الأخير:
            // متسابقان برمزين مختلفين وهويّةٍ واحدة → الثاني 409 لا خطأَ خادم.
            try {
                $device = EndpointDevice::create([
                    'device_uuid' => $d['device_uuid'],
                    'company_id' => $token->company_id,
                    'employee_id' => $token->employee_id,
                    'hostname' => $d['hostname'],
                    'os' => $d['os'],
                    'agent_version' => $d['agent_version'] ?? null,
                    'hw' => $d['hw'] ?? null,
                    'public_key' => $d['public_key'],   // النموذجُ يشتقّ pubkey_fp ويرفض أيَّ مادةٍ خاصة
                    'status' => 'active',
                ]);
            } catch (\Illuminate\Database\QueryException $e) {
                abort(409, 'جهازٌ بهذه الهويّة أو بهذا المفتاح مسجَّلٌ فعلاً');
            }

            hub_audit('تسجيلُ جهازٍ طرفيّ', 'endpoints', $device->id,
                mb_substr((string) $device->hostname, 0, 120));
            \App\Support\FlowRunner::fire('enrolled', 'endpoints', $device);

            return $device;
        });

        // ردُّ التسجيل يعلن **سطحَ البروتوكول الموقَّع كاملاً** — بابُ الوكيل الوحيد
        // إلى مساراته (لا شاشةَ يقرؤها جهاز): كلُّها خلف عقد Es256 (docblock هناك).
        return response()->json([
            'ok' => true,
            'device_id' => $device->id,           // يسافر لاحقاً في X-Endpoint-Id (عقدُ Es256)
            'pubkey_fp' => $device->pubkey_fp,
            'heartbeat_path' => '/api/v1/endpoint/heartbeat',
            'event_path' => '/api/v1/endpoint/event',
            'commands_pull_path' => '/api/v1/endpoint/commands/pull',
            'commands_result_path' => '/api/v1/endpoint/commands/result',
        ], 201);
    }
}
