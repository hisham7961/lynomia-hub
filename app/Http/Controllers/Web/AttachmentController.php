<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\Attachment;
use App\Support\AttachmentService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * المرفقات الشاملة: أي سجل من أي وحدة يقبل ملفات — عقد على شركة، إيصال على
 * مصروف، تصميم على مهمة. التنزيل بهوية المستخدم وصلاحية رؤية الوحدة، ويُسجَّل.
 */
class AttachmentController extends Controller
{
    /**
     * أقصى ملفاتٍ في رفعةٍ واحدة — لقطاتُ متجرٍ لثلاث منصّاتٍ لا تتجاوزها.
     * يشير إلى مصدر الحقيقة الواحد في `AttachmentService` (يقرؤه القالبُ أيضاً)
     * كي لا يفترق حاجزُ الويب عن جوهرِ الجوال المشترك (F.2). وحاجزُ الامتدادات
     * ونطاقُ التحقّق كلُّها هناك أيضاً — سكّةٌ واحدة لا نسختان.
     */
    public const BATCH_MAX = AttachmentService::BATCH_MAX;

    /**
     * الرفع — **ملفٌ واحدٌ أو عدّة**.
     *
     * كان الحقل ملفاً واحداً في كل مرة، ولقطاتُ المتجر تُرفع ثمانياً وعشراً:
     * فتُكرَّر الدورةُ كلُّها (اختيار ← نوع الوثيقة ← إرفاق ← انتظار) لكل صورة،
     * ومن ملّ في السادسة ترك النصف. الآن `files[]` تقبل الدفعة، ويبقى `file`
     * المفرد يعمل بحذافيره (نماذجُ قديمة وAPI ومسارات أخرى تبعث به).
     */
    public function store(Request $r)
    {
        // التحقّقُ + حاجزُ الامتداد + قائمةُ الكتابة البيضاء + البصمة كلُّها في الجوهرِ
        // المشترك `AttachmentService` (F.2) — الويبُ والجوالُ يستدعيانه، لا نسختان.
        $data = AttachmentService::validateUpload($r);

        // الإرفاق بصلاحية «عرض» قرارٌ منتجيّ مقصود ومُختبَر (AttachmentsTest):
        // مشاهدٌ قد يُرفق مستنداً داعماً على سجلٍ يراه. الحمايةُ الحقيقية أن
        // الملف يمرّ بحاجز الامتدادات، والتنزيل يُجبَر attachment، والمعاينة
        // تحصر أنواعها — فلا تنفيذ. (لم نكسر سلوكاً قائماً لأجل تشدّدٍ نظريّ.)
        AttachmentService::guardRecord($data['module'], $data['record_id'], 'v');

        // الدفعةُ بترتيب اختيارها، والمفردُ دفعةٌ من واحد — مسارٌ واحدٌ لا مساران
        // (المقطَّعُ يكون قد حُقن في `files` بوسيط ResolveChunkedUploads قبل هذا)
        $files = AttachmentService::filesFromRequest($r);
        $made = AttachmentService::attach($data['module'], $data['record_id'], $files, $data);

        $a = $made[0];
        $n = count($made);
        $label = $a->kind ? (hub_doc_label($a->module, $a->kind) ?? '') : null;

        return back()->with('ok', $n > 1
            ? '📎 أُرفق ' . $n . ' ملفاً' . ($label ? ' — ' . $label : '') . ' بترتيب اختيارها'
            : ($label ? 'أُرفقت الوثيقة: ' . $label : 'أُرفق الملف'))
            ->withFragment('att-' . $a->id);
    }

    /**
     * تحريكُ مرفقٍ في الترتيب — **بالتبديل مع جاره** لا بإعادة ترقيم الكل.
     * ترتيبُ اللقطات هو العرضُ نفسه: الأولى هي ما يراه المستخدم في المتجر.
     */
    public function move(Request $r, string $id)
    {
        $a = Attachment::findOrFail($id);
        $this->guardRecord($a->module, $a->record_id, 'v');
        abort_unless($a->uploaded_by === auth()->id() || hub_is_owner()
            || hub_can(auth()->user(), $a->module, 'e'), 403, 'الترتيب لمن يملك تعديل الوحدة');

        $up = $r->input('dir') !== 'down';

        // الجارُ في اتجاه الحركة: ترتيبٌ أصغر (صعوداً) أو أكبر (نزولاً)، وعند
        // تساوي `sort` (مرفقاتٌ قديمةٌ كلُّها صفر) يفصل تاريخُ الإنشاء ثم المفتاح.
        $peer = Attachment::where('module', $a->module)->where('record_id', $a->record_id)
            ->where('id', '!=', $a->id)
            ->where(fn ($w) => $up
                ? $w->where('sort', '<', $a->sort)
                    ->orWhere(fn ($e) => $e->where('sort', $a->sort)->where('id', '<', $a->id))
                : $w->where('sort', '>', $a->sort)
                    ->orWhere(fn ($e) => $e->where('sort', $a->sort)->where('id', '>', $a->id)))
            ->orderBy('sort', $up ? 'desc' : 'asc')
            ->orderBy('id', $up ? 'desc' : 'asc')
            ->first();

        if (! $peer) return back()->with('ok', $up ? 'هي الأولى أصلاً' : 'هي الأخيرة أصلاً');

        // تساوي القيم يجعل التبديل بلا أثر — تُفكّ العقدة بترقيمٍ صريح
        $mine = (int) $a->sort;
        $theirs = (int) $peer->sort;
        if ($mine === $theirs) { $mine = $up ? $theirs + 1 : $theirs - 1; }

        $a->forceFill(['sort' => $theirs])->save();
        $peer->forceFill(['sort' => $mine])->save();

        return back()->with('ok', $up ? '⬆ قُدِّمت' : '⬇ أُخِّرت')->withFragment('shots');
    }

