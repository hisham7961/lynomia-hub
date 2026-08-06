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
        /*
         * **البحثُ في نصّ الرسائل لا في أسماء المحادثات.** كان صندوق البحث
         * يُرشّح القائمةَ بالجافاسكربت على الاسم والسطر الأخير وحدهما — فمن يبحث
         * عن رقم حسابٍ أُرسل قبل شهرين لا يجده إلا بالتمرير يدوياً في كل خيط.
         * والبحثُ محصورٌ بمحادثاتي: لا يبلغ أحدٌ برسالةِ بحثٍ ما ليس طرفاً فيه.
         */
        $q = trim(hub_str($r->query('q')));
        $hits = collect();
        if ($q !== '') {
            $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $q) . '%';
            $hits = DmMessage::alive()
                ->where(fn ($w) => $w->where('from_id', $me)->orWhere('to_id', $me))
                ->where('body', 'LIKE', $like)
                ->orderByDesc('created_at')->limit(60)->get()
                ->map(fn ($m) => ['msg' => $m, 'other' => $m->from_id === $me ? $m->to_id : $m->from_id]);
        }

        /*
         * **القائمةُ تُبنى على مستوى الخيط لا من شريحةٍ مسطّحة.**
         *
         * كانت تُجلب أحدثُ ٥٠٠ رسالةٍ ثم تُجمَّع — فخيطٌ ثرثارٌ واحد يبتلع النافذةَ
         * كلَّها: رسالةٌ غير مقروءةٍ أقدمُ تسقط من الحساب، فيقول الشريطُ «غير
         * مقروءة ٣» وتُظهر القائمةُ واحدة، ولا سبيلَ إلى فتح الباقي إلا بالصدفة.
         * والعدُّ يُحسب الآن على **كل** الصفوف بتجميعٍ في القاعدة، فيطابق
         * `unreadCount()` بالبناء لا بالاتفاق.
         */
        $unreadByThread = DmMessage::alive()->where('to_id', $me)->whereNull('read_at')
            ->select('thread_key', \Illuminate\Support\Facades\DB::raw('COUNT(*) c'))
            ->groupBy('thread_key')->pluck('c', 'thread_key');

        // أحدثُ خيطٍ أولاً — ٦٠ خيطاً لا ٥٠٠ رسالة، وغيرُ المقروء مضمومٌ دائماً
        $recentKeys = DmMessage::alive()->where(fn ($w) => $w->where('from_id', $me)->orWhere('to_id', $me))
            ->select('thread_key', \Illuminate\Support\Facades\DB::raw('MAX(created_at) last_at'))
            ->groupBy('thread_key')->orderByDesc('last_at')->limit(60)->pluck('thread_key');
        $keys = $recentKeys->merge($unreadByThread->keys())->unique()->values();

        // آخرُ رسالةٍ في كل خيطٍ مختار — صفٌّ واحدٌ لكل خيط لا نافذةٌ عامّة
        $threads = $keys->map(function ($key) use ($me, $unreadByThread) {
            $last = DmMessage::alive()->where('thread_key', $key)
                ->orderByDesc('created_at')->orderByDesc('id')->first();
            if (! $last) return null;

            return [
                'other'  => $last->from_id === $me ? $last->to_id : $last->from_id,
                'last'   => $last,
                'unread' => (int) ($unreadByThread[$key] ?? 0),
            ];
        })->filter()->sortByDesc(fn ($t) => (string) $t['last']->created_at)->values();

        $users = User::whereIn('id', $threads->pluck('other')->merge($hits->pluck('other')))
            ->pluck('name', 'id');
        $all = User::whereNull('deleted_at')->where('id', '!=', $me)
            ->where('status', 'نشط')->orderBy('name')->pluck('name', 'id');

        return view('dm.inbox', ['threads' => $threads, 'users' => $users, 'all' => $all,
            'open' => null, 'msgs' => collect(), 'other' => null, 'q' => $q, 'hits' => $hits,
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

        // المحذوفةُ تبقى في الخيط أثراً يقول «حُذفت رسالة» — المحادثةُ المبتورةُ
        // بلا تفسيرٍ تجعل الطرفَ الآخر يظنّ أنه أخطأ القراءة.
        // **أحدثُ ٣٠٠ لا أقدمُها**: الترتيب التصاعدي مع limit كان يُرجع أول ٣٠٠
        // رسالة في عمر الخيط — فمتى تجاوزها لا يظهر أي جديدٍ أبداً: المرسل لا يرى
        // رسالته بعد الإرسال، والمستلم لا يرى الوارد وقد خُتم مقروءاً أعلاه.
        $msgs = DmMessage::where('thread_key', $key)
            ->orderByDesc('created_at')->orderByDesc('id')->limit(300)->get()
            ->reverse()->values();

        // نفس الشاشة: قائمةُ المحادثات إلى جانب الخيط المفتوح — لا صفحتان منفصلتان
        $me = auth()->id();
        $all = DmMessage::alive()->where(fn ($w) => $w->where('from_id', $me)->orWhere('to_id', $me))
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
            'users' => User::whereIn('id', $ids)->pluck('name', 'id'), 'q' => '', 'hits' => collect(),
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

        $msg = DmMessage::create([
            'thread_key' => DmMessage::threadKey(auth()->id(), $other->id),
            'from_id'    => auth()->id(),
            'to_id'      => $other->id,
            'body'       => $data['body'],
            'att'        => $r->hasFile('att') ? $r->file('att')->store('hub', 'local') : null,
            'created_at' => now(),
        ]);

        // record_id بلا module: لا رابطَ يُبنى منه (الوجهة حوار لا سجل وحدة) —
        // لكنه يُمكّن سحبَ الرسالة من سحب إشعارها معها
        hub_notify($other->id, 'dm',
            '💬 رسالة من ' . auth()->user()->name . ': ' . trim($data['body']), null, $msg->id);

        return redirect()->route('dm.thread', $other->id)->withFragment('bottom');
    }

    /**
     * سحبُ رسالةٍ أُرسلت بالخطأ — **لصاحبها وحده**.
     *
     * لا يمحو أحدٌ كلام غيره: من تلقّى رسالةً لا يُخفيها عن نفسه ولا عن مُرسِلها،
     * وغريبٌ عن المحادثة لا يمسّها. والحذفُ **ناعم**: مكانُ الرسالة يبقى يقول
     * «حُذفت رسالة» بدل أن تختفي بلا تفسير فيظنّ الطرفُ الآخر أنه أخطأ القراءة.
     */
    public function destroy(string $id)
    {
        $m = DmMessage::findOrFail($id);
        abort_unless(in_array(auth()->id(), [$m->from_id, $m->to_id], true), 403,
            'لا شأن لك بهذه المحادثة');
        abort_unless($m->from_id === auth()->id(), 403,
            'الحذف لصاحب الرسالة وحده — لا يمحو أحدٌ كلام غيره');
        /*
         * **رسالةٌ في مكانه لا طردٌ من الصفحة**: كان `abort(503)` — و٥٠٣ رمزُ وضع
         * الصيانة لا رمزُ «ميزةٌ غير مهيّأة»، ولا صفحةَ له في المشروع فتُرجَم
         * صفحةً إنجليزيةً عارية ضاعت فيها الرسالة التي تسمّي العلاج. من ضغط زرّاً
         * في محادثةٍ يستحقّ سطراً يقرؤه وهو في مكانه.
         */
        if (! hub_has_col('dm_messages', 'deleted_at')) {
            return back()->with('err',
                'سحبُ الرسائل ميزةٌ جديدة تحتاج تحديث قاعدة البيانات — شغّل الترحيلات '
                . '(php artisan migrate أو من مركز التشغيل ⚙️) ثم أعد المحاولة. '
                . 'وبقيةُ النظام تعمل كالمعتاد.');
        }
        abort_if($m->deleted_at !== null, 422, 'حُذفت هذه الرسالة من قبل');

        $m->forceFill(['deleted_at' => now()])->save();
        hub_data_bump('dm_messages');

        // غايةُ السحب استرجاعُ ما أُرسل خطأً — وكان نصُّ الرسالة كاملاً (حتى ٥٩٠
        // حرفاً) يبقى في جرس المستلم بعد السحب. الإشعار يُسحب مع رسالته.
        \App\Models\HubNotification::where('kind', 'dm')->where('record_id', $m->id)->delete();

        return back()->with('ok', 'سُحبت الرسالة — يبقى مكانُها يقول إنها حُذفت');
    }

    /**
     * عدد غير المقروء للمستخدم الحالي — لشارة القائمة.
     *
     * **تعمل في كل صفحة**، فلا تعتمد على عمودٍ حديث بلا فحص: نشرٌ سبق هجرتَه
     * كان سيُعطي `Unknown column` في كل طلب فلا يفتح النظام كلُّه. و`hub_has_col`
     * مخبّأٌ فلا ثمنَ لهذا الأمان.
     */
    public static function unreadCount(): int
    {
        $q = DmMessage::where('to_id', auth()->id())->whereNull('read_at');
        if (hub_has_col('dm_messages', 'deleted_at')) $q->whereNull('deleted_at');

        return $q->count();
    }
}
