<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\EndpointCommand;
use App\Models\EndpointDevice;
use App\Models\EndpointEvent;
use App\Models\EndpointRelease;
use App\Support\Api;
use App\Support\EndpointPrivacy;
use App\Support\Es256;
use App\Support\FlowRunner;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * **بروتوكولُ النقاط الطرفية الموقَّع** — Work OS · الطور J · WP-J.2 · §43.
 *
 * أربعةُ مسارات جهازٍ خلف وسيط `endpoint.signature` (عقدُ التوقيع مكتوبٌ مرةً
 * واحدةً في docblock ‏`App\Support\Es256` — replay/طابع/nonce تُفرَض هناك قبل
 * الوصول هنا)، وسطحُ إصدارٍ داخليّ واحد:
 *
 *  • `heartbeat` — نبضةٌ تحدّث `last_heartbeat_at` والعتادَ والوضعيّةَ **الصادقة**
 *    (C15: قيمةٌ خارج {active|inactive|not-configured} تُخزَّن 'not-configured'
 *    — **أبداً** لا 'active')، وتكتب مقاييسَ الأسطول عبر `hub_metric_put`
 *    (المخزنُ الواحد `metric_points` — لا مخزنَ ثانياً).
 *  • `event` — ابتلاعُ حدثٍ: **مُصادِقُ الخصوصيّة أولاً** (حقولُ المراقبة تُرَدّ
 *    422 مسجَّلةً ولا يُخزَّن منها حرف)، ثم allowlist للنوع، والملخّصُ منقّحٌ
 *    مقصوص. `usb` يبثّ `endpoint.usb_event` و`posture` عالي الشدّة يبثّ
 *    `endpoint.posture_alert` (السجلُّ في hub.events؛ عتباتُ SecurityEvents
 *    ENDPOINT_* في WP-J.3).
 *  • `commandsPull` — ادّعاءُ المستحقّ **ذرّياً** (آلةُ حالة outbox حرفياً:
 *    UPDATE مشروطٌ بـstate='pending') — سحبان متزامنان لا يزدوج بينهما أمر.
 *  • `commandResult` — انتقالٌ مشروطٌ claimed→done|failed، ونتيجةٌ تمرّ
 *    بمُصادِق الخصوصيّة، وتوقيعُ نتيجةٍ اختياريّ يُتحقَّق (عقدُه أدناه).
 *  • `issue` (ويب، داخليّ) — إصدارُ أمرٍ من **القائمة المغلقة الخمسة لا غير**
 *    (C10 — لا shell): isolate/lock تصعيدُ هويةٍ + سببٌ إلزاميّ + تدقيق؛
 *    وidempotency عبر UNIQUE(device_id,ikey).
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * **عقدُ توقيع النتيجة (اختياريّ — يرافق عقدَ الطلب في docblock ‏Es256):**
 * حين يرفق الوكيلُ `result_sig` فهو ES256 (البدائيّةُ الواحدة نفسُها) على
 * السلسلة UTF-8:
 *
 *     IKEY + "\n" + STATE + "\n" + sha256hex(RESULT_RAW)
 *
 * حيث `RESULT_RAW` هو **نصُّ** حقل `result` كما أرسله الوكيلُ حرفياً (الحقلُ
 * يسافر نصَّ JSON خاماً لا كائناً — فالتوقيعُ على بايتاتٍ ثابتةٍ لا على إعادةِ
 * ترميزٍ قد تُبدّل ترتيبَ المفاتيح؛ درسُ MySQL 8)، والغائبُ تجزئتُه تجزئةُ
 * السلسلة الفارغة. `result_sig` يسافر base64(DER) ويُخزَّن مع الصفّ أثراً
 * قابلاً للإسناد. توقيعٌ مرفَقٌ لا يصحّ = 422 ولا انتقالَ حالة.
 * وكيلُ الطور K يعكس العقدين معاً.
 * ─────────────────────────────────────────────────────────────────────────────
 *
 * الجمهورُ **داخليّ** بالبناء: مساراتُ الجهاز هويّةُ توقيعٍ لا جلسة، وسطحُ
 * الإصدار خلف auth + مالك/مراقب + عزلِ شركةٍ (عبرَ شركةٍ ٤٠٤) — وحسابُ
 * العميل ٤٠٤ فوق كل شيء (PortalGuard قائمةٌ بيضاء + دفاعٌ هنا).
 */
