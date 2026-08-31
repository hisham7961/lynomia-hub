<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\ShareLink;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * غرفة البيانات الآمنة: مشاركة مستند حساس مع طرف خارجي (مستثمر، محاسب، محامٍ)
 * برابط مؤقت — كلمة مرور اختيارية، تاريخ انتهاء، منع تنزيل بعلامة مائية،
 * سجل مشاهدات، وإلغاء فوري. الملفات على القرص الخاص لا تُخدم إلا عبر الرابط.
 */
class DataRoomController extends Controller
{
    protected function gate(): void
    {
        abort_unless(hub_secrets(), 403,
            'غرفة البيانات تتطلب صلاحية الأسرار أو المالك');
    }

    /* ────────── الإدارة (داخل النظام) ────────── */

    public function index()
    {
        $this->gate();
        $links = ShareLink::orderByDesc('created_at')->orderByDesc('id')->limit(60)->get();
        // سقفٌ صريح: كانت الشاشة تسحب كل السجل التاريخي للمشاهدات إلى الذاكرة —
        // رابطٌ متداول واحد يعني آلاف الصفوف لمجرد عرض الأحدث.
        //
        // لكن **السقفَ العامّ يبتلعه رابطٌ واحد**: ٣٠٠ مشاهدةٍ لأكثرِ الروابط
        // تداولاً تُخرج بقيّةَ الروابط من النتيجة فتبدو «بلا مشاهدات» وسجلُّها
        // قائمٌ في القاعدة. الحصّةُ الآن **لكل رابطٍ على حدة**: خمسٌ لكلٍّ،
        // والسقفُ يتناسب مع عدد الروابط المعروضة لا مع نشاط أحدها.
        $lastViews = DB::table('share_views')->whereIn('share_id', $links->pluck('id'))
            ->orderByDesc('created_at')->orderByDesc('id')
            ->limit(5 * max(1, $links->count()) + 300)->get()
            ->groupBy('share_id')->map(fn ($g) => $g->take(5));

        // ما لم تبلغه النافذةُ العامّة يُستكمل بصفٍّ واحدٍ لكل رابطٍ صامت — فلا
        // يُقال «بلا مشاهدات» عن رابطٍ شوهد فعلاً
        foreach ($links as $l) {
            if (isset($lastViews[$l->id])) continue;
            $row = DB::table('share_views')->where('share_id', $l->id)
                ->orderByDesc('created_at')->orderByDesc('id')->limit(1)->get();
            if ($row->isNotEmpty()) $lastViews[$l->id] = $row;
        }

        return view('dataroom.index', compact('links', 'lastViews'));
    }

    public function store(Request $r)
    {
        $this->gate();
        $d = $r->validate([
            'title'    => ['required', 'string', 'max:190'],
            // حدُّ النظام نفسُه لا رقمٌ مكتوبٌ بيد: كان ٥٠ م.ب صامتاً بينما بقية
            // مسارات الرفع تتبع «files.max_kb» — فغرفةُ البيانات مخزنُ ملفاتٍ
            // كغيرها، ومن رفع هويةً بصريةً هنا يستحق السقفَ ذاته.
            'file'     => ['required', 'file', 'max:' . hub_upload_cap()['kb']],
            'password' => ['nullable', 'string', 'min:4', 'max:100'],
            'days'     => ['nullable', 'integer', 'min:1', 'max:365'],
            'no_download' => ['nullable'],
        ]);

        $link = ShareLink::create([
            'token'         => Str::random(48),
            'title'         => $d['title'],
            'path'          => $r->file('file')->store('dataroom', 'local'),   // القرص الخاص صراحةً — لا يُخدم إلا عبر الرمز
            'mime'          => $r->file('file')->getMimeType(),
            'password_hash' => filled($d['password'] ?? null) ? Hash::make($d['password']) : null,
            'expires_at'    => filled($d['days'] ?? null) ? now()->addDays((int) $d['days']) : null,
            'no_download'   => $r->boolean('no_download'),
            'created_by'    => auth()->id(),
            'created_at'    => now(),
        ]);

        // مشاركةُ مستندٍ حساسٍ خارج النظام حدثٌ أمنيّ — كان يمرّ دون أثرٍ في
        // سلسلة التدقيق (صفٌّ created_by فقط): يُدوَّن الآن باسمه وحمايته ومدّته
        hub_audit('إنشاء رابط مشاركة', null, $link->id, $d['title'], ['after' => [
            'protected' => $link->password_hash ? true : false,
            'expires' => $link->expires_at?->toDateString() ?: 'دائم',
            'no_download' => (bool) $link->no_download,
        ]]);

        return back()->with('ok', 'أُنشئ الرابط — انسخه من القائمة وشاركه')->with('newlink', route('share.show', $link->token));
    }

    public function revoke(string $id)
    {
        $this->gate();
        $link = ShareLink::findOrFail($id);
        $link->update(['revoked' => true]);
        hub_audit('إلغاء رابط مشاركة', null, $link->id, $link->title);
        // أقل احتفاظ: لا un-revoke في النظام، فالملف بعد الإلغاء غير قابل للوصول
        // من أي مسار — إبقاؤه (مستندٌ وُصف حساساً أصلاً) على القرص أبداً تراكمُ خطر
        if ($link->path && is_file(storage_path('app/' . $link->path))) {
            @unlink(storage_path('app/' . $link->path));
        }

        return back()->with('ok', 'أُلغي الرابط فوراً ومُحي ملفه من الخادم');
    }

