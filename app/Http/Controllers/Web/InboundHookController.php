<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\InboundHook;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
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

    /**
     * **رصدُ الختمِ الزمنيّ — كتابةٌ واحدةٌ بالدقيقةِ كحدٍّ أقصى.**
     *
     * والعمودُ المكتوبُ واحدٌ لا اثنان: الطلبُ إمّا حمل الترويسةَ أو افتقدها،
     * فيُؤرَّخ الوجهُ الذي وقع وحدَه. وغيابُ العمودِ (تنصيبٌ لم يُرحَّل بعد)
     * يُتخطّى بصمت — الرصدُ إضافةٌ لا شرطٌ لعملِ النقطة.
     */
    protected function observeTimestamp(\App\Models\InboundHook $hook, bool $present): void
    {
        $col = $present ? 'ts_seen_at' : 'ts_missing_at';
        if (! hub_has_col('inbound_hooks', $col)) return;

        try {
            $last = $hook->{$col};
            if ($last && \Illuminate\Support\Carbon::parse($last)->gt(now()->subMinute())) return;
            // كتابةٌ مباشرةٌ لا حفظُ نموذج — نظيرُ سطرِ `hits` أسفلَه حرفاً: فحفظُ
            // النموذجِ يلمس `updated_at` ويُمرّ عمودَ السرِّ على cast التشفير بلا داعٍ
            \Illuminate\Support\Facades\DB::table('inbound_hooks')->where('id', $hook->id)->update([$col => now()]);
            $hook->{$col} = now();   // فلا يُكرَّر الرصدُ في الطلبِ نفسِه
        } catch (\Throwable $e) {
            report($e);   // الرصدُ لا يُسقط استقبالاً
        }
    }

    public function receive(Request $r, string $token)
    {
        $hook = InboundHook::where('token', $token)->where('enabled', true)->first();
        // صفٌّ غائبٌ أو مُعطَّل: نفس الرد كي لا يُميَّز الموجودُ من المُعطَّل بالرمز
        abort_unless($hook, 404, 'نقطةُ استقبالٍ غير معروفة أو معطَّلة');

        $raw = (string) $r->getContent();

        /*
         * **رصدُ تبنّي الختمِ الزمنيّ — قبل أيِّ فرض** (#20 · §٥ · v2.597.0).
         *
         * كلفةُ البند المعلَنة «المُرسِلونَ القدامى يُرفَضون»، ولا يُقرَّر على
         * كلام. فيُسجَّل لكلِّ نقطةٍ آخرُ طلبٍ **حمل** الترويسةَ وآخرُ طلبٍ
         * **افتقدها** — فيقرأ المالكُ بعد أسبوعين مَن يتوقّف بالاسم
         * (`HardeningReadiness::inboundHooks()`).
         *
         * **والكتابةُ مخنوقةٌ بالدقيقة** كنمطِ `ApiAuth::last_used_at`: رصدٌ
         * لا يضيف كتابةً لكلِّ طلبٍ على سطحٍ عامّ.
         */
        $this->observeTimestamp($hook, trim((string) $r->header('X-Hub-Timestamp', '')) !== '');

        // توقيعُ HMAC حين يكون للنقطة سرّ: X-Hub-Signature: sha256=<hmac>
        if (filled($hook->secret)) {
            $sent = (string) $r->header('X-Hub-Signature', '');
            $ts = trim((string) $r->header('X-Hub-Timestamp', ''));

            // **والفرضُ رافعةٌ مطفأةٌ افتراضياً**: بلا إشعالها يبقى المُرسِلُ
            // القديمُ يعمل حرفاً كما كان — لا شيءَ يُكسَر بالترقية.
            if ($ts === '' && (string) setting('security.inbound_require_timestamp', '0') === '1') {
                abort(401, 'هذه النقطةُ تتطلب ترويسةَ X-Hub-Timestamp');
            }
            // (v2.399) ربطُ التوقيع بالزمن حين يرسل المصدرُ X-Hub-Timestamp: يُوقَّع "ts.body" ويُرفض
            // ما تجاوز خمسَ دقائق — فالطلبُ الملتقَط لا يُعاد بعد نافذته. وبلا الترويسة يبقى
            // التوقيعُ على الجسم كما كان (توافقٌ مع المُرسِلين القائمين).
            if ($ts !== '') {
                abort_unless(ctype_digit($ts) && abs(time() - (int) $ts) <= 300, 401, 'طابعُ الوقت خارج النافذة المسموحة (٥ دقائق)');
                $calc = 'sha256=' . hash_hmac('sha256', $ts . '.' . $raw, (string) $hook->secret);
            } else {
                $calc = 'sha256=' . hash_hmac('sha256', $raw, (string) $hook->secret);
            }
            abort_unless($sent !== '' && hash_equals($calc, $sent), 401, 'توقيعٌ غير صالح');
        }

        /*
         * **حمايةُ إعادة التشغيل**: التوقيعُ يُثبت أن الجسمَ صدر من مالك السرّ،
         * ولا يُثبت أنّه لم يُعَد إرساله — فمن التقط الطلب يُعيده كما هو بلا
         * معرفةِ السرّ، ويتكرّر الحدثُ من سطحٍ عامّ. `X-Hub-Event-Id` (‏وهي
         * الترويسةُ التي يرسلها صادرُ النظام نفسُه) + قيدٌ فريد + `insertOrIgnore`
         * يجعلان الإعادةَ بلا أثر. ومُرسِلٌ لا يُرسل الترويسة يبقى يعمل كما كان.
         */
        $eventId = hub_fit(hub_str($r->header('X-Hub-Event-Id')), 190);
        $eventId = $eventId === '' ? null : $eventId;

        // (WP-1.3) الحمولةُ تُخزَّن **مطموسةً**: مفاتيحُ الأسرار (password/api_key/…) بالعمق
        // في JSON، وأنماطُ الرموز (Bearer/JWT/PEM/…) في النص — بعد التحقق من التوقيع
        // على الخام كما هو، فالطمسُ لا يمسّ HMAC.
        $row = [
            'hook_id'    => $hook->id,
            'payload'    => mb_strcut(\App\Support\Redactor::json($raw), 0, self::MAX_BYTES),
            'ip'         => $r->ip(),
            'status'     => 200,
            'created_at' => now(),
        ];
        if (Schema::hasColumn('inbound_hook_events', 'event_id')) $row['event_id'] = $eventId;
        // (WP-1.4) ربطُ الحدث الوارد بطلبه — يظهر في صفحة `system.trace` بمعرّفه
        if (Schema::hasColumn('inbound_hook_events', 'request_id')) {
            $row['request_id'] = mb_substr((string) \App\Support\Api::requestId(), 0, 40) ?: null;
        }

        $fresh = $eventId === null
            ? (bool) DB::table('inbound_hook_events')->insert($row)
            : (bool) DB::table('inbound_hook_events')->insertOrIgnore($row);

        if (! $fresh) {
            return response()->json(['ok' => true, 'event' => $hook->event,
                                     'received' => strlen($raw), 'duplicate' => true]);
        }

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

        // #20: حالةُ تبنّي الختمِ الزمنيّ لكلِّ نقطة — «مَن يتوقّف» قبل أيِّ إشعال
        $ts = collect(\App\Support\HardeningReadiness::inboundHooks())->keyBy('id');
        $tsSummary = \App\Support\HardeningReadiness::summary();
        $requireTs = (string) setting('security.inbound_require_timestamp', '0') === '1';

        return view('integrations.hooks', compact('hooks', 'events', 'ts', 'tsSummary', 'requireTs'));
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
        hub_audit($hook->enabled ? 'تفعيل ويبهوك وارد' : 'تعطيل ويبهوك وارد', 'integrations', $hook->id, $hook->name);

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
