<?php

namespace App\Http\Controllers\Api;

use App\Http\Middleware\ResolveChunkedUploads;
use App\Models\Attachment;
use App\Support\Api;
use App\Support\AttachmentService;
use App\Support\ChunkedUpload;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * **ملفّاتُ الجوال + الماسحُ + الموقع (F.1/F.2/F.3/F.4)** — Mobile Readiness · الطور F.
 *
 * **يرث `V1Controller`** (نظيرُ `MobileResourceController`/`MobileCommController`)
 * لِيَرِثَ ثلاثَ سككٍ جاهزةٍ للطور F:
 *  • **الماسحُ (F.3):** `V1Controller::identityResolve($q)` — المحلّلُ الموحّد
 *    (عهدة/باركود/سيريال). لا `api_token` في الجوال ⇒ `tokenAllows` يمرّ (صلاحيّاتُ
 *    المستخدم كاملةً تحت `hub_can`)، والنطاقُ من `Identity::resolve` — فلا يُكشَف
 *    سجلٌّ خارجَ نطاق المستخدم.
 *  • **الموقعُ (F.4):** `V1Controller::trackStart/trackIngest/trackEnd` — بدءٌ
 *    بموافقةٍ صريحة (`consent=true`)، دفعاتٌ (`Tracking::BATCH_MAX`)، منعُ تكرارٍ
 *    بنيويّ + تسلسلٌ في `Tracking::ingest`، ثم إنهاءٌ صريح — لا تتبّعٌ خفيٌّ دائم.
 *  • **الـIdempotency (F.1/F.4):** `idempotentBegin/Finish/Release` الموروثة، بمالكِ
 *    الجوال (`Idempotency::owner` ⇒ `mobile_session->id` · Critic F1) — لدفعةِ النقاط
 *    (F.4) وإتمامِ الرفع (F.1) كي لا تُضاعف إعادةُ المحاولة نقاطاً أو مرفقاً.
 *
 * **جوهرُ الملفّات (F.1/F.2):** يُعاد استعمالُ `App\Support\AttachmentService` — لا
 * نسخةَ منطقٍ ثانية (Critic F2): `validateUpload` + `guardRecord(...,'v')` +
 * `filesFromRequest` + `attach` (قائمةُ الكتابة البيضاء + البصمةُ sha256 + القرصُ
 * الخاصّ `local` — لا base64 ولا رابطٌ عامّ) + `download`/`stream` (حاجزُ الإصابة +
 * سجلُّ الوصول + التدقيق). **الرفعُ المقطَّعُ يُعاد استعمالُ `App\Support\ChunkedUpload`**
 * (لا جدولَ جديد — القرارُ موثَّق): `append` للقطعة و`claim` للتجميع، وعلامةُ
 * `ResolveChunkedUploads::FLAG` تُرفع في «الإتمام» كي يرفع `hub_upload_cap` سقفَ
 * الطلب الواحد عن الملفّ المجمَّع.
 *
 * كلُّ ردٍّ بغلاف `Api::*`؛ الهويّةُ من الجلسة وحدَها (`auth()->user()` الذي أرسته
 * `MobileSessionAuth`) — لا يُوثَق بأيِّ هويّةٍ/نطاقٍ/ملكيّةٍ يرسلها العميل.
 */
class MobileFileController extends V1Controller
{
    // ═══════════════════════ F.1 · رفعُ الملفّات (مقطَّع + مفرد) ═══════════════════════