class EndpointProtocolController extends Controller
{
    /** الجهازُ المصادَق — يثبّته وسيطُ التوقيع قبل أي معالج */
    protected function device(Request $r): EndpointDevice
    {
        return $r->attributes->get('endpoint_device');
    }

    /**
     * بوّابةُ الخصوصيّة المفروضة خادمياً: مخالفةٌ = ردٌّ 422 **مسجَّل** —
     * والحمولةُ لا تُخزَّن ولا تُقتبس في السجل (مساراتُها وحدَها تكفي للتشخيص).
     */
    protected function privacyGate(Request $r, array $payload, string $surface)
    {
        $bad = EndpointPrivacy::violations($payload);
        if ($bad === []) return null;

        Log::warning('رُفضت حمولةُ نقطةٍ طرفية تحمل حقولَ مراقبة', [
            'surface' => $surface,
            'device_id' => $this->device($r)->id,
            'keys' => array_slice($bad, 0, 10),
            'request_id' => Api::requestId(),
        ]);
        \App\Support\SecurityRadar::record($r, 'وصول مرفوض',
            'حمولةُ مراقبةٍ من جهازٍ طرفيّ (' . $surface . '): ' . implode('، ', array_slice($bad, 0, 5)));

        return Api::error(Api::VALIDATION_FAILED, 422,
            'الخصوصيّةُ مفروضةٌ خادمياً: حقولُ المراقبة (' . implode('، ', array_slice($bad, 0, 5)) . ') مرفوضةٌ ولا تُخزَّن');
    }

    /* ───────── النبضة ───────── */

    public function heartbeat(Request $r)
    {
        $device = $this->device($r);

        // الخصوصيّةُ قبل كل شيء — جردُ عتادٍ يحمل مفتاحَ مراقبةٍ يُرَدّ كاملاً
        if ($resp = $this->privacyGate($r, (array) $r->json()->all(), 'heartbeat')) return $resp;

        $d = $r->validate([
            'hostname' => ['nullable', 'string', 'max:190'],
            'agent_version' => ['nullable', 'string', 'max:60'],
            'hw' => ['nullable', 'array'],
            'posture' => ['nullable', 'array'],
        ]);

        // **الوضعيّةُ الصادقة (C15):** ما ليس قراءةً معلومةً صريحةً يُخزَّن
        // 'not-configured' — قراءةٌ منعها النظامُ أو ادّعاءٌ خارج القائمة لا
        // يتحوّلان 'active' أبداً؛ 'active' لا تُكتب إلا كما أُبلغت حرفياً.
        $posture = null;
        if (is_array($d['posture'] ?? null)) {
            $posture = [];
            foreach ($d['posture'] as $check => $reading) {
                if (! is_string($check) || trim($check) === '') continue;
                $reading = is_string($reading) ? strtolower(trim($reading)) : '';
                $posture[mb_substr(trim($check), 0, 60)] =
                    in_array($reading, ['active', 'inactive', 'not-configured'], true) ? $reading : 'not-configured';
                if (count($posture) >= 30) break;
            }
        }

        // saveQuietly: نبضةٌ كل دقائق لا تُغرق التدقيقَ ولا ترفع نسخةَ القفل
        // التفاؤليّ — والقصُّ عند الكاتب هنا لأن الحارسَ الصامت لا يُستدعى.
        $device->forceFill(array_filter([
            'last_heartbeat_at' => now(),
            'hostname' => isset($d['hostname']) ? mb_substr(trim((string) $d['hostname']), 0, 120) : null,
            'agent_version' => isset($d['agent_version']) ? mb_substr((string) $d['agent_version'], 0, 30) : null,
            'hw' => $d['hw'] ?? null,
            'posture' => $posture,
        ], fn ($v) => $v !== null))->saveQuietly();

        // مقاييسُ الأسطول عبر السكّة الواحدة hub_metric_put — لا مخزنَ ثانياً:
        // نبضةٌ (١) لسلسلة الحضور، وعدُّ الفحوص الفعّالة **الصادقة** للوضعيّة.
        hub_metric_put('endpoints', (string) $device->id, 'heartbeat', 1.0, now(), 'agent');
        if ($posture !== null) {
            hub_metric_put('endpoints', (string) $device->id, 'posture_ok',
                (float) count(array_filter($posture, fn ($v) => $v === 'active')), now(), 'agent');
        }

        return response()->json([
            'ok' => true,
            // القارئُ الحقيقيّ للإعداد endpoint.heartbeat_interval_min — الوكيلُ
            // يعايرُ إيقاعَه من هنا (متمايزٌ عن heartbeat.* الخاص بالمجدولات).
            'interval_min' => max(1, (int) setting('endpoint.heartbeat_interval_min', 5)),
            'pending_commands' => EndpointCommand::where('device_id', $device->id)
                ->where('state', 'pending')->count(),
        ]);
    }

