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

    public function store(Request $r)
    {
        $data = $r->validate([
            'module'    => ['required', 'string', 'max:60'],
            'record_id' => ['required', 'string', 'max:36'],
            'file'      => ['required', 'file', 'max:' . (int) setting('files.max_kb', 512000)],
            'note'      => ['nullable', 'string', 'max:200'],
        ]);

        $this->guardRecord($data['module'], $data['record_id'], 'v');

        $f = $r->file('file');
        $ext = mb_strtolower((string) $f->getClientOriginalExtension());
        abort_if(in_array($ext, self::BLOCKED, true), 422, 'هذا النوع من الملفات غير مسموح');

        $path = $f->store('hub/att', 'local');

        $a = Attachment::create([
            'module'        => $data['module'],
            'record_id'     => $data['record_id'],
            'field'         => ($data['note'] ?? null) ?: null,   // ملاحظة اختيارية تصف الملف
            'disk'          => 'local',
            'path'          => $path,
            'original_name' => Str::limit((string) $f->getClientOriginalName(), 290, ''),
            'mime'          => substr((string) $f->getMimeType(), 0, 160),
            'size'          => (int) $f->getSize(),
            'checksum'      => hash_file('sha256', $f->getRealPath()) ?: null,
            'uploaded_by'   => auth()->id(),
        ]);

        return back()->with('ok', 'أُرفق الملف')->withFragment('att-' . $a->id);
    }

    public function download(string $id)
    {
        $a = Attachment::findOrFail($id);
        $this->guardRecord($a->module, $a->record_id, 'v');

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

    /** أنواع تُعاين حيّاً داخل المتصفح — صور نقطية وPDF فقط؛ SVG/HTML تبقى تنزيلاً (قد تحمل سكربتات) */
    protected const INLINE_MIMES = ['image/png', 'image/jpeg', 'image/gif', 'image/webp', 'image/bmp', 'image/avif', 'application/pdf'];

    /**
     * معاينة حية: الصورة/الشهادة/اللوجو تُعرض مصغّرةً وكاملةً دون تنزيل،
     * وPDF يفتح في عارض المتصفح. بهوية المستخدم وصلاحيته نفسها، ويُسجَّل الاطلاع.
     */
    public function preview(string $id)
    {
        $a = Attachment::findOrFail($id);
        $this->guardRecord($a->module, $a->record_id, 'v');
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