    /**
     * `POST files/upload-session` — بدءُ جلسةِ رفعٍ مقطَّع · F.1.
     *
     * يُعلن الهدفَ (module/record_id/filename/mime/size) ويُحقَّق **خادميّاً الآن**:
     *  • `guardRecord(...,'v')` — إخفاقٌ مبكّرٌ إن لم يُخوَّل (لا IDOR، والحارسُ نفسُه
     *    يُعاد عند «الإتمام» فلا مسارَ تصعيد: العميلُ قد يبدأ جلسةً على سجلٍ ثم يُتمّها
     *    على آخر، لكنّ «الإتمام» يعيد التحقّق فلا يُرفَق إلا على مخوَّل).
     *  • حاجزُ الامتداد على `filename` — رفضُ نوعٍ تنفيذيٍّ قبل رفع أيِّ بايت.
     *  • `size` المُعلَن (إن وُجد) يُقاس بحدِّ **النظام** (`appKb`) — سقفُ الملفّ المجمَّع.
     *
     * لا جدولَ قاعدةٍ للجلسة: الحالةُ على القرص في مجلّدِ المستخدم وحدَه
     * (`ChunkedUpload::dir(auth id)`) — القرارُ موثَّق. الرمزُ المسكوكُ هو الجلسة.
     */
    public function uploadSession(Request $r): Response
    {
        $this->tagMobile($r);

        $data = $r->validate([
            'module'    => ['required', 'string', 'max:60'],
            'record_id' => ['required', 'string', 'max:36'],
            'filename'  => ['required', 'string', 'max:300'],
            'mime'      => ['nullable', 'string', 'max:160'],
            'size'      => ['nullable', 'integer', 'min:0'],
        ], [], ['filename' => 'اسم الملف', 'mime' => 'نوع المحتوى', 'size' => 'الحجم']);

        // حسابُ العميل: الرفعُ على سجلات وحدات سطحه فقط (منعٌ فوق المصفوفة · §28/§47)
        $this->guardClientModule($data['module']);

        // نقطةُ التخويلِ نفسُها التي يفرضها «الإتمام» — إخفاقٌ مبكّرٌ لا IDOR
        AttachmentService::guardRecord($data['module'], $data['record_id'], 'v');

        // حاجزُ الامتداد على الاسم المُعلَن — قبل أن يرفع العميلُ غيغابايتاً يُرفَض
        $ext = mb_strtolower((string) pathinfo($data['filename'], PATHINFO_EXTENSION));
        if (in_array($ext, AttachmentService::BLOCKED, true)) {
            return Api::error(Api::VALIDATION_FAILED, 422,
                'هذا النوع من الملفات غير مسموح: ' . Str::limit($data['filename'], 40));
        }

        // الحجمُ المُعلَن (إن وُجد) يُقاس بحدِّ النظام — سقفُ الملفّ المجمَّع لا سقفُ الطلب
        $cap = hub_upload_cap();
        if ((int) ($data['size'] ?? 0) > $cap['appKb'] * 1024) {
            return Api::error(Api::PAYLOAD_TOO_LARGE, 413,
                'حجمُ الملف يتجاوز الحدَّ المسموح على الخادم (' . hub_bytes($cap['appKb'] * 1024) . ')');
        }

        // رمزُ الجلسة (= الرمزُ المقطَّع) — الحالةُ على القرص في مجلّد المستخدم لا في القاعدة
        return $this->ok([
            'session'    => ChunkedUpload::token(),
            'chunk_size' => $cap['chunkAt'] * 1024,           // بايتاتٍ لكل قطعة (العميلُ يقطّع دونها)
            'max_parts'  => ChunkedUpload::MAX_PARTS,
            'max_bytes'  => $cap['appKb'] * 1024,             // سقفُ الملفّ المجمَّع
            'module'     => $data['module'],
            'record_id'  => $data['record_id'],
        ]);
    }

