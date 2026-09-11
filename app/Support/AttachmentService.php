<?php

namespace App\Support;

use App\Models\Attachment;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * **جوهرُ المرفقات المشترك** — Mobile Readiness · الطور F · F.2 (نمطُ الجواهرِ
 * المشتركة: `CommentService`/`DmService`/`ApprovalService`).
 *
 * كان منطقُ الرفع والتنزيل موصولاً بطبقةِ ردِّ الويب في `AttachmentController`:
 * `store()` تُعيد `back()->with('ok',…)` (تحويلةٌ لا تصلح للجوال)، و`download()`
 * تُعيد ردَّ ملفٍّ لكنّها تُدقّق وتُسجّل وتُدرِج داخلَها. فاستُخرج هنا **الجوهرُ
 * المعيدُ للبيانات** كي يستدعيه **الويبُ والجوالُ معاً** بلا ازدواج (Critic F2):
 * التحقّقُ، وقائمةُ الكتابة البيضاء، والبصمةُ (sha256)، وحاجزُ الامتداد، وحارسُ
 * السجل (`guardRecord`) — سكّةٌ واحدة، فلا يعيد الجوالُ كتابةَ أيٍّ منها.
 *
 * **العقودُ المحفوظة (الويبُ يبقى حرفاً بحرف):**
 *  • القرصُ الخاصُّ `local` وحدَه (`store('hub/att','local')`) — لا رابطَ عامّ ولا base64.
 *  • البصمةُ `hash_file('sha256')`، والـmime/الحجم/`uploaded_by` من الخادم لا من العميل.
 *  • الصفُّ يُبنى بقائمةٍ بيضاءَ صريحة (Attachment مُتاحُ الإسناد `$guarded=['id']` —
 *    فالأمانُ في هذا المصفوف اليدويّ لا في النموذج · INVENTORY §5).
 *  • `guardRecord(...,'v')` نقطةُ التخويلِ الوحيدة (رؤيةُ الوحدة + نطاقُ السجل ⇒
 *    سجلٌّ خارج النطاق = ٤٠٤، وحدةٌ غيرُ مرئيّة = ٤٠٣) — لا IDOR.
 *  • المصابُ (`av_status='infected'`) لا يُقدَّم (٤٢٣)، والوصولُ المصنَّفُ يُدقَّق.
 *
 * التحقّقُ يرمي `ValidationException` فيترجمها الإطارُ آليّاً: الويبُ ⇒ رجوعٌ بأخطاءِ
 * جلسة، و`Api::render` ⇒ `VALIDATION_FAILED` — فلا فرعَ خاصّ لأيّ سطح.
 */
class AttachmentService
{
    /**
     * امتداداتٌ تُرفض مهما كان الإعداد — موحَّدةٌ على **الأشدّ** (نظيرُ حقولِ الوحدات).
     * (نُقلت من `AttachmentController` لتصير مصدرَ الحقيقةِ الواحد لكلا السطحَين.)
     */
    public const BLOCKED = ['php', 'php3', 'php4', 'php5', 'php7', 'php8', 'phtml', 'phar', 'cgi', 'pl', 'sh', 'htaccess',
        'html', 'htm', 'xhtml', 'svg', 'svgz', 'js', 'mjs'];

    /** أقصى ملفاتٍ في رفعةٍ واحدة — لقطاتُ متجرٍ لثلاث منصّاتٍ لا تتجاوزها */
    public const BATCH_MAX = 20;

    /**
     * أنواعٌ تُعاين/تُبثّ حيّاً داخل العميل — صورٌ نقطية وPDF فقط؛ SVG/HTML تبقى تنزيلاً
     * (قد تحمل سكربتات). مصدرُ الحقيقةِ الواحد لكلا السطحَين (`AttachmentController::INLINE_MIMES`
     * يشير إليه، ومعه بوّابةُ ملفات الوحدات وغرفةُ البيانات) — سياسةٌ واحدةٌ لا نسختان.
     */
    public const INLINE_MIMES = ['image/png', 'image/jpeg', 'image/gif', 'image/webp', 'image/bmp', 'image/avif', 'application/pdf'];