    public function download(string $id)
    {
        // الجوهرُ المشترك (F.2): حارسٌ + حاجزُ الإصابة + عدّاد + سجلُّ تنزيلٍ + تدقيقُ
        // الوصولِ المصنَّف + ردُّ ملفٍّ بترويسة attachment — الويبُ والجوالُ يستدعيانه.
        return AttachmentService::download(Attachment::findOrFail($id));
    }

    /**
     * **تنزيلُ مرفقات السجل كلِّها في ملفٍّ واحد.**
     *
     * سجلٌّ عليه اثنا عشر مرفقاً (هويّةٌ بصرية، وعقد، وتصاميم) كان يُنزَّل ضغطةً
     * ضغطةً — ونافذةُ التنزيل تسأل عن كلٍّ منها. الحزمةُ تُبنى بالأسماء الأصلية
     * كما رُفعت، ويُسجَّل كلُّ ملفٍّ فيها في سجل التنزيل كما لو نُزّل وحده:
     * حزمةٌ تُخرج اثني عشر ملفاً لا يجوز أن تظهر في الأثر تنزيلاً واحداً.
     *
     * والمصابُ لا يدخل الحزمة (حاجزُ التنزيل نفسه)، والصلاحيةُ صلاحيةُ السجل.
     */
    public function zip(string $module, string $recordId)
    {
        $this->guardRecord($module, $recordId, 'v');

        // خادمٌ بلا امتداد zip: **سطرٌ في مكان الزرّ لا صفحةُ خطأ** — الصفحة تعمل
        // وبقيّةُ المرفقات تُنزَّل فرادى، فلا يُقطع المستخدم عمّا جاء له.
        if (! class_exists(\ZipArchive::class)) {
            return back()->with('err',
                'ضغطُ الملفات غير متاح على هذا الخادم (امتداد zip غير مثبَّت) — نزّل المرفقات فرادى بزرّ «تحميل»');
        }

        // `!= 'infected'` وحدها تُسقط صفوف NULL صامتةً على المحرّكين — ومرفقٌ
        // لم يُفحص بعد مرفقٌ سليمٌ حتى يُوسم، فلا يُحذف من الحزمة بلا سبب.
        $items = Attachment::where('module', $module)->where('record_id', $recordId)
            ->where(fn ($w) => $w->whereNull('av_status')->orWhere('av_status', '!=', 'infected'))
            ->orderBy('created_at')->orderBy('id')->get();
        abort_if($items->isEmpty(), 404, 'لا مرفقات في هذا السجل');

        $dir = storage_path('app/hub/tmp');
        if (! is_dir($dir)) @mkdir($dir, 0775, true);
        $this->pruneTmp($dir);
        $tmp = $dir . '/att-' . Str::random(24) . '.zip';

        $zip = new \ZipArchive;
        abort_unless($zip->open($tmp, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) === true,
            500, 'تعذّر إنشاء ملف الحزمة');

        $used = [];
        $packed = [];
        foreach ($items as $a) {
            // طبقةُ الوثيقةِ على المورد: وثيقةٌ ممنوعةٌ صراحةً لهذا المستخدمِ لا تدخلُ الحزمةَ
            // (فلا يلتفُّ التنزيلُ الجماعيُّ على منعٍ فرديّ · المستوى 5/6)
            if (! \App\Support\DocumentPolicy::allows(auth()->user(), $a, 'download')) continue;
            $abs = Storage::disk($a->disk ?: 'local')->path($a->path);
            if (! is_file($abs)) continue;                      // ملفٌ مفقودٌ على القرص لا يُسقط الحزمة كلها

            // اسمان متطابقان داخل الحزمة: الثاني يدهس الأول صامتاً — يُرقَّم
            $name = $this->zipEntryName((string) ($a->original_name ?: basename($a->path)), $used);
            $zip->addFile($abs, $name);
            $packed[] = $a;
        }
        $zip->close();

        abort_if(! $packed, 404, 'مرفقات هذا السجل غير موجودة على القرص');

        foreach ($packed as $a) $a->increment('downloads');
        self::auditClassifiedAccess($packed[0]);   // الحزمةُ وصولٌ للسجل المصنَّف كلِّه (v2.399)
        DB::table('download_log')->insert(collect($packed)->map(fn ($a) => [
            'attachment_id' => $a->id, 'user_id' => auth()->id(),
            'ip' => request()->ip(),
            'device' => substr('حزمة ZIP · ' . request()->userAgent(), 0, 200),
            'created_at' => now(),
        ])->all());

        $label = (string) (hub_scope(('\\App\\Models\\' . hub_mod($module)['model'])::query(), $module)
            ->whereKey($recordId)->value(hub_display_col($module)) ?: $recordId);

        return response()->download($tmp, $this->safeZipName($label))->deleteFileAfterSend(true);
    }