    /**
     * `PUT files/upload-session/{id}/chunk` — إلحاقُ قطعةٍ بالترتيب · F.1.
     *
     * نظيرُ `UploadChunkController::chunk` حرفاً (سكّةٌ واحدة): تحقّقُ الرمز
     * (`ChunkedUpload::validToken`) + الفهرس (`i` صفريّ، `< MAX_PARTS`) ثم
     * `ChunkedUpload::append($id,$i,$chunk, appKb)` — الحدُّ على المجموع حدُّ **النظام**
     * لا حدُّ الطلب الواحد (لهذا وُجد التقطيع). قطعةٌ خارج الدور تُرفض (٤٢٢، لا ثقبٌ
     * صامت)، والتجاوزُ يُلغي الرفعة (٤١٣). الرمزُ يُطالَب به من مجلّد صاحبه وحدَه
     * (`append` عبر `dir(auth id)`) — فلا يُستهلك رمزُ غيره ولو خُمِّن.
     */
    public function uploadChunk(Request $r, string $id): Response
    {
        $this->tagMobile($r);
        abort_unless(ChunkedUpload::validToken($id), 422, 'رمزُ رفعةٍ غير صالح');

        $d = $r->validate([
            'i'     => ['required', 'integer', 'min:0', 'max:' . (ChunkedUpload::MAX_PARTS - 1)],
            'chunk' => ['required', 'file'],
        ], [], ['i' => 'رقم القطعة', 'chunk' => 'القطعة']);

        $cap = hub_upload_cap();
        $res = ChunkedUpload::append($id, (int) $d['i'], $r->file('chunk'), (int) $cap['appKb']);

        if (! ($res['ok'] ?? false)) {
            // تجاوزُ الحدّ يُلغي الرفعة ⇒ PAYLOAD_TOO_LARGE؛ خارجُ الدور/تعذّرُ الكتابة ⇒ VALIDATION_FAILED
            $overCap = str_contains((string) ($res['msg'] ?? ''), 'تجاوز');

            return Api::error(
                $overCap ? Api::PAYLOAD_TOO_LARGE : Api::VALIDATION_FAILED,
                $overCap ? 413 : 422,
                (string) ($res['msg'] ?? 'تعذّر إلحاقُ القطعة')
            );
        }

        return $this->ok([
            'session'  => $id,
            'received' => (int) ($res['have'] ?? 0),   // البايتاتُ المتراكمة على القرص
            'next'     => (int) ($res['next'] ?? 0),    // رقمُ القطعة التالية المتوقَّعة
        ]);
    }

    /**
     * `POST files/upload-session/{id}/complete` — تجميعُ القطع وإرفاقُها · F.1.
     *
     * **Idempotency (مالكُ الجوال · F1) يُحجَز أوّلاً** فلا تُضاعِف إعادةُ الإتمام المرفقَ
     * (إعادةٌ بالمفتاح نفسِه ⇒ الردُّ المخزَّن). ثم: يُتحقَّق من اكتمال القطع
     * (`seen === parts`)، تُرفع `ResolveChunkedUploads::FLAG` (كي يرفع `hub_upload_cap`
     * سقفَ الطلب عن المجمَّع)، يُجمَّع بـ`claim` ⇒ `UploadedFile` يُحقَن في الطلب كـ`file`
     * (نظيرُ الوسيط حرفاً — مع إسقاط خبيئة الملفات المحوَّلة)، ثم يمرّ **بالجوهرِ المشترك
     * نفسِه**: `validateUpload` + `guardRecord(...,'v')` + `attach` — لا مسارَ جانبيّ.
     */
    public function uploadComplete(Request $r, string $id): Response
    {
        $this->tagMobile($r);
        abort_unless(ChunkedUpload::validToken($id), 422, 'رمزُ رفعةٍ غير صالح');

        // الحجزُ قبل أيِّ أثر: إعادةُ «الإتمام» بالمفتاح نفسِه لا تُرفِق مرّتين (F1)
        $gate = $this->idempotentBegin($r);
        if ($gate instanceof Response) return $gate;

        try {
            // اكتمالُ القطع (إن أعلن العميلُ عددها) — رفعةٌ ناقصةٌ لا تُرفَق
            $expected = (int) $r->input('parts', $r->input('n', 0));
            $seen = ChunkedUpload::seen($id);
            if ($expected > 0 && $seen !== $expected) {
                if ($gate === true) $this->idempotentRelease($r);

                return Api::error(Api::VALIDATION_FAILED, 422,
                    'وصل ' . $seen . ' من ' . $expected . ' قطعة — الرفعة ناقصة');
            }

            // رفعُ العلامةِ **قبل** التحقّق: كي يرى `hub_upload_cap['kb']` (في قاعدة `max:`)
            // أنّ سقفَ الطلب الواحد لم يعد قيداً على الملفّ المجمَّع
            $r->attributes->set(ResolveChunkedUploads::FLAG, true);

            $name = hub_str($r->input('filename', $r->input('name', 'ملف')));
            $file = ChunkedUpload::claim($id, $name);
            if (! $file) {
                if ($gate === true) $this->idempotentRelease($r);

                return Api::error(Api::VALIDATION_FAILED, 422, 'الرفعة غير مكتملةٍ أو غير موجودة');
            }

            // حقنُ الملفّ المجمَّع كـ`file` وإسقاطُ خبيئة الملفات المحوَّلة — نظيرُ
            // `ResolveChunkedUploads` حرفاً، كي يراه الجوهرُ المشترك ملفاً مرفوعاً عاديّاً
            $r->files->set('file', $file);
            (function () { $this->convertedFiles = null; })->call($r);

            // الجوهرُ المشترك — السكّةُ نفسُها التي يسلكها الويب في `store()` بعد الوسيط
            $data = AttachmentService::validateUpload($r);
            AttachmentService::guardRecord($data['module'], $data['record_id'], 'v');
            $files = AttachmentService::filesFromRequest($r);
            $made = AttachmentService::attach($data['module'], $data['record_id'], $files, $data);

            ChunkedUpload::forget($id);   // تنظيفُ القطع بعد الإرفاق الناجح
            hub_audit('إرفاق ملفٍ مقطَّعٍ عبر الجوال', $data['module'], $data['record_id'],
                (($made[0]->original_name ?? null) ?: 'مرفق') . ' — ' . count($made) . ' ملف');

            $resp = $this->ok($this->attachmentsPayload($data['module'], $data['record_id'], $made));
            $this->idempotentFinish($r, $resp);

            return $resp;
        } catch (\Throwable $e) {
            if ($gate === true) $this->idempotentRelease($r);
            throw $e;
        }
    }