    /**
     * **التحقّقُ من طلبِ رفع** (مفردٌ أو دفعةٌ + وصفُ الوثيقة) — القواعدُ حرفاً بحرف
     * كما كانت في `AttachmentController::store`. `kind` مقيَّدٌ بمفاتيحِ ملفِّ الوثائق
     * للوحدة (`hub_doc_spec`) — مفتاحٌ معلنٌ لا نصٌّ حر. يعيد المصفوفَ المُتحقَّق.
     */
    public static function validateUpload(Request $r): array
    {
        return $r->validate([
            'module'    => ['required', 'string', 'max:60'],
            'record_id' => ['required', 'string', 'max:36'],
            // أحدُهما يكفي: المفردُ أو الدفعة — والتحقق على كل ملفٍ في الدفعة
            'file'      => ['required_without:files', 'nullable', 'file', 'max:' . hub_upload_cap()['kb']],
            'files'     => ['required_without:file', 'nullable', 'array', 'max:' . self::BATCH_MAX],
            'files.*'   => ['file', 'max:' . hub_upload_cap()['kb']],
            'note'      => ['nullable', 'string', 'max:300'],
            'kind'       => ['nullable', 'string', 'max:40',
                             Rule::in(collect(hub_doc_spec(hub_str($r->input('module'))))->pluck('key')->all())],
            'doc_no'     => ['nullable', 'string', 'max:80'],
            'issued_at'  => ['nullable', 'date'],
            'expires_at' => ['nullable', 'date'],
        ], [], [
            'note' => 'الملاحظة', 'kind' => 'نوع الوثيقة', 'doc_no' => 'رقم الوثيقة',
            'issued_at' => 'تاريخ الإصدار', 'expires_at' => 'تاريخ الانتهاء',
        ]);
    }

    /**
     * قائمةُ الملفات من الطلب — المفردُ والدفعةُ في مسارٍ واحد (بترتيب اختيارها)،
     * والمقطَّعُ يكون قد حُقن في `files` بوسيط `ResolveChunkedUploads` قبل هذا.
     * يرمي ٤٢٢ إن خلا الطلبُ من ملف (شبكةُ أمانٍ خلفَ التحقّق الذي يمسكها أوّلاً).
     *
     * @return \Illuminate\Http\UploadedFile[]
     */
    public static function filesFromRequest(Request $r): array
    {
        $files = $r->hasFile('files') ? array_values(array_filter((array) $r->file('files'))) : [];
        if ($r->hasFile('file')) array_unshift($files, $r->file('file'));
        abort_if(! $files, 422, 'لا ملف في الطلب');

        return array_slice($files, 0, self::BATCH_MAX);
    }

    /**
     * **إرفاقُ ملفاتٍ على سجل** — الجوهرُ المشترك: حاجزُ الامتداد ⇒ التخزينُ على القرص
     * الخاصّ ⇒ الصفُّ بقائمةٍ بيضاءَ يدويّة (بصمةٌ + mime/حجم/رافعٌ من الخادم) ⇒ إبطالُ
     * خبيئةِ «ينتهي قريباً» عند وثيقةٍ مؤرَّخة. يعيد المرفقاتِ المُنشأة (بترتيب الوصول).
     *
     * **الحارسُ يُنادى قبل هذا** (`guardRecord(...,'v')`) — هذا الجوهرُ لا يخوّل، يكتب.
     *
     * @param  \Illuminate\Http\UploadedFile[] $files
     * @param  array $meta note/kind/doc_no/issued_at/expires_at (من `validateUpload`)
     * @return Attachment[]
     */
    public static function attach(string $module, string $recordId, array $files, array $meta = []): array
    {
        // **الترتيبُ يتبع الوصول**: اللقطةُ الجديدة تُذيَّل ولا تقفز إلى الصدارة
        $sort = (int) Attachment::where('module', $module)
            ->where('record_id', $recordId)->max('sort');

        $made = [];
        foreach ($files as $f) {
            $ext = mb_strtolower((string) $f->getClientOriginalExtension());
            abort_if(in_array($ext, self::BLOCKED, true), 422,
                'هذا النوع من الملفات غير مسموح: ' . Str::limit((string) $f->getClientOriginalName(), 40));

            $path = $f->store('hub/att', 'local');

            $made[] = Attachment::create([
                'module'        => $module,
                'record_id'     => $recordId,
                'note'          => ($meta['note'] ?? null) ?: null,
                'kind'          => ($meta['kind'] ?? null) ?: null,
                'doc_no'        => ($meta['doc_no'] ?? null) ?: null,
                'issued_at'     => ($meta['issued_at'] ?? null) ?: null,
                'expires_at'    => ($meta['expires_at'] ?? null) ?: null,
                'disk'          => 'local',
                'path'          => $path,
                'original_name' => Str::limit((string) $f->getClientOriginalName(), 290, ''),
                'mime'          => substr((string) $f->getMimeType(), 0, 160),
                'size'          => (int) $f->getSize(),
                'checksum'      => hash_file('sha256', $f->getRealPath()) ?: null,
                'sort'          => ++$sort,
                'uploaded_by'   => auth()->id(),
            ]);
        }

        // وثيقةٌ لها مدّة تدخل رادار «ينتهي قريباً» فوراً لا بعد انقضاء المخبأ
        if (($made[0] ?? null) && $made[0]->expires_at) hub_expiry_bust();

        return $made;
    }