    /* ───────── ابتلاعُ الأحداث ───────── */

    public function event(Request $r)
    {
        $device = $this->device($r);

        // الخصوصيّةُ أولاً — قبل التحقق من الشكل حتى: لا يُخزَّن حرفُ مراقبةٍ أبداً
        if ($resp = $this->privacyGate($r, (array) $r->json()->all(), 'event')) return $resp;

        $d = $r->validate([
            'kind' => ['required', 'string', Rule::in(EndpointEvent::KINDS)],       // allowlist لا enum (C10)
            'severity' => ['nullable', 'string', Rule::in(EndpointEvent::SEVERITIES)],
            'summary' => ['required', 'string', 'max:2000'],                        // يُنقَّح ويُقصّ ٤٠٠ عند الكاتب
            'meta' => ['nullable', 'array'],
        ]);

        try {
            $event = EndpointEvent::create([
                'device_id' => $device->id,
                'company_id' => $device->company_id,             // من صفّ الجهاز لا من الحمولة
                'kind' => $d['kind'],
                'severity' => $d['severity'] ?? 'info',
                'summary' => $d['summary'],                      // النموذجُ ينقّح ويقصّ (EndpointPrivacy::sanitizeText)
                'meta' => $d['meta'] ?? null,
                'nonce' => (string) $r->header('X-Endpoint-Nonce', ''),
                'request_id' => Api::requestId(),
                'created_at' => now(),
            ]);
        } catch (\Illuminate\Database\QueryException $e) {
            // UNIQUE(device_id,nonce) — دفاعُ عمقٍ لا يفترض بقاءَ ترتيبِ الوسيط
            return Api::error(Api::CONFLICT, 409, 'حدثٌ مُعادٌ بحذافيره من الطلب نفسِه');
        }

        // بثُّ الأحداث الدلالية (hub.events): usb دوماً، وposture حين تعلو الشدّة.
        if ($d['kind'] === 'usb') {
            FlowRunner::fire('usb_event', 'endpoints', $device);
        } elseif ($d['kind'] === 'posture' && in_array((string) ($d['severity'] ?? ''), ['warning', 'high'], true)) {
            FlowRunner::fire('posture_alert', 'endpoints', $device);
        }

        // (WP-J.3 · §63) أكوادُ SecurityEvents ‏ENDPOINT_*: قيدٌ أمنيّ **واحد** عند
        // بلوغ العتبة داخل النافذة — لا ضجيجَ لكل حدث. القرارُ (العتبةُ والتفرّدُ
        // للنافذة) في `SecurityEvents::endpointAlerts`، والصيغتان **حرفيّتان هنا**
        // (موضعُ الكتابة الذي تثبته خريطةُ التغطية) والكتالوجُ يطابقهما بالكود.
        foreach (\App\Support\SecurityEvents::endpointAlerts($event) as $code => $al) {
            hub_audit(match ($code) {
                'ENDPOINT_USB_SURGE' => 'تكرارُ أحداث USB على جهازٍ طرفيّ',
                'ENDPOINT_POSTURE_ALERT' => 'تدهورُ وضعيّةِ جهازٍ طرفيّ',
                default => $al['action'],
            }, 'endpoints', (string) $device->id,
                mb_substr((string) $device->hostname, 0, 80) . ' — ' . $al['n'] . ' من أحداث '
                    . $d['kind'] . ' خلال ' . $al['window_min'] . ' دقيقة');
        }

        return response()->json(['ok' => true, 'event_id' => $event->id], 201);
    }