    /**
     * `POST files/attach` — رفعٌ مفردٌ متعدّدُ الأجزاء (multipart) في طلبٍ واحد · F.1.
     *
     * الجوهرُ المشترك مباشرةً: `validateUpload` (module/record_id/kind/الحجم) +
     * `guardRecord(...,'v')` + `filesFromRequest` + `attach`. لا base64 ولا رابطٌ عامّ —
     * القرصُ الخاصّ `local` وحدَه. **Idempotency** (مالكُ الجوال · F1) على الإرفاق.
     */
    public function attach(Request $r): Response
    {
        $this->tagMobile($r);

        $gate = $this->idempotentBegin($r);
        if ($gate instanceof Response) return $gate;

        try {
            $data = AttachmentService::validateUpload($r);
            $this->guardClientModule($data['module']);   // سطحُ العميل فقط (§28/§47)
            AttachmentService::guardRecord($data['module'], $data['record_id'], 'v');
            $files = AttachmentService::filesFromRequest($r);
            $made = AttachmentService::attach($data['module'], $data['record_id'], $files, $data);

            hub_audit('إرفاق ملفٍ عبر الجوال', $data['module'], $data['record_id'],
                (($made[0]->original_name ?? null) ?: 'مرفق') . ' — ' . count($made) . ' ملف');

            $resp = $this->ok($this->attachmentsPayload($data['module'], $data['record_id'], $made));
            $this->idempotentFinish($r, $resp);

            return $resp;
        } catch (\Throwable $e) {
            if ($gate === true) $this->idempotentRelease($r);
            throw $e;
        }
    }

    // ═══════════════════════ F.2 · التنزيل/البثّ المُصادَق ═══════════════════════