    /* ────────── الوجه العام (بلا تسجيل دخول) ────────── */

    public function show(Request $r, string $token)
    {
        $link = $this->alive($token);

        if ($link->password_hash && ! $r->session()->get('share:' . $link->id)) {
            // العنوان لا يُكشف قبل كلمة المرور: «عرض استحواذ فلان» كثيراً ما يكون
            // هو السرّ نفسه — وكلمةُ المرور وُضعت تحديداً لطبقةٍ ثانية عند تسرّب الرابط
            return view('dataroom.gate', ['token' => $token, 'title' => 'مستند محمي']);
        }

        $this->logView($link);

        return view('dataroom.show', [
            'link'  => $link,
            'stamp' => $r->ip() . ' · ' . now()->format('Y-m-d H:i'),
        ]);
    }

    public function unlock(Request $r, string $token)
    {
        $link = $this->alive($token);
        if (! $link->password_hash || ! Hash::check(hub_str($r->input('password')), $link->password_hash)) {
            return back()->withErrors(['password' => 'كلمة المرور غير صحيحة']);
        }
        $r->session()->put('share:' . $link->id, true);

        return redirect()->route('share.show', $token);
    }

    /** بث الملف نفسه — يخدم المعاينة، والتنزيل فقط إن كان مسموحاً */
    public function file(Request $r, string $token)
    {
        $link = $this->alive($token);
        abort_if($link->password_hash && ! $r->session()->get('share:' . $link->id), 403);

        $abs = storage_path('app/' . $link->path);
        abort_unless(file_exists($abs), 404);

        /*
         * سياسة النوع قبل سياسة التنزيل: الصور النقطية وPDF وحدها تُعاين
         * inline — أي شيء آخر (HTML/SVG خاصةً) تنزيلٌ قسري، فهذا سطحٌ **عام
         * بلا مصادقة** وملفٌ يُنفَّذ بأصل النظام هنا يصل لكوكي مدير عاين الرابط.
         * ومعها: «عرض فقط» على نوعٍ لا يُعاين = لا مسار بثٍّ إطلاقاً، والجلبُ
         * المباشر بلا dl كان **لا يُسجَّل** — فسجل المشاهدات أعمى عن أهم مسار.
         */
        $inline = in_array((string) $link->mime, AttachmentController::INLINE_MIMES, true);
        $download = $r->boolean('dl') || ! $inline;
        abort_if($download && $link->no_download, 403, 'التنزيل ممنوع لهذا الرابط — عرض فقط');
        $this->logView($link);

        // «عرض فقط»: الوسمُ يُحرَق في نفس البايتات المخدومة — لا في غلافٍ يُتجاوَز
        // بجلب هذا الرابط مباشرةً. بصمةُ المُشاهِد (النظام · IP · وقت) تلازم النسخة.
        // يفشل **مغلقاً**: لا يُبَثّ الأصلُ النظيفُ لرابطٍ وُعد بالوسم.
        if ($link->no_download) {
            $stamp = setting('app.name', 'Lynomia') . ' · ' . $r->ip() . ' · ' . now()->format('Y-m-d H:i');
            try {
                $bytes = (string) $link->mime === 'application/pdf'
                    ? \App\Support\Watermark::pdf($abs, $stamp)
                    : \App\Support\Watermark::image($abs, $stamp, (string) $link->mime);
            } catch (\Throwable $e) {
                report($e);
                abort(500, 'تعذّر تجهيز العرض الآمن لهذا المستند — راجع مُرسِل الرابط');
            }

            return response($bytes, 200, [
                'Content-Type' => $link->mime ?: 'application/octet-stream',
                'Content-Disposition' => 'inline; filename="' . rawurlencode($link->title) . '"',
                'X-Robots-Tag' => 'noindex',
                'X-Content-Type-Options' => 'nosniff',
                'Cache-Control' => 'no-store, private',   // بصمةٌ لكل مُشاهِد — لا تُخبَّأ ولا تُشارَك
            ]);
        }

        return response()->file($abs, [
            'Content-Type' => $link->mime ?: 'application/octet-stream',
            'Content-Disposition' => ($download ? 'attachment' : 'inline') . '; filename="' . rawurlencode($link->title) . '"',
            'X-Robots-Tag' => 'noindex',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /* ────────── داخلي ────────── */

    protected function alive(string $token): ShareLink
    {
        $link = ShareLink::where('token', $token)->first();
        abort_unless($link, 404);
        abort_if($link->isDead(), 410, 'انتهت صلاحية هذا الرابط أو أُلغي');

        return $link;
    }

    protected function logView(ShareLink $link): void
    {
        DB::table('share_views')->insert([
            'share_id' => $link->id,
            'ip'       => request()->ip(),
            'device'   => substr((string) request()->userAgent(), 0, 200),
            'created_at' => now(),
        ]);
        $link->increment('views');
    }
}
