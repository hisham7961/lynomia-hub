<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\DmMessage;
use App\Models\HubNotification;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * المراسلة الداخلية المباشرة: محادثة ثنائية لكل زوج مستخدمين، غير مقروء
 * بارز، إيصالات قراءة، مرفقات خلف بوابة الملفات — ولا يقرأ الخيط غير طرفيه.
 */
class DmController extends Controller
{
    /**
     * حضورُ الزملاء — من هو متصلٌ الآن ومتى ظهر آخر مرة.
     *
     * `sessions_log.last_seen_at` صار نبضةً حيّة منذ v2.181، فالحضور يُقرأ منه
     * بلا أي بنيةٍ جديدة: خمس دقائق من آخر ظهورٍ تعني «متصل الآن».
     */
    public static function presence(array $userIds): array
    {
        if (! $userIds || ! \Illuminate\Support\Facades\Schema::hasTable('sessions_log')) return [];

        $rows = \Illuminate\Support\Facades\DB::table('sessions_log')
            ->whereIn('user_id', $userIds)->where('revoked', false)
            ->groupBy('user_id')
            ->pluck(\Illuminate\Support\Facades\DB::raw('MAX(last_seen_at)'), 'user_id');

        $out = [];
        foreach ($rows as $uid => $at) {
            if (! $at) continue;
            $c = \Illuminate\Support\Carbon::parse($at);
            $out[$uid] = ['online' => $c->gt(now()->subMinutes(5)), 'at' => $c];
        }

        return $out;
    }

    /** قائمة المحادثات: آخر رسالة وغير المقروء لكل طرف */
    public function inbox(Request $r)
    {
        $me = auth()->id();

        /*
         * **اختيارُ زميلٍ يفتح خيطَه بلا جافاسكربت**: القائمة كانت تنتقل عند
         * `onsubmit` وحده، و`<select>` لا يُرسِل نموذجَه باختيار عنصر، ولا زرَّ
         * إرسالٍ مرئيّ — فيختار المستخدم زميلاً و**لا يحدث شيء**. الآن النموذج
         * يصل هنا فعلاً ويُحوَّل إلى الخيط، والجافاسكربت تسريعٌ لا شرطُ عمل.
         */
        $to = hub_str($r->query('to'));
        if ($to !== '' && $to !== $me && User::whereNull('deleted_at')->whereKey($to)->exists()) {
            return redirect()->route('dm.thread', $to);
        }
        $msgs = DmMessage::where('from_id', $me)->orWhere('to_id', $me)
            ->orderByDesc('created_at')->limit(500)->get();

        $threads = $msgs->groupBy('thread_key')->map(function ($g) use ($me) {
            $last = $g->first();
            return [
                'other'  => $last->from_id === $me ? $last->to_id : $last->from_id,
                'last'   => $last,
                'unread' => $g->where('to_id', $me)->whereNull('read_at')->count(),
            ];
        })->values();

        $users = User::whereIn('id', $threads->pluck('other'))->pluck('name', 'id');
        $all = User::whereNull('deleted_at')->where('id', '!=', $me)
            ->where('status', 'نشط')->orderBy('name')->pluck('name', 'id');

        return view('dm.inbox', ['threads' => $threads, 'users' => $users, 'all' => $all,
            'open' => null, 'msgs' => collect(), 'other' => null,
            'presence' => self::presence($threads->pluck('other')->all())]);
    }