    /**
     * `GET files/{id}/download` — تنزيلٌ مُصادَقٌ بترويسة attachment · F.2.
     *
     * الجوهرُ المشترك `AttachmentService::download`: `guardRecord(...,'v')` (وحدةٌ غيرُ
     * مرئيّة ⇒ ٤٠٣، وسجلٌّ خارجَ النطاق ⇒ `hub_scope->findOrFail` ٤٠٤ — **لا IDOR**) +
     * حاجزُ `av_status==='infected'` (٤٢٣) + عدّادٌ + سجلُّ تنزيلٍ + تدقيقُ الوصولِ
     * المصنَّف + ردُّ ملفٍّ بترويسة `Content-Disposition: attachment` (فملفُ HTML/SVG
     * مرفوعٌ لا يُنفَّذ). **لا رابطٌ عامّ** — البايتاتُ خلفَ بوّابةِ التخويلِ نفسِها.
     * `findOrFail` غيرُ الموجود/المحذوفِ ناعماً ⇒ ٤٠٤ (SoftDeletes على `Attachment`).
     */
    public function download(Request $r, string $id): Response
    {
        $this->tagMobile($r);

        $att = Attachment::findOrFail($id);
        // حسابُ العميل: مرفقُ وحدةٍ خارج سطحه 404 قبل أيّ بايت (منعٌ فوق المصفوفة)
        $this->guardClientModule((string) $att->module);

        return AttachmentService::download($att);
    }

    /**
     * `GET files/{id}/stream` — بثٌّ مُصادَقٌ للعرضِ داخلَ التطبيق · F.2.
     *
     * الجوهرُ المشترك `AttachmentService::stream` (نظيرُ `AttachmentController::preview`
     * حرفاً — سكّةٌ واحدة): حرّاسُ `download` نفسُها (guardRecord + حاجزُ الإصابة) +
     * حصرُ الأنواع على `INLINE_MIMES` (صورٌ نقطية + PDF؛ غيرُها ٤١٥ يُنزَّل) + ترويسة
     * `Content-Disposition: inline` مع `X-Content-Type-Options: nosniff` وCSP صارمة.
     * لا رابطٌ عامّ — نفسُ بوّابةِ التخويل. سجلٌّ خارجَ النطاق ⇒ ٤٠٤ (لا IDOR).
     */
    public function stream(Request $r, string $id): Response
    {
        $this->tagMobile($r);

        $att = Attachment::findOrFail($id);
        $this->guardClientModule((string) $att->module);   // سطحُ العميل فقط

        return AttachmentService::stream($att);
    }

    /**
     * سياجُ وحدةِ الملفّ لحساب العميل (تطبيق العميل · §28/§47): وحدةٌ خارج
     * `MobilePortalGuard::MODULE_ALLOW` ⇒ 404 (لا كشفَ وجود) — فوق `guardRecord`
     * لا بديلاً عنه (منعٌ فوق المصفوفة ولو منح دورٌ مُساءُ الضبط وحدةً داخلية).
     */
    private function guardClientModule(string $module): void
    {
        if (hub_is_client(auth()->user())
            && ! \App\Http\Middleware\MobilePortalGuard::clientModuleAllowed($module)) {
            abort(Api::error(Api::RESOURCE_NOT_FOUND, 404, 'غير موجود'));
        }
    }

    // ═══════════════════════ F.3 · الماسح (QR/سيريال) ═══════════════════════

    /**
     * `GET identity/resolve/{q}` — المحلّلُ الموحّد · F.3.
     *
     * إعادةُ استعمالِ `V1Controller::identityResolve` حرفاً (لا محرّكٌ ثانٍ). لا
     * `api_token` في الجوال ⇒ `tokenAllows` يمرّ فتُطبَّق صلاحيّاتُ المستخدم كاملةً
     * تحت `hub_can`، والنطاقُ داخلَ `Identity::resolve` — فلا يُكشَف سجلٌّ خارجَ نطاق
     * المستخدم. الحمولةُ معرِّفاتٌ لا حقولُ سجلٍّ (تفاصيلُه من مساره القياسيّ بحقول دوره).
     */
    public function identityResolve(string $q)
    {
        $this->tagMobile(request());

        return parent::identityResolve($q);
    }

    // ═══════════════════════ F.4 · الموقع (التتبّع الميدانيّ) ═══════════════════════