    /* ───────── سحبُ الأوامر (ادّعاءٌ ذرّيّ — آلةُ حالة outbox) ───────── */

    public function commandsPull(Request $r)
    {
        $device = $this->device($r);

        // العالقُ في claimed (انقطاعُ وكيلٍ بعد السحب) يعود للصفّ من لحظة الحجز —
        // والمُنهَكُ (ثلاثُ ادّعاءاتٍ بلا نتيجة) ينتهي expired لا يدور للأبد.
        EndpointCommand::where('device_id', $device->id)->where('state', 'claimed')
            ->where('claimed_at', '<', now()->subMinutes(15))
            ->where('attempts', '>=', 3)
            ->update(['state' => 'expired', 'finished_at' => now()]);
        EndpointCommand::where('device_id', $device->id)->where('state', 'claimed')
            ->where('claimed_at', '<', now()->subMinutes(15))
            ->update(['state' => 'pending', 'claimed_at' => null]);

        // المستحقُّ الآن بترتيبٍ حتميّ (id كاسرُ تعادل) — والمؤجَّلُ لا يُلتقط قبل موعده
        $ids = EndpointCommand::where('device_id', $device->id)->where('state', 'pending')
            ->where(fn ($q) => $q->whereNull('next_at')->orWhere('next_at', '<=', now()))
            ->orderBy('created_at')->orderBy('id')->limit(10)->pluck('id');

        // **الادّعاءُ الذرّيّ** (نمطُ HubOutbox حرفياً): UPDATE مشروطٌ بأن الحالة
        // ما زالت pending — سحبان متزامنان: واحدٌ يفوز بالصفّ والآخرُ يراه محجوزاً.
        $claimed = [];
        foreach ($ids as $id) {
            $won = EndpointCommand::whereKey($id)->where('state', 'pending')
                ->update(['state' => 'claimed', 'claimed_at' => now(), 'attempts' => DB::raw('attempts + 1')]);
            if ($won === 1) $claimed[] = $id;
        }

        $commands = EndpointCommand::whereIn('id', $claimed)
            ->orderBy('created_at')->orderBy('id')->get()
            ->map(fn ($c) => ['id' => $c->id, 'type' => $c->type, 'args' => $c->args, 'ikey' => $c->ikey])
            ->values();

        return response()->json(['ok' => true, 'commands' => $commands]);
    }

    /* ───────── نتيجةُ أمرٍ (انتقالٌ مشروط + توقيعُ نتيجةٍ اختياريّ) ───────── */