    /**
     * **تنزيلُ مرفقٍ** — الجوهرُ المشترك: حارسٌ (v) ⇒ حاجزُ الإصابة ⇒ وجودُ الملف ⇒
     * عدّادٌ + سجلُّ تنزيلٍ + تدقيقُ الوصولِ المصنَّف ⇒ ردُّ ملفٍّ بترويسة
     * `Content-Disposition: attachment` (فملفُ HTML/SVG مرفوعٌ لا يُنفَّذ في المتصفح).
     * لا رابطَ عامّ — كلُّ بايتٍ يمرّ بهذا الحاجز.
     */
    public static function download(Attachment $a): \Symfony\Component\HttpFoundation\BinaryFileResponse
    {
        self::guardRecord($a->module, $a->record_id, 'v');
        // طبقةُ الوثيقةِ على مستوى المورد (المستوى 5/6): منعٌ صريحٌ لهذه الوثيقةِ ⇒ ٤٠٣
        DocumentPolicy::authorize(auth()->user(), $a, 'download');

        // مرفقٌ وُسم «مصاباً» بأداةٍ خارجية يُحجب فوراً — 423 Locked: محجوز لا مفقود
        abort_if($a->av_status === 'infected', 423, 'حُجب هذا الملف — وُسم مصاباً بفحص الفيروسات');

        $abs = Storage::disk($a->disk ?: 'local')->path($a->path);
        abort_unless(is_file($abs), 404, 'الملف غير موجود على القرص');

        $a->increment('downloads');
        DB::table('download_log')->insert([
            'attachment_id' => $a->id, 'user_id' => auth()->id(),
            'ip' => request()->ip(), 'device' => substr((string) request()->userAgent(), 0, 200),
            'created_at' => now(),
        ]);

        // تنزيلُ مرفقٍ على سجلٍّ مصنَّفٍ «سري» حدثُ وصولٍ حسّاسٍ يدخل سلسلةَ التدقيق
        self::auditClassifiedAccess($a);

        return response()->download($abs, $a->original_name ?: basename($a->path));
    }