    /**
     * حزمةٌ لم تُرسَل تبقى على القرص: `deleteFileAfterSend` تُنفَّذ **بعد** إتمام
     * الإرسال وحده — واتصالٌ انقطع في منتصف تنزيلٍ يترك ملفاً كاملاً وراءه.
     * ساعةٌ تكفي أطولَ تنزيلٍ معقول، وما فوقها يُكنَس عند الحزمة التالية.
     */
    protected function pruneTmp(string $dir): void
    {
        foreach (glob($dir . '/att-*.zip') ?: [] as $old) {
            if (is_file($old) && filemtime($old) < time() - 3600) @unlink($old);
        }
    }

    /** اسمٌ فريدٌ داخل الحزمة (تكرارُ الاسم يدهس ملفاً بصمت) */
    protected function zipEntryName(string $name, array &$used): string
    {
        $name = preg_replace('#[\\\\/:*?"<>|\x00-\x1F]#u', '-', trim($name)) ?: 'ملف';
        $name = hub_fit($name, 120);
        $try = $name;
        for ($i = 2; isset($used[mb_strtolower($try)]); $i++) {
            $ext = (string) pathinfo($name, PATHINFO_EXTENSION);
            $stem = $ext !== '' ? mb_substr($name, 0, -1 * (mb_strlen($ext) + 1)) : $name;
            $try = $stem . " ({$i})" . ($ext !== '' ? '.' . $ext : '');
        }
        $used[mb_strtolower($try)] = true;

        return $try;
    }

    protected function safeZipName(string $label): string
    {
        $label = preg_replace('#[\\\\/:*?"<>|\x00-\x1F]#u', '-', trim($label)) ?: 'مرفقات';

        return hub_fit('مرفقات — ' . $label, 100) . '.zip';
    }

    /** أنواع تُعاين حيّاً داخل المتصفح — صور نقطية وPDF فقط؛ SVG/HTML تبقى تنزيلاً (قد تحمل سكربتات) */
    // عامة: بوابة ملفات الوحدات وغرفة البيانات تتبعان السياسة نفسها — تعريفٌ واحد.
    // مصدرُ الحقيقة الآن في `AttachmentService` (سكّةٌ واحدة للويب والجوال · F.2)؛ يبقى الاسمُ
    // هنا كي تظلّ `FileController`/`DataRoomController`/`preview` تشير إليه دون تغيير.
    public const INLINE_MIMES = AttachmentService::INLINE_MIMES;

    /**
     * معاينة حية: الصورة/الشهادة/اللوجو تُعرض مصغّرةً وكاملةً دون تنزيل،
     * وPDF يفتح في عارض المتصفح. بهوية المستخدم وصلاحيته نفسها، ويُسجَّل الاطلاع.
     *
     * الجوهرُ المشترك (F.2): حارسٌ + حاجزُ الإصابة + حصرُ الأنواع + سجلُّ معاينةٍ + تدقيقٌ
     * + ردُّ ملفٍّ inline بترويسات أمانٍ — الويبُ والجوالُ (`stream`) يستدعيانه، لا نسختان.
     */
    public function preview(string $id)
    {
        return AttachmentService::stream(Attachment::findOrFail($id));
    }

