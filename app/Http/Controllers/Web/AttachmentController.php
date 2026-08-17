<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\Attachment;
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
    /** امتدادات تُرفض مهما كان الإعداد — تنفيذية على الخادم */
    protected const BLOCKED = ['php', 'php3', 'php4', 'php5', 'php7', 'php8', 'phtml', 'phar', 'cgi', 'pl', 'sh', 'htaccess'];

    /** أقصى ملفاتٍ في رفعةٍ واحدة — لقطاتُ متجرٍ لثلاث منصّاتٍ لا تتجاوزها */
    public const BATCH_MAX = 20;

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
        $data = $r->validate([
            'module'    => ['required', 'string', 'max:60'],
            'record_id' => ['required', 'string', 'max:36'],
            // أحدُهما يكفي: المفردُ أو الدفعة — والتحقق على كل ملفٍ في الدفعة
            'file'      => ['required_without:files', 'nullable', 'file', 'max:' . hub_upload_cap()['kb']],
            'files'     => ['required_without:file', 'nullable', 'array', 'max:' . self::BATCH_MAX],
            'files.*'   => ['file', 'max:' . hub_upload_cap()['kb']],
            // الملاحظة كانت تُحقَّق ٢٠٠ حرفاً وتُحشر في عمود ٦٠ — صار لها عمودها
            'note'      => ['nullable', 'string', 'max:300'],
            // نوع الوثيقة من ملف الكيان — مفتاحٌ معلن لا نصٌّ حر
            'kind'       => ['nullable', 'string', 'max:40',
                             \Illuminate\Validation\Rule::in(collect(hub_doc_spec(hub_str($r->input('module'))))->pluck('key')->all())],
            'doc_no'     => ['nullable', 'string', 'max:80'],
            'issued_at'  => ['nullable', 'date'],
            'expires_at' => ['nullable', 'date'],
        ], [], [
            'note' => 'الملاحظة', 'kind' => 'نوع الوثيقة', 'doc_no' => 'رقم الوثيقة',
            'issued_at' => 'تاريخ الإصدار', 'expires_at' => 'تاريخ الانتهاء',
        ]);

        $this->guardRecord($data['module'], $data['record_id'], 'v');

        // الدفعةُ بترتيب اختيارها، والمفردُ دفعةٌ من واحد — مسارٌ واحدٌ لا مساران
        $files = $r->hasFile('files') ? array_values(array_filter((array) $r->file('files'))) : [];
        if ($r->hasFile('file')) array_unshift($files, $r->file('file'));
        abort_if(! $files, 422, 'لا ملف في الطلب');
        $files = array_slice($files, 0, self::BATCH_MAX);

        // **الترتيبُ يتبع الوصول**: اللقطةُ الجديدة تُذيَّل ولا تقفز إلى الصدارة —
        // وصدارةُ المعرض هي أولُ ما يراه المستخدم في المتجر.
        $sort = (int) Attachment::where('module', $data['module'])
            ->where('record_id', $data['record_id'])->max('sort');

        $made = [];
        foreach ($files as $f) {
            $ext = mb_strtolower((string) $f->getClientOriginalExtension());
            abort_if(in_array($ext, self::BLOCKED, true), 422,
                'هذا النوع من الملفات غير مسموح: ' . Str::limit((string) $f->getClientOriginalName(), 40));

            $path = $f->store('hub/att', 'local');

            $made[] = Attachment::create([
                'module'        => $data['module'],
                'record_id'     => $data['record_id'],
                'note'          => ($data['note'] ?? null) ?: null,   // ملاحظة اختيارية تصف الملف
                'kind'          => ($data['kind'] ?? null) ?: null,
                'doc_no'        => ($data['doc_no'] ?? null) ?: null,
                'issued_at'     => ($data['issued_at'] ?? null) ?: null,
                'expires_at'    => ($data['expires_at'] ?? null) ?: null,
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

        $a = $made[0];
        // وثيقةٌ لها مدّة تدخل رادار «ينتهي قريباً» فوراً لا بعد انقضاء المخبأ
        if ($a->expires_at) hub_expiry_bust();

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
        $a = Attachment::findOrFail($id);
        $this->guardRecord($a->module, $a->record_id, 'v');

        // عمود av_status كان حبراً على ورق: مرفقٌ وُسم «مصاب» يُخدم كأن شيئاً
        // لم يكن. لا ماسحَ مدمجاً بعد (يبقى 'pending' فيُخدم) — لكن متى وسمت
        // أداةٌ خارجية ملفاً مصاباً توقّف تقديمه فوراً. 423 Locked: محجوز لا مفقود.
        abort_if($a->av_status === 'infected', 423, 'حُجب هذا الملف — وُسم مصاباً بفحص الفيروسات');

        $abs = Storage::disk($a->disk ?: 'local')->path($a->path);
        abort_unless(is_file($abs), 404, 'الملف غير موجود على القرص');

        $a->increment('downloads');
        DB::table('download_log')->insert([
            'attachment_id' => $a->id, 'user_id' => auth()->id(),
            'ip' => request()->ip(), 'device' => substr((string) request()->userAgent(), 0, 200),
            'created_at' => now(),
        ]);

        // Content-Disposition: attachment — ملف HTML/SVG مرفوع لا يُنفَّذ في المتصفح أبداً
        return response()->download($abs, $a->original_name ?: basename($a->path));
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
    // عامة: بوابة ملفات الوحدات وغرفة البيانات تتبعان السياسة نفسها — تعريفٌ واحد
    public const INLINE_MIMES = ['image/png', 'image/jpeg', 'image/gif', 'image/webp', 'image/bmp', 'image/avif', 'application/pdf'];

    /**
     * معاينة حية: الصورة/الشهادة/اللوجو تُعرض مصغّرةً وكاملةً دون تنزيل،
     * وPDF يفتح في عارض المتصفح. بهوية المستخدم وصلاحيته نفسها، ويُسجَّل الاطلاع.
     */
    public function preview(string $id)
    {
        $a = Attachment::findOrFail($id);
        $this->guardRecord($a->module, $a->record_id, 'v');
        // نفس حاجز التنزيل: الملف المصاب لا يُقدَّم على المعاينة أيضاً — والأخطر أن
        // صفحة السجل تُحمّل المعاينة تلقائياً في <img>/<iframe> فيُعرَض بلا ضغطة.
        abort_if($a->av_status === 'infected', 423, 'حُجب هذا الملف — وُسم مصاباً بفحص الفيروسات');
        abort_unless(in_array($a->mime, self::INLINE_MIMES, true), 415, 'هذا النوع يُنزَّل ولا يُعاين');

        $abs = Storage::disk($a->disk ?: 'local')->path($a->path);
        abort_unless(is_file($abs), 404, 'الملف غير موجود على القرص');

        DB::table('download_log')->insert([
            'attachment_id' => $a->id, 'user_id' => auth()->id(),
            'ip' => request()->ip(), 'device' => substr('معاينة · ' . request()->userAgent(), 0, 200),
            'created_at' => now(),
        ]);

        return response()->file($abs, [
            'Content-Type'           => $a->mime,
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'; style-src 'unsafe-inline'",
            'Cache-Control'          => 'private, max-age=300',
        ]);
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

    /** الهدف موجود، والوحدة مرئية للمستخدم، والسجل ضمن نطاقه */
    protected function guardRecord(?string $module, ?string $recordId, string $op): void
    {
        $def = hub_mod((string) $module);
        abort_unless($def && $recordId, 404);
        abort_unless(hub_can(auth()->user(), $module, $op), 403);
        $class = '\\App\\Models\\' . $def['model'];
        hub_scope($class::query(), $module)->findOrFail($recordId);
    }
}