    public function commandResult(Request $r)
    {
        $device = $this->device($r);

        $d = $r->validate([
            'command_id' => ['required', 'string', 'max:64'],
            'state' => ['required', 'string', Rule::in(['done', 'failed'])],
            // نصُّ JSON خامٌ (بايتاتُ التوقيع الثابتة — عقدُ النتيجة أعلاه)
            'result' => ['nullable', 'string', 'max:60000'],
            'result_sig' => ['nullable', 'string', 'max:700'],
        ]);

        // أمرُ جهازٍ آخر أو معدوم: ٤٠٤ واحد — لا تسريبَ وجود (عزلُ الأجهزة والشركات)
        $cmd = EndpointCommand::where('device_id', $device->id)->whereKey($d['command_id'])->first();
        if (! $cmd) {
            return Api::error(Api::RESOURCE_NOT_FOUND, 404, 'أمرٌ غيرُ معروفٍ لهذا الجهاز');
        }

        // النتيجةُ نصُّ JSON صالحٍ — وتمرّ بمُصادِق الخصوصيّة ككل مدخلٍ من الجهاز
        $resultRaw = (string) ($d['result'] ?? '');
        $result = null;
        if ($resultRaw !== '') {
            $result = json_decode($resultRaw, true);
            if (! is_array($result)) {
                return Api::error(Api::VALIDATION_FAILED, 422, 'حقلُ result نصُّ JSON (كائنٌ أو مصفوفة) لا سواه');
            }
            if ($resp = $this->privacyGate($r, $result, 'command_result')) return $resp;
        }

        // توقيعُ النتيجة الاختياريّ — البدائيّةُ الواحدة Es256 على العقد المعلن أعلاه
        $sigB64 = trim((string) ($d['result_sig'] ?? ''));
        if ($sigB64 !== '') {
            $sig = base64_decode($sigB64, true);
            $signed = $cmd->ikey . "\n" . $d['state'] . "\n" . hash('sha256', $resultRaw);
            $valid = false;
            try {
                $valid = $sig !== false && $sig !== '' && Es256::verify($signed, $sig, (string) $device->public_key);
            } catch (\RuntimeException $e) {
                report($e);
            }
            if (! $valid) {
                \App\Support\SecurityRadar::record($r, 'وصول مرفوض', 'توقيعُ نتيجةِ أمرٍ طرفيّ لا يصحّ');

                return Api::error(Api::VALIDATION_FAILED, 422, 'توقيعُ النتيجة لا يصحّ بعقده — لا انتقالَ حالة');
            }
        }

        // **الانتقالُ المشروط** (آلةُ الحالة): claimed→done|failed فقط — نتيجةٌ
        // لأمرٍ غير مُدَّعى أو منتهٍ = 409، والحالةُ النهائية لا تُدهس أبداً.
        $moved = EndpointCommand::whereKey($cmd->id)->where('state', 'claimed')->update([
            'state' => $d['state'],
            'result' => $result === null ? null : json_encode($result, JSON_UNESCAPED_UNICODE),
            'result_sig' => $sigB64 !== '' ? mb_substr($sigB64, 0, 700) : null,
            'finished_at' => now(),
            'updated_at' => now(),
        ]);
        if ($moved !== 1) {
            return Api::error(Api::CONFLICT, 409, 'الأمرُ ليس في حالة claimed — النتيجةُ تُقدَّم لأمرٍ مُدَّعى مرةً واحدة');
        }

        return response()->json(['ok' => true, 'state' => $d['state']]);
    }

    /* ───────── بيانُ تحديث الوكيل (الطور L · WP-L.2 · §44/§62) ───────── */

    /**
     * **البيانُ الموقَّع**: أحدثُ إصدارٍ منشورٍ لنظامِ الجهاز ومعماريّته — بالشكل
     * الذي يستهلكه `agent/internal/update.Apply` حرفياً: `{url, sha256}` (ومعهما
     * `version` و`signing_status` — المفاتيحُ الزائدة تُهمَل في Go فلا تكسر).
     *
     * النطاقُ نطاقُ الجهاز لا الطلب: `os` من صفّ الجهاز المصادَق حصراً؛ و`arch`
     * من جردِ نبضته **الموقَّعة** (`hw.arch` — يبلّغه الوكيلُ runtime.GOARCH)
     * أولاً، فإن غاب فتلميحُ الاستعلام `?arch=` (خارجُ التوقيع — path() لا يحمل
     * الاستعلام، فلا يغلب قراءةً موقَّعة)، وإلا amd64. والتجزئةُ في البيان هي
     * تجزئةُ الأرتيفاكت الحقيقية المحسوبةُ خادمياً عند النشر — انحرافُها على
     * الجهاز رفضُ تبديلٍ قاطعٌ (عقدُ Apply).
     *
     * **الصدقُ (C15):** `signing_status` يسافر كما خُزّن — 'unsigned-dev' ما لم
     * يوقَّع توقيعٌ حقيقيّ ويُقرَّ تحقّقُه؛ لا ادّعاءَ في البيان أبداً.
     */
    public function agentManifest(Request $r)
    {
        $device = $this->device($r);

        // المعماريّة: القراءةُ الموقَّعة (نبضةُ hw) تغلب تلميحَ الاستعلام غيرَ الموقَّع
        $hwArch = strtolower(trim((string) data_get($device->hw, 'arch', '')));
        $qArch = strtolower(trim((string) $r->query('arch', '')));
        $arch = in_array($hwArch, EndpointRelease::ARCHES, true) ? $hwArch
            : (in_array($qArch, EndpointRelease::ARCHES, true) ? $qArch : 'amd64');

        // «الأحدثُ لمنصّتي» بترتيبٍ حتميّ (created_at ثم id كاسرُ تعادل — لا قرعة)
        $rel = EndpointRelease::where('os', (string) $device->os)->where('arch', $arch)
            ->orderByDesc('created_at')->orderByDesc('id')->first();
        if (! $rel) {
            return Api::error(Api::RESOURCE_NOT_FOUND, 404, 'لا إصدارَ منشوراً لهذه المنصّة بعد');
        }

        return response()->json([
            'version' => $rel->version,
            'url' => route('endpoint.agent.download', $rel->id),   // مسارُ التنزيل الموقَّع نفسُه
            'sha256' => $rel->sha256,
            'signing_status' => $rel->signing_status,              // الصدقُ يسافر مع البيان (C15)
        ]);
    }