    /** خيط محادثة مع مستخدم — الفتح يختم القراءة */
    public function thread(string $userId)
    {
        $other = User::findOrFail($userId);
        abort_if($other->id === auth()->id(), 404, 'لا محادثة مع النفس');

        $key = DmMessage::threadKey(auth()->id(), $other->id);
        DmMessage::where('thread_key', $key)->where('to_id', auth()->id())
            ->whereNull('read_at')->update(['read_at' => now()]);

        $msgs = DmMessage::where('thread_key', $key)->orderBy('created_at')->limit(300)->get();

        // نفس الشاشة: قائمةُ المحادثات إلى جانب الخيط المفتوح — لا صفحتان منفصلتان
        $me = auth()->id();
        $all = DmMessage::where('from_id', $me)->orWhere('to_id', $me)
            ->orderByDesc('created_at')->limit(500)->get();
        $threads = $all->groupBy('thread_key')->map(function ($g) use ($me) {
            $last = $g->first();

            return ['other' => $last->from_id === $me ? $last->to_id : $last->from_id,
                    'last' => $last, 'unread' => $g->where('to_id', $me)->whereNull('read_at')->count()];
        })->values();

        $ids = $threads->pluck('other')->push($other->id)->unique()->all();

        return view('dm.inbox', [
            'other' => $other, 'msgs' => $msgs, 'open' => $other->id,
            'threads' => $threads,
            'users' => User::whereIn('id', $ids)->pluck('name', 'id'),
            'all' => User::whereNull('deleted_at')->where('id', '!=', $me)
                ->where('status', 'نشط')->orderBy('name')->pluck('name', 'id'),
            'presence' => self::presence($ids),
        ]);
    }

    /**
     * إرسالٌ من الصندوق مباشرةً: المُرسَل إليه في الحمولة لا في المسار.
     *
     * الرسالةُ الأولى كانت تحتاج خيطاً مفتوحاً، والخيطُ يحتاج رابطاً لا يوجد —
     * حلقةٌ مغلقة. الآن للصندوق بابُه: تختار الزميل وتكتب وترسل في خطوةٍ واحدة.
     */
    public function start(Request $r)
    {
        $to = hub_str($r->input('to'));

        $r->merge(['to' => $to]);
        $r->validate(['to' => ['required', 'string']], [], ['to' => 'المُرسَل إليه']);

        return $this->send($r, $to);
    }

    public function send(Request $r, string $userId)
    {
        /*
         * **المُرسَل إليه يُتحقَّق منه قبل الكتابة**: `findOrFail` وحده يرمي ٤٠٤
         * على معرّفٍ خاطئ فيرى المستخدم صفحةَ خطأٍ بدل رسالةٍ تقول ما الخلل.
         * وأهمّ: منذ v2.207 يُوقَف الحسابُ تلقائياً عند انتهاء الخدمة — ورسالةٌ
         * إلى موقوفٍ تدخل صندوقاً لا يفتحه أحد، والمرسِلُ يرى «✓» ويبني عليها.
         */
        $other = User::whereNull('deleted_at')->find($userId);
        $why = match (true) {
            ! $other                        => 'لا حساب بهذا المعرّف — اختر زميلاً من القائمة',
            $other->id === auth()->id()     => 'لا محادثة مع النفس',
            ($other->status ?? '') !== 'نشط' => 'حساب «' . $other->name . '» موقوف — رسالتُك لن يفتحها أحد',
            default                         => null,
        };
        if ($why !== null) return back()->withInput()->withErrors(['to' => $why]);

        // مسافاتٌ بيضٌ ليست رسالة: `required` وحدها تقبل «   »
        $r->merge(['body' => trim(hub_str($r->input('body')))]);
        $data = $r->validate([
            'body' => ['required', 'string', 'max:4000'],
            'att'  => ['nullable', 'file', 'max:' . (int) setting('files.max_kb', 512000)],
        ], [], ['body' => 'نص الرسالة', 'att' => 'المرفق']);

        DmMessage::create([
            'thread_key' => DmMessage::threadKey(auth()->id(), $other->id),
            'from_id'    => auth()->id(),
            'to_id'      => $other->id,
            'body'       => $data['body'],
            'att'        => $r->hasFile('att') ? $r->file('att')->store('hub', 'local') : null,
            'created_at' => now(),
        ]);

        hub_notify($other->id, 'dm',
            '💬 رسالة من ' . auth()->user()->name . ': ' . trim($data['body']));

        return redirect()->route('dm.thread', $other->id)->withFragment('bottom');
    }

    /** عدد غير المقروء للمستخدم الحالي — لشارة القائمة */
    public static function unreadCount(): int
    {
        return DmMessage::where('to_id', auth()->id())->whereNull('read_at')->count();
    }
}
