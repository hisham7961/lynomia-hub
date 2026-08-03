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
        // رابطٌ متداول واحد يعني آلاف الصفوف لمجرد عرض الأحدث
        $lastViews = DB::table('share_views')->whereIn('share_id', $links->pluck('id'))
            ->orderByDesc('created_at')->orderByDesc('id')->limit(300)->get()->groupBy('share_id');

        return view('dataroom.index', compact('links', 'lastViews'));
    }

    public function store(Request $r)
    {
        $this->gate();
        $d = $r->validate([
            'title'    => ['required', 'string', 'max:190'],
            'file'     => ['required', 'file', 'max:51200'],
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

        return back()->with('ok', 'أُنشئ الرابط — انسخه من القائمة وشاركه')->with('newlink', route('share.show', $link->token));
    }

    public function revoke(string $id)
    {
        $this->gate();
        $link = ShareLink::findOrFail($id);
        $link->update(['revoked' => true]);
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