    /**
     * `POST tracking/start` — بدءُ جلسةِ تتبّعٍ بموافقةٍ صريحة · F.4.
     *
     * `V1Controller::trackStart` يفرض `consent=true` (لا تتبّعَ بلا إقرار) و`field_role`
     * (لأصحاب الدور الميدانيّ) عبر `fieldEmp()` ويُدقّق. **لا تتبّعٌ خفيٌّ دائم:**
     * الجلسةُ تُبدأ صراحةً وتُنهى صراحةً (`tracking/{session}/end`).
     */
    public function trackingStart(Request $r): Response
    {
        $this->tagMobile($r);

        return parent::trackStart($r);
    }

    /**
     * `POST tracking/{session}/points` — استيعابُ دفعةِ نقاطٍ · F.4.
     *
     * **Idempotency (مالكُ الجوال · F1)** حول `parent::trackIngest` فإعادةُ المحاولة
     * بالمفتاح نفسِه لا تُضاعِف النقاط. الجلسةُ لصاحبها حصراً (`emp_id`)، والدفعةُ
     * محدودةٌ (`Tracking::BATCH_MAX`)، والتكرارُ البنيويُّ والتسلسلُ يُحسمان في
     * `Tracking::ingest` — لا تتبّعٌ لجلسةِ غيرِ المستخدم.
     */
    public function trackingPoints(Request $r, string $session): Response
    {
        $this->tagMobile($r);

        $gate = $this->idempotentBegin($r);
        if ($gate instanceof Response) return $gate;

        try {
            $resp = parent::trackIngest($r, $session);
            $this->idempotentFinish($r, $resp);

            return $resp;
        } catch (\Throwable $e) {
            if ($gate === true) $this->idempotentRelease($r);
            throw $e;
        }
    }

    /**
     * `POST tracking/{session}/end` — إنهاءُ الجلسةِ صراحةً · F.4.
     *
     * `V1Controller::trackEnd` يبسّط المسارَ ويحسب المسافة، ويُدقّق. الإنهاءُ الصريحُ
     * هو ما يمنع التتبّعَ الدائمَ الخفيّ (بندُ المهمّة).
     */
    public function trackingEnd(Request $r, string $session): Response
    {
        $this->tagMobile($r);

        return parent::trackEnd($r, $session);
    }

    // ═══════════════════════════ مساعِداتٌ داخلية ═══════════════════════════

    /**
     * غلافُ المرفقات المُنشأة — حقولٌ آمنةٌ للعميل (لا مسارُ قرصٍ ولا رابطٌ عامّ). حقلا
     * `download`/`stream` مساراتٌ **نسبيّة** لنقاطٍ مُصادَقة (تُطلَب برمز الجلسة) لا روابطَ
     * عامّة — نظيرُ عقدِ «لا رابطٌ عامّ» في المرفقات كلِّها.
     *
     * @param  Attachment[] $made
     */
    private function attachmentsPayload(string $module, string $recordId, array $made): array
    {
        return [
            'module'    => $module,
            'record_id' => $recordId,
            'count'     => count($made),
            'files'     => array_map(fn (Attachment $a) => [
                'id'            => (string) $a->id,
                'original_name' => $a->original_name,
                'mime'          => $a->mime,
                'size'          => (int) $a->size,
                'checksum'      => $a->checksum,
                'kind'          => $a->kind,
                'created_at'    => optional($a->created_at)->toIso8601String(),
                'download'      => route('mobile.files.download', ['id' => $a->id], false),
                'stream'        => route('mobile.files.stream', ['id' => $a->id], false),
            ], array_values($made)),
        ];
    }

    /** غلافُ نجاحٍ موحَّد: `data` + `request_id` (X-API-Version من `MobileSessionAuth`) */
    private function ok(array $data): JsonResponse
    {
        return response()->json(['data' => $data, 'request_id' => Api::requestId()], 200);
    }

    /** وسمُ مصدرِ الطلب `mobile` — يقرؤه `hub_audit` عبر `Api::requestSource` (وسمٌ لا تخويل) */
    private function tagMobile(Request $r): void
    {
        $r->attributes->set('request_source', 'mobile');
    }
}
