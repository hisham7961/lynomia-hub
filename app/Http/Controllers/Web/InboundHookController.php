<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\InboundHook;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * **الويبهوك الوارد** — نقاطُ استقبالٍ أصلية في مركز التكامل. نظامٌ خارجيّ
 * (n8n، نموذج، خدمة) يستدعي `POST /hook/{token}` فيُخزَّن ما أرسله حدثاً ويُحصى.
 *
 * الأمان: الرمزُ في الرابط غيرُ قابلٍ للتخمين، وتوقيعُ HMAC اختياريٌّ يُثبِت المُرسِل،
 * والحمولةُ مسقوفةٌ حجماً، والمسارُ محدودُ المعدّل. لا مصادقة جلسة — سطحٌ عامٌّ مقصود.
 */
class InboundHookController extends Controller
{
    /** أقصى حجمِ حمولةٍ تُخزَّن (٦٤ كيلوبايت) — ما زاد يُقتطع، لا يُرفض */
    protected const MAX_BYTES = 65535;   // حدُّ عمود TEXT بالضبط — 65536 يفيضه ببايتٍ فيسقط بـ1406 على MySQL

    protected function gate(): void
    {
        abort_unless(hub_is_owner(), 403, 'إدارة الويبهوك الوارد للمالكين فقط');
    }

    /* ───────── الوجه العام: الاستقبال ───────── */

    public function receive(Request $r, string $token)
    {
        $hook = InboundHook::where('token', $token)->where('enabled', true)->first();
        // صفٌّ غائبٌ أو مُعطَّل: نفس الرد كي لا يُميَّز الموجودُ من المُعطَّل بالرمز
        abort_unless($hook, 404, 'نقطةُ استقبالٍ غير معروفة أو معطَّلة');

        $raw = (string) $r->getContent();

        // توقيعُ HMAC حين يكون للنقطة سرّ: X-Hub-Signature: sha256=<hmac>
        if (filled($hook->secret)) {
            $sent = (string) $r->header('X-Hub-Signature', '');
            $calc = 'sha256=' . hash_hmac('sha256', $raw, (string) $hook->secret);
            abort_unless($sent !== '' && hash_equals($calc, $sent), 401, 'توقيعٌ غير صالح');
        }

        DB::table('inbound_hook_events')->insert([
            'hook_id'    => $hook->id,
            'payload'    => mb_strcut($raw, 0, self::MAX_BYTES),
            'ip'         => $r->ip(),
            'status'     => 200,
            'created_at' => now(),
        ]);
        // النبضة تُحدَّث بلا سباق: زيادةٌ ذرّية على مستوى القاعدة
        DB::table('inbound_hooks')->where('id', $hook->id)
            ->update(['hits' => DB::raw('hits + 1'), 'last_hit_at' => now()]);

        return response()->json(['ok' => true, 'event' => $hook->event, 'received' => strlen($raw)]);
    }

    /* ───────── الإدارة (مركز التكامل) ───────── */

    public function index()
    {
        $this->gate();
        $hooks = InboundHook::orderByDesc('created_at')->orderByDesc('id')->get();
        $events = DB::table('inbound_hook_events')
            ->whereIn('hook_id', $hooks->pluck('id'))
            ->orderByDesc('id')->limit(40)->get()->groupBy('hook_id');

        return view('integrations.hooks', compact('hooks', 'events'));
    }

    public function store(Request $r)
    {
        $this->gate();
        $d = $r->validate([
            'name'   => ['required', 'string', 'max:190'],
            'event'  => ['nullable', 'string', 'max:120'],
            'signed' => ['nullable'],
        ], [], ['name' => 'اسم النقطة']);

        $hook = InboundHook::create([
            'name'       => $d['name'],
            'token'      => Str::random(48),
            'secret'     => $r->boolean('signed') ? Str::random(64) : null,
            'event'      => $d['event'] ?? null,
            'enabled'    => true,
            'company_id' => (($cids = hub_company_ids()) && is_array($cids)) ? ($cids[0] ?? null) : null,
            'created_by' => auth()->id(),
        ]);
        hub_audit('إنشاء ويبهوك وارد', 'integrations', $hook->id, $hook->name);

        return back()->with('ok', 'أُنشئت نقطةُ الاستقبال — انسخ رابطها'
            . ($hook->secret ? ' وسرّها (لن يظهر ثانيةً)' : ''))
            ->with('newhook', $hook->id);
    }

    public function toggle(string $id)
    {
        $this->gate();
        $hook = InboundHook::findOrFail($id);
        $hook->update(['enabled' => ! $hook->enabled]);

        return back()->with('ok', $hook->enabled ? 'فُعّلت النقطة' : 'أُوقفت النقطة — لن تستقبل شيئاً');
    }

    public function destroy(string $id)
    {
        $this->gate();
        $hook = InboundHook::findOrFail($id);
        DB::table('inbound_hook_events')->where('hook_id', $hook->id)->delete();
        $hook->delete();
        hub_audit('حذف ويبهوك وارد', 'integrations', $id, (string) $hook->name);

        return back()->with('ok', 'حُذفت نقطةُ الاستقبال وسجلّها');
    }
}