    /**
     * **تنزيلُ الجهاز الموقَّع** — خلف `EndpointSignature` كأخواته (لا public
     * storage ولا سكّةَ تقديمٍ ثانية): الإصدارُ يُقدَّم لجهازٍ نظامُه نظامُ
     * الإصدار حصراً (عبرَ نظامٍ ٤٠٤ — لا تسريبَ وجود)، من القرص المحليّ، بصفِّ
     * `download_log` لكل نجاحٍ (السكّةُ الواحدة — user_id فارغٌ فالهويّةُ آلة)
     * وبـContent-Disposition: attachment (انضباطُ AttachmentController).
     */
    public function agentDownload(Request $r, string $id)
    {
        $device = $this->device($r);

        $rel = EndpointRelease::whereKey($id)->where('os', (string) $device->os)->first();
        if (! $rel) {
            return Api::error(Api::RESOURCE_NOT_FOUND, 404, 'إصدارٌ غيرُ معروفٍ لمنصّة هذا الجهاز');
        }
        $abs = \Illuminate\Support\Facades\Storage::disk('local')->path($rel->path);
        if (! is_file($abs)) {
            return Api::error(Api::RESOURCE_NOT_FOUND, 404, 'الأرتيفاكت غير موجود على القرص');
        }

        DB::table('download_log')->insert([
            'attachment_id' => $rel->id, 'user_id' => null,
            'ip' => $r->ip(),
            'device' => substr('وكيل طرفيّ ' . $device->hostname . ' · إصدار ' . $rel->version, 0, 200),
            'created_at' => now(),
        ]);

        return response()->download($abs, $rel->fileName());
    }

    /* ───────── الإصدار (ويب · داخليّ · القائمةُ المغلقة) ───────── */