    /** الحذف: من رفعه، أو من يملك تعديل الوحدة، أو المالك — ويُدوَّن في التدقيق */
    public function destroy(string $id)
    {
        $a = Attachment::findOrFail($id);
        $u = auth()->user();
        abort_unless(
            $a->uploaded_by === $u->id || hub_is_owner($u) || hub_can($u, $a->module, 'e'),
            403, 'حذف المرفق لمن رفعه أو من يملك تعديل الوحدة'
        );
        $this->guardRecord($a->module, $a->record_id, 'v');

        $a->delete();   // حذف ناعم — الملف يبقى على القرص للاستعادة
        // مرفقٌ مؤرَّخٌ حُذف: يخرج من رادار «ينتهي قريباً» وعدّاد شارة التنبيهات —
        // كان الرفعُ يُبطل الخبيئة والحذفُ لا، فيبقى المحذوفُ في الرادار حتى انتهائها
        if ($a->expires_at) hub_expiry_bust();

        hub_audit('حذف مرفق', $a->module, $a->record_id, (string) $a->original_name);

        return back()->with('ok', 'حُذف المرفق');
    }

    /**
     * **ضبطُ قاعدةِ وصولٍ لوثيقةٍ بعينها** (المستوى 5/6): سماحٌ/منعٌ لدورٍ أو مستخدم،
     * مربوطٌ بمعرِّفِ المرفقِ المستقرّ (لا باسم الملف). للمالكِ أو من يملكُ تعديلَ وحدةِ السجل.
     */
    public function access(Request $r, string $id)
    {
        $a = Attachment::findOrFail($id);
        $u = auth()->user();
        abort_unless(hub_is_owner($u) || hub_can($u, $a->module, 'e'), 403,
            'ضبطُ وصولِ الوثيقةِ للمالكِ أو من يملكُ تعديلَ وحدتها');
        // النطاقُ يسري: لا تُضبط وثيقةُ سجلٍّ خارجَ صلاحيتك (٤٠٤ لا إثباتَ وجود)
        $this->guardRecord($a->module, $a->record_id, 'v');

        $d = $r->validate([
            'principal_type' => 'required|in:role,user',
            'principal_id'   => 'required|string',
            'effect'         => 'required|in:allow,deny',
            'action'         => 'nullable|in:*,download,preview,view',
            'note'           => 'nullable|string|max:300',
        ]);

        DB::table('document_access_rules')->updateOrInsert(
            ['resource_type' => 'attachment', 'resource_id' => $a->id,
             'principal_type' => $d['principal_type'], 'principal_id' => $d['principal_id'],
             'action' => $d['action'] ?? '*'],
            ['effect' => $d['effect'], 'note' => $d['note'] ?? null,
             'created_by' => $u->id, 'updated_at' => now(), 'created_at' => now()],
        );
        \App\Support\DocumentPolicy::forget((string) $a->id);
        hub_audit('ضبط وصول وثيقة', $a->module, $a->record_id, (string) $a->original_name,
            ['after' => ['doc' => $a->id, $d['principal_type'] => $d['principal_id'], 'effect' => $d['effect']]]);

        return back()->with('ok', $d['effect'] === 'deny' ? 'مُنع الوصولُ لهذه الوثيقة' : 'سُمح الوصولُ لهذه الوثيقة');
    }

    /** حذفُ قاعدةِ وصولِ وثيقة */
    public function accessClear(Request $r, string $id, string $ruleId)
    {
        $a = Attachment::findOrFail($id);
        $u = auth()->user();
        abort_unless(hub_is_owner($u) || hub_can($u, $a->module, 'e'), 403);
        DB::table('document_access_rules')->where('id', $ruleId)
            ->where('resource_type', 'attachment')->where('resource_id', $a->id)->delete();
        \App\Support\DocumentPolicy::forget((string) $a->id);
        hub_audit('حذف قاعدة وصول وثيقة', $a->module, $a->record_id, (string) $a->original_name);

        return back()->with('ok', 'أُزيلت قاعدةُ الوصول');
    }

    /** مرفقات سجل — للتضمين في صفحة العرض */
    public static function forRecord(string $module, string $recordId)
    {
        $items = Attachment::where('module', $module)->where('record_id', $recordId)
            ->orderByDesc('created_at')->get();
        $users = \App\Models\User::whereIn('id', $items->pluck('uploaded_by')->filter())
            ->pluck('name', 'id');

        return [$items, $users];
    }

    /* ────────── داخلي ────────── */

    /**
     * تفويضٌ لجوهرِ المرفقات المشترك (F.2) — نسخةٌ واحدة لكلا السطحَين. تبقى هنا
     * كي يستدعيها بقيّةُ طرائقِ الويب (`preview`/`zip`) بالتوقيع نفسِه دون تغيير.
     */
    protected static function auditClassifiedAccess(Attachment $a): void
    {
        AttachmentService::auditClassifiedAccess($a);
    }

    /**
     * نقطةُ التخويلِ الوحيدة — تفويضٌ للجوهرِ المشترك (F.2). تبقى هنا كي تستدعيها
     * طرائقُ الويب (`move`/`preview`/`zip`/`destroy`) عبر `$this` دون تغيير.
     */
    protected function guardRecord(?string $module, ?string $recordId, string $op): void
    {
        AttachmentService::guardRecord($module, $recordId, $op);
    }
}
