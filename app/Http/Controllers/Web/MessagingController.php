<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\OutboxMessage;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

/**
 * **مركز المراسلة** — كل طرق التواصل الخارجة من النظام في شاشة واحدة.
 *
 * كانت المراسلة مبعثرةً: توكن تلجرام في الإعدادات، والبريد في `.env` بلا
 * شاشةٍ تقول حالته، والطوابير في مركز التشغيل، والإعادة أمرَ طرفية،
 * وتفضيلاتُ المستخدم (`notify_prefs`) **حلقةً ميتة** تُقرأ ولا تُكتب من
 * أي واجهة. هنا تُجمع وتُدار وتُختبر — للمالك، كسائر مركز التكاملات.
 *
 * **وأخبثُ ما تكشفه هذه الشاشة**: `MAIL_MAILER=log` الافتراضي يعني أن كل
 * بريدٍ «يُرسل بنجاح» إلى ملف السجل ولا يصل أحداً — نجاحٌ كاذبٌ صامت.
 */
class MessagingController extends Controller
{
    protected function gate(): void
    {
        abort_unless(hub_is_owner(), 403, 'مركز المراسلة للمالكين فقط');
    }

    public function index()
    {
        $this->gate();

        // الطوابير حيّةً: قناة × حالة
        $grid = [];
        foreach (DB::table('outbox')->select('channel', 'state', DB::raw('COUNT(*) as c'))
            ->groupBy('channel', 'state')->get() as $r) {
            $grid[$r->channel][$r->state] = (int) $r->c;
        }

        // آخر الفاشلات بأخطائها — الخطأ نصُّ العلاج
        $failed = OutboxMessage::where('state', 'failed')
            ->orderByDesc('created_at')->orderByDesc('id')->limit(10)->get();

        $mailer = (string) config('mail.default');

        return view('integrations.messaging', [
            'grid'       => $grid,
            'failed'     => $failed,
            'tgReady'    => (string) setting('notify.tg_token', '') !== '',
            'tgChat'     => (string) setting('notify.tg_chat', ''),
            'mailer'     => $mailer,
            'mailReal'   => ! in_array($mailer, ['log', 'array'], true),
            'inappCount' => (int) DB::table('notifications_hub')->count(),
            'inappWeek'  => (int) DB::table('notifications_hub')->where('created_at', '>=', now()->subDays(7))->count(),
            'prefsUsers' => (int) DB::table('users')->whereNull('deleted_at')
                ->whereNotNull('notify_prefs')->where('notify_prefs', 'NOT LIKE', '[]')
                ->where('notify_prefs', 'NOT LIKE', '{}')->count(),
            'heartbeat'  => (string) setting('heartbeat.outbox', ''),
        ]);
    }

    /**
     * إرسالٌ تجريبي فوري — الدليل الحيّ بدل التخمين: يصفّ رسالةً لمالكها
     * ثم يشغّل عامل التسليم نفسه، فالنتيجة نتيجةُ المسار الحقيقي كاملاً.
     */
    public function test(Request $r)
    {
        $this->gate();
        $d = $r->validate(['channel' => ['required', 'in:tg,mail']]);

        $msg = OutboxMessage::create([
            'user_id' => auth()->id(), 'kind' => 'test', 'channel' => $d['channel'],
            'text' => '🧪 رسالة تجريبية من مركز المراسلة — وصولُها يعني أن القناة تعمل ('
                . now()->format('H:i:s') . ')',
            'state' => 'queued', 'created_at' => now(),
        ]);

        Artisan::call('hub:outbox', ['--limit' => 25]);

        $msg->refresh();

        return $msg->state === 'sent'
            ? back()->with('ok', '✅ أُرسلت التجريبية عبر ' . ($d['channel'] === 'tg' ? 'تلجرام' : 'البريد')
                . ' — تفقد وصولها' . ($d['channel'] === 'mail' && in_array((string) config('mail.default'), ['log', 'array'], true)
                    ? '. ⚠️ لكن البريد على وضع «سجل»: كُتبت في ملف السجل ولم تغادر الخادم' : ''))
            : back()->with('err', 'فشلت التجريبية: ' . ($msg->error ?: 'ما تزال في الصف — راجع عامل التسليم'));
    }

    /** إعادةُ الفاشل من الشاشة — كان أمرَ طرفيةٍ لا يعرفه إلا من قرأ التوثيق */
    public function retry()
    {
        $this->gate();
        $n = OutboxMessage::where('state', 'failed')->update(['state' => 'queued', 'error' => null]);
        if ($n) Artisan::call('hub:outbox', ['--limit' => 50]);

        $left = OutboxMessage::where('state', 'failed')->count();

        return back()->with($left ? 'err' : 'ok',
            $n === 0 ? 'لا رسائل فاشلة تُعاد'
                : ($left ? "أُعيدت {$n} — بقيت {$left} فاشلة: أسبابُها في الجدول أدناه"
                    : "أُعيدت {$n} رسالة وسُلّمت كلُّها ✅"));
    }
}