    public function issue(Request $r, string $id)
    {
        // العميلُ ٤٠٤ فوق كل شيء (دفاعٌ في العمق تحت PortalGuard — نمطُ mint)
        abort_if(hub_is_client(auth()->user()), 404);
        abort_unless(hub_is_owner() || hub_monitor(), 403, 'إصدارُ أوامر الأجهزة للمالك أو المراقب');

        // عبرَ شركةٍ: ٤٠٤ لا تسريبَ وجود — معزولُ شركاتٍ لا يأمر غيرَ أجهزته
        $cids = hub_company_ids();
        $device = EndpointDevice::whereKey($id)
            ->when($cids !== null, fn ($q) => $q->whereIn('company_id', $cids))
            ->first();
        abort_unless($device, 404);

        // §3 — أهليّةُ الأوامرِ مقصورةٌ على الجهاز **النشط**: المعلَّقُ (حالةُ أصلٍ
        // نهائيّة) والمقفولُ والمتقاعدُ لا تُصدَر لهم أوامرُ جديدة (المعلَّقُ لا يبلغ
        // أصلاً — وسيطُ التوقيع يردّه ٤٠٣). صدقٌ: لا أمرٌ يُخزَّن لجهازٍ لن ينفّذه.
        abort_unless($device->status === 'active', 409,
            'الجهازُ غيرُ نشط (معلَّق/مقفول/متقاعد) — لا تُصدَر له أوامر');

        $d = $r->validate([
            // **القائمةُ المغلقة الخمسة لا غير** (C10) — نوعٌ غيرُ مُدرَجٍ (وأيُّ
            // فكرةِ shell) = 422 قبل أيّ أثر؛ لا مسارَ أمرٍ حرّ في النظام.
            'type' => ['required', 'string', Rule::in(EndpointCommand::TYPES)],
            'args' => ['nullable', 'array'],
            'reason' => [Rule::requiredIf(fn () => in_array((string) $r->input('type'), EndpointCommand::STEPUP_TYPES, true)),
                'nullable', 'string', 'max:400'],
            'ikey' => ['nullable', 'string', 'max:64', 'regex:/^[A-Za-z0-9._:-]{1,64}$/'],
        ], [], ['type' => 'نوع الأمر', 'reason' => 'السبب', 'ikey' => 'مفتاح التكرار']);

        // معاملاتُ الأمر من الداخل أيضاً لا تحمل ألفاظَ مراقبة — اتساقُ البوّابة
        if (is_array($d['args'] ?? null) && ($bad = EndpointPrivacy::violations($d['args'])) !== []) {
            return Api::error(Api::VALIDATION_FAILED, 422,
                'حقولُ مراقبةٍ في معاملات الأمر: ' . implode('، ', array_slice($bad, 0, 5)));
        }

        // الخطيران (والمستقبليّ wipe مثلُهما): تصعيدُ هويةٍ طازجٌ فوق السبب الإلزاميّ
        if (in_array((string) $d['type'], EndpointCommand::STEPUP_TYPES, true)) {
            if ($resp = hub_require_stepup()) return $resp;
        }

        $ikey = trim((string) ($d['ikey'] ?? '')) ?: (string) Str::uuid();

        try {
            $cmd = EndpointCommand::create([
                'device_id' => $device->id,
                'company_id' => $device->company_id,
                'type' => $d['type'],
                'args' => $d['args'] ?? null,
                'state' => 'pending',
                'ikey' => $ikey,
                'reason' => $d['reason'] ?? null,
                'by_id' => (string) auth()->id(),
            ]);
        } catch (\Illuminate\Database\QueryException $e) {
            // idempotency صلب: UNIQUE(device_id,ikey) — الإصدارُ المكرَّر يعيد
            // الأمرَ القائمَ بعينه، لا أمراً ثانياً يُرسَل مرتين.
            $existing = EndpointCommand::where('device_id', $device->id)->where('ikey', $ikey)->firstOrFail();

            return response()->json(['ok' => true, 'command_id' => $existing->id,
                'state' => $existing->state, 'duplicate' => true]);
        }

        // الأثرُ الإلزاميّ: من أمرَ، أيَّ جهازٍ، بأيّ نوعٍ، ولماذا (السببُ للخطيرين).
        // الصيغتان الخطيرتان **حرفيّتان** (WP-J.3): يصنّفهما SecurityEvents بالكود
        // ENDPOINT_CMD_CRITICAL وتثبتهما خريطةُ التغطية «proven» — لا تركيبَ من متغيّر.
        hub_audit(in_array((string) $d['type'], EndpointCommand::STEPUP_TYPES, true)
                ? ($d['type'] === 'isolate' ? 'أمرُ عزلِ جهازٍ طرفيّ' : 'أمرُ قفلِ جهازٍ طرفيّ')
                : 'إصدارُ أمرٍ لجهازٍ طرفيّ',
            'endpoints', $cmd->id,
            $d['type'] . ' ← ' . mb_substr((string) $device->hostname, 0, 80)
                . (filled($d['reason'] ?? null) ? ' — ' . mb_substr((string) $d['reason'], 0, 200) : ''));

        return response()->json(['ok' => true, 'command_id' => $cmd->id, 'state' => 'pending'], 201);
    }
}