    /**
     * **بثُّ مرفقٍ للعرضِ الحيّ** — الجوهرُ المشترك (نظيرُ `download`): حارسٌ (v) ⇒ حاجزُ
     * الإصابة ⇒ حصرُ الأنواع على `INLINE_MIMES` (وإلا ٤١٥ «يُنزَّل ولا يُعاين») ⇒ وجودُ
     * الملف ⇒ سجلُّ معاينةٍ + تدقيقُ الوصولِ المصنَّف ⇒ ردُّ ملفٍّ بترويسة
     * `Content-Disposition: inline` مع `nosniff` وCSP صارمة (فصورةٌ/PDF تُعرض بلا تنفيذ).
     * لا رابطَ عامّ — كلُّ بايتٍ يمرّ بهذا الحاجز. يستدعيه الويبُ (`preview`) والجوالُ
     * (`stream`) معاً — نسخةٌ واحدةٌ لا نسختان (Critic F2).
     */
    public static function stream(Attachment $a): \Symfony\Component\HttpFoundation\Response
    {
        self::guardRecord($a->module, $a->record_id, 'v');
        // نفسُ طبقةِ الوثيقةِ على المعاينة (نظيرُ التنزيل): منعٌ صريحٌ ⇒ ٤٠٣ — لا معاينةَ تتجاوز
        DocumentPolicy::authorize(auth()->user(), $a, 'preview');

        abort_if($a->av_status === 'infected', 423, 'حُجب هذا الملف — وُسم مصاباً بفحص الفيروسات');
        // SVG/HTML مرفوعٌ لا يُعاين حيّاً (قد يحمل سكربتاً) — يُنزَّل attachment فلا يُنفَّذ
        abort_unless(in_array($a->mime, self::INLINE_MIMES, true), 415, 'هذا النوع يُنزَّل ولا يُعاين');

        $abs = Storage::disk($a->disk ?: 'local')->path($a->path);
        abort_unless(is_file($abs), 404, 'الملف غير موجود على القرص');

        DB::table('download_log')->insert([
            'attachment_id' => $a->id, 'user_id' => auth()->id(),
            'ip' => request()->ip(), 'device' => substr('معاينة · ' . request()->userAgent(), 0, 200),
            'created_at' => now(),
        ]);
        self::auditClassifiedAccess($a);   // المعاينةُ/البثُّ وصولٌ كالتنزيل (v2.399)

        return response()->file($abs, [
            'Content-Type'            => $a->mime,
            'X-Content-Type-Options'  => 'nosniff',
            'Content-Security-Policy'  => "default-src 'none'; style-src 'unsafe-inline'",
            'Cache-Control'           => 'private, max-age=300',
        ]);
    }

    /**
     * **نقطةُ التخويلِ الوحيدة:** الهدفُ موجودٌ، والوحدةُ مرئيّةٌ للمستخدم، والسجلُّ ضمن
     * نطاقه (`hub_scope(...)->findOrFail` ⇒ خارج النطاق = ٤٠٤). لا يُوثَق بأيِّ نطاقٍ
     * يرسله العميل — النطاقُ من `auth()->user()` وحدَه.
     */
    public static function guardRecord(?string $module, ?string $recordId, string $op): void
    {
        $def = hub_mod((string) $module);
        abort_unless($def && $recordId, 404);
        abort_unless(hub_can(auth()->user(), $module, $op), 403);
        $class = '\\App\\Models\\' . $def['model'];
        hub_scope($class::query(), $module)->findOrFail($recordId);
    }

    /**
     * يُدوّن وصولاً لبياناتٍ مصنَّفة إن كان السجلُّ الأمّ يحمل حقلَ سرّيةٍ مُقيَّداً.
     * يقرأ الحقلَ من تعريف الوحدة (أيُّ حقلٍ اسمُه `secrecy`)، فلا يُخصّ وحدةً بعينها.
     */
    public static function auditClassifiedAccess(Attachment $a): void
    {
        try {
            $def = hub_mod((string) $a->module);
            if (! $def) return;
            $sf = collect($def['fields'] ?? [])->firstWhere('col', 'secrecy');
            if (! $sf) return;
            $class = '\\App\\Models\\' . ($def['model'] ?? '');
            if (! class_exists($class)) return;
            $val = (string) ($class::whereKey($a->record_id)->value('secrecy') ?? '');
            if (in_array($val, ['سري', 'مقيّد', 'restricted', 'confidential'], true)) {
                hub_audit('وصول لبيانات مصنَّفة', $a->module, $a->record_id,
                    ($a->original_name ?: 'مرفق') . " — تصنيف: {$val}");
            }
        } catch (\Throwable $e) {
            // التصنيفُ إثراءٌ للتدقيق لا يكسر تنزيلاً
        }
    }
}
