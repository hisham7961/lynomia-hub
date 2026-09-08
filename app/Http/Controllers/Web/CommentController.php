<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\Comment;
use App\Models\Conversation;
use App\Models\HubNotification;
use App\Models\Task;
use App\Models\User;
use App\Support\ChatCommands;
use App\Support\CommentService;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * محرك التعليقات: على أي سجل من أي وحدة + قناة الفريق العامة (module=feed).
 * منشن @اسم يولّد إشعاراً، الرد يُشعر صاحب التعليق، تثبيت، تحويل لمهمة، سجل قراءة.
 */
class CommentController extends Controller
{
    /** قناة الفريق — منشورات داخلية منطَّقةٌ بشركة القارئ (WP-A.5) */
    public function feed(Request $r)
    {
        $me = (string) auth()->id();
        $tab = in_array($t = hub_str($r->query('t', 'all')), ['all', 'me', 'pin'], true) ? $t : 'all';

        $posts = self::feedCompanyFilter(
            Comment::where('module', 'feed')->whereNull('parent_id')->with('user', 'replies.user')
                // «ما ذكرني»: قناةٌ تنمو تُغرق ما يعنيك — والذكرُ هو ما يعنيك
                ->when($tab === 'me', fn ($w) => self::mentioning($w, $me))
                ->when($tab === 'pin', fn ($w) => $w->where('pinned', true)))
            ->orderByDesc('pinned')->orderByDesc('created_at')
            ->paginate(15)->withQueryString();

        $this->markRead($posts->getCollection());

        // نبضُ القناة: قناةٌ صامتة تُقال لا تُخفى — والذكرُ يُعدّ كي لا يمرّ دون انتباه.
        // كلُّ عدّادٍ منطَّقٌ كالقائمة، فلا يُسرّب النبضُ عدداً من شركةٍ أخرى.
        $week = self::feedCompanyFilter(Comment::where('module', 'feed')->whereNull('parent_id')
            ->where('created_at', '>=', now()->subDays(7)))->get(['user_id']);

        return view('feed.index', [
            'posts' => $posts, 'users' => $this->userNames(), 'tab' => $tab,
            'pulse' => [
                'week'   => $week->count(),
                'people' => $week->pluck('user_id')->filter()->unique()->count(),
                'pinned' => self::feedCompanyFilter(
                    Comment::where('module', 'feed')->where('pinned', true))->count(),
                'mine'   => self::feedCompanyFilter(self::mentioning(
                    Comment::where('module', 'feed')->whereNull('parent_id'), $me))->count(),
            ],
            'presence' => DmController::presence(
                $posts->getCollection()->pluck('user_id')->filter()->unique()->values()->all()),
        ]);
    }

    /**
     * (WP-A.5 · SF-4/SF-5) نطاقُ الشركة على قناة الفريق — على السكّة نفسِها
     * (`hub_company_ids`) لا محرّكَ عزلٍ ثانٍ:
     *  • المستخدمُ المقيَّدُ بشركاتٍ يرى منشوراتِ شركاته + الإعلاناتِ العامة
     *    (`company_id` فارغ: منشورُ مالكٍ/غيرِ مقيَّد، أو منشورٌ قديمٌ قبل الهجرة).
     *  • غيرُ المقيَّد (المالك/بلا قائمةِ شركات) يرى الكلَّ كما كان.
     *  • العميلُ لا يبلغ القناةَ أصلاً (PortalGuard فوق هذا كلِّه).
     * والعمودُ حديث: قبل الهجرة لا يُنطَّق شيءٌ (القناةُ كما كانت) — نظيرُ `scopeAlive`.
     */
    protected static function feedCompanyFilter($q)
    {
        if (! hub_has_col('comments', 'company_id')) return $q;      // ما قبل الهجرة
        if (($cids = hub_company_ids()) === null) return $q;          // غيرُ مقيَّد يرى الكل

        return $q->where(fn ($w) => $w->whereIn('company_id', $cids)->orWhereNull('company_id'));
    }

    /** منشوراتٌ ذُكر فيها فلان — `mentions` عمودُ JSON يحمل قائمة المعرّفات */
    protected static function mentioning($q, string $uid)
    {
        return $q->whereJsonContains('mentions', $uid);
    }

    public function store(Request $r)
    {
        $data = $r->validate([
            'module'    => ['required', 'string', 'max:60'],
            'record_id' => ['nullable', 'string'],
            'parent_id' => ['nullable', 'exists:comments,id'],
            'body'      => ['required', 'string', 'max:4000'],
            // السقفُ الفعليّ لا رقمٌ مكتوبٌ بيدٍ هنا: كان ٥٠٠ م.ب ثابتةً في هذا
// المسار وحده، فتغييرُ الإعداد لا يمسّه — ومرفقُ التعليق يمرّ من
// البوابة نفسها التي تمرّ منها بقيّة المرفقات.
            'att'       => ['nullable', 'file', 'max:' . hub_upload_cap()['kb']],
            'mention'   => ['nullable', 'array'],
            'internal'  => ['nullable', 'boolean'],
        ]);

        // (WP-C.1) رسالةُ قناةٍ: تُنسَب لحاويتها عبر `conversation_id` — لا محرّكَ
        // رسائلَ ثانٍ. الحرسُ بالعضويّة (`guardConversation`) لا بمصفوفةِ وحدةٍ:
        // ردٌّ لا يُحقَن في قناةٍ لا يراها القارئ (٤٠٤)، والضيفُ لا يكتب (٤٠٣).
        $conversationId = null;
        $reqConv = trim(hub_str($r->input('conversation_id')));
        if (($data['module'] ?? '') === 'channel' || $reqConv !== '') {
            $convId = $reqConv !== '' ? $reqConv : (string) ($data['record_id'] ?? '');
            [$conv] = ConversationController::guardConversation($convId, 'post');
            [$module, $recordId] = ['channel', (string) $conv->id];
            $conversationId = (string) $conv->id;
        } else {
            [$module, $recordId] = $this->guardTarget($data['module'], $data['record_id'] ?? null);
        }

        // (WP-C.3 · §8) **أوامرُ المحادثة**: رسالةٌ تبدأ بـ/task ‏/issue ‏/assign تُنفَّذ
        // خدمةً حقيقيّةً لا نصّاً يُخزَّن — والحرسُ هنا فوق حرسِ السياقِ الذي مرّ توّاً:
        // السياقُ (القناة/السجل) مضمونُ الرؤية سلفاً (guardConversation/guardTarget)، ثم
        // يفرض المُوزِّعُ `hub_can(op)` على الوحدةِ الهدف. مجهولٌ → ٤٢٢، غيرُ مصرّحٍ → ٤٠٣،
        // ولا نصٌّ يُخزَّن في أيّ الحالتين (لا نجاحٌ زائف). الأمرُ فعلٌ رأسيّ لا ردٌّ على خيط.
        if (empty($data['parent_id']) && ($parsed = ChatCommands::parse($data['body'])) !== null) {
            $res = ChatCommands::dispatch($parsed, auth()->user(), [
                'module'          => $module,
                'record_id'       => $recordId,
                'conversation_id' => $conversationId,
            ]);

            return back()->with('ok', $res['message'])->withFragment('c-' . $res['backlink']->id);
        }

        // **الردُّ يلتصق بخيطه** — قبل رفعِ أيّ مرفقٍ (نظيرُ الترتيب الأصليّ: لا يُخزَّن
        // مرفقٌ لطلبٍ سيُرفَض)، عبر الحارسِ المشترك (Critic F2).
        CommentService::assertReplyIntegrity($data['parent_id'] ?? null, $module, $recordId);

        // **الفرعُ العاديُّ** يُنشأ عبر `CommentService::create` (سكّةٌ تعيد الموديل ·
        // Critic F2) — نفسُ المنشن وبناءِ الصفِّ و`notifyAround` حرفاً بحرف، يشترك فيها
        // الويبُ والجوال. الويبُ يترجم النتيجةَ إعادةَ توجيه.
        $c = CommentService::create(auth()->user(), $module, $recordId, $data['body'], [
            'parent_id'       => $data['parent_id'] ?? null,
            'att'             => $r->hasFile('att') ? $r->file('att')->store('hub', 'local') : null,
            'internal'        => $r->boolean('internal'),
            'mention'         => (array) $r->input('mention', []),
            'conversation_id' => $conversationId,
        ]);

        return back()->with('ok', 'نُشر التعليق')->withFragment('c-' . $c->id);
    }

    /** تثبيت/فك تثبيت — لمن يملك تعديل الوحدة (وللقناة: المالك أو صاحب علم monitor) */
    public function pin(string $id)
    {
        $c = Comment::findOrFail($id);
        // تنطيقُ السجل الأمّ أولاً كما في toTask: تثبيتٌ على سجلٍ خارج النطاق
        // كان يمرّ بمجرد امتلاك «تعديل» الوحدة دون فحص أن السجل نفسه مرئيّ
        $this->guardTarget($c->module, $c->record_id);
        $can = match ($c->module) {
            'feed'    => hub_monitor(),
            // تثبيتُ رسالةِ قناةٍ لأصحابها ومشرفيها — الدورُ عضويّةٌ لا مصفوفة
            'channel' => Conversation::roleCanManage(Conversation::roleOf($c->record_id, (string) auth()->id())),
            default   => hub_can(auth()->user(), $c->module, 'e'),
        };
        abort_unless($can, 403);

        $c->update(['pinned' => ! $c->pinned, 'updated_at' => now()]);

        return back()->with('ok', $c->pinned ? 'ثُبّت' : 'أُلغي التثبيت');
    }

    /**
     * حلّ التعليق وعكسه (v2.123): نقاشٌ عولج يُعلَّم «محلولاً» فيهدأ بصرياً دون
     * حذف أثره — لصاحب التعليق أو من يملك تعديل وحدة السجل.
     */
    public function resolve(string $id)
    {
        $c = Comment::findOrFail($id);
        // السجلُّ الأمّ ضمن النطاق أولاً — ثم صاحبُ التعليق أو من يملك تعديله
        $this->guardTarget($c->module, $c->record_id);
        $can = $c->user_id === auth()->id() || match ($c->module) {
            'feed'    => hub_monitor(),
            'channel' => Conversation::roleCanManage(Conversation::roleOf($c->record_id, (string) auth()->id())),
            default   => hub_can(auth()->user(), $c->module, 'e'),
        };
        abort_unless($can, 403);

        $done = $c->resolved_at === null;
        $c->update(['resolved_at' => $done ? now() : null,
            'resolved_by' => $done ? auth()->id() : null, 'updated_at' => now()]);

        return back()->with('ok', $done ? 'عُلّم التعليق محلولاً' : 'أُعيد التعليق مفتوحاً');
    }

    /** حذف — صاحب التعليق أو المالك */
    public function destroy(string $id)
    {
        $c = Comment::findOrFail($id);
        abort_unless($c->user_id === auth()->id() || hub_is_owner(), 403);
        $c->delete();

        return back()->with('ok', 'حُذف التعليق');
    }

    /** تحويل تعليق إلى مهمة — يرث مشروع السجل الأصلي إن وُجد */
    public function toTask(string $id)
    {
        abort_unless(hub_can(auth()->user(), 'tasks', 'a'), 403, 'تحويل التعليقات لمهام يتطلب صلاحية إضافة مهام');
        $c = Comment::findOrFail($id);
        /*
         * **هدفُ التعليق يُفحص كما في كل فعلٍ عليه**: كان يكفي امتلاكُ «إضافة
         * مهام» لتحويل **أي** تعليق — ونصُّ التعليق يُنسخ حرفياً في وصف المهمة.
         * فمن لا يملك الموارد البشرية يقرأ تعليقاً على ملفٍّ وظيفيّ بتحويله.
         * من يرى السجل يحوّل تعليقه، ولا أحد سواه.
         */
        $this->guardTarget($c->module, $c->record_id);
        abort_if($c->task_id, 422, 'حُوّل هذا التعليق لمهمة من قبل');

        // مشروع المهمة: من عمود مشروع السجل الأصلي إن وُجد
        $projectId = null;
        if ($c->record_id && $c->module !== 'feed' && ($md = hub_mod($c->module)) && ($col = hub_project_col($c->module))) {
            $projectId = \Illuminate\Support\Facades\DB::table($md['table'])->where('id', $c->record_id)->value($col);
        }

        // وراثةُ الشركة والعميل من السجل الأصل أو من نطاق المحوِّل (v2.399): المعزولُ كان
        // يُنشئ مهمةً بلا شركةٍ فلا يراها هو نفسُه بعد ثانية.
        $inherit = [];
        if ($c->record_id && $c->module !== 'feed' && ($md0 = hub_mod($c->module))) {
            foreach (['company_id' => hub_company_col($c->module), 'client_id' => hub_client_col($c->module)] as $k => $col) {
                if ($col && hub_has_col('tasks', $k)) {
                    $inherit[$k] = \Illuminate\Support\Facades\DB::table($md0['table'])->where('id', $c->record_id)->value($col);
                }
            }
        }
        if (empty($inherit['company_id']) && hub_has_col('tasks', 'company_id') && ($cids = hub_company_ids()) !== null && $cids) $inherit['company_id'] = $cids[0];
        if (empty($inherit['client_id']) && hub_has_col('tasks', 'client_id') && ($kids = hub_client_ids()) !== null && $kids) $inherit['client_id'] = $kids[0];

        $task = Task::create(array_filter($inherit) + [
            'title'       => Str::limit(trim(preg_replace('/\s+/u', ' ', $c->body)), 70),
            'project_id'  => $projectId,
            'assignee_id' => $c->mentions[0] ?? $c->user_id,
            'status'      => 'جديدة',
            'description' => $c->body . "\n\n— حُوّلت من تعليق بواسطة " . auth()->user()->name,
        ]);
        $c->update(['task_id' => $task->id, 'updated_at' => now()]);

        if ($task->assignee_id && $task->assignee_id !== auth()->id()) {
            $this->notify($task->assignee_id, 'assign',
                'أُسندت إليك مهمة من تعليق: ' . Str::limit($task->title, 60) . ' — بواسطة ' . auth()->user()->name,
                'tasks', $task->id);
        }

        return back()->with('ok', 'أُنشئت مهمة من التعليق');
    }

    /** تفاعل على تعليق: إيموجي واحد لكل مستخدم لكل رمز — الضغط ثانيةً يزيله */
    public function react(Request $r, string $id)
    {
        $c = Comment::findOrFail($id);
        $this->guardTarget($c->module, $c->record_id);          // يرى السجل = يتفاعل

        $emoji = hub_str($r->input('emoji'));
        abort_unless(in_array($emoji, self::REACTIONS, true), 422, 'تفاعل غير معروف');

        $q = \Illuminate\Support\Facades\DB::table('reactions')
            ->where('comment_id', $c->id)->where('user_id', auth()->id())->where('emoji', $emoji);

        if ($q->exists()) {
            $q->delete();
        } else {
            try {
                \Illuminate\Support\Facades\DB::table('reactions')->insert([
                    'id' => (string) Str::uuid(), 'comment_id' => $c->id,
                    'user_id' => auth()->id(), 'emoji' => $emoji, 'created_at' => now(),
                ]);
            } catch (\Illuminate\Database\QueryException $e) {
                // ضغطتان متزامنتان — القيد الفريد حسمها
            }
            if ($c->user_id !== auth()->id()) {
                $this->notify($c->user_id, 'react',
                    $emoji . ' تفاعل ' . auth()->user()->name . ' مع ' . ($c->module === 'feed' ? 'منشورك' : 'تعليقك') . ': ' . Str::limit(trim($c->body), 50),
                    $c->module, $c->record_id);
            }
        }

        return back()->withFragment('c-' . $c->id);
    }

    /** التفاعلات المتاحة — قائمة مغلقة كي لا تُحقن رموز عشوائية */
    public const REACTIONS = ['👍', '❤️', '🎉', '😂', '🤔', '🙏'];

    /** تفاعلات مجموعة تعليقات: [comment_id => [emoji => [أسماء]]] */
    public static function reactionsFor($comments): array
    {
        $ids = collect($comments)->flatMap(fn ($c) => [$c->id, ...($c->relationLoaded('replies') ? $c->replies->pluck('id') : [])]);
        if ($ids->isEmpty()) return [];

        $rows = \Illuminate\Support\Facades\DB::table('reactions')
            ->join('users', 'users.id', '=', 'reactions.user_id')
            ->whereIn('comment_id', $ids)->get(['comment_id', 'emoji', 'users.name', 'reactions.user_id']);

        $out = [];
        foreach ($rows as $r) $out[$r->comment_id][$r->emoji][] = ['name' => $r->name, 'id' => $r->user_id];

        return $out;
    }

    /* ────────── أدوات مشتركة (يستخدمها أيضاً عرض الوحدات) ────────── */

    /** تعليقات سجل مع الردود، ويسجَّل أن المستخدم الحالي قرأها */
    public static function forRecord(string $module, string $recordId)
    {
        $items = Comment::where('module', $module)->where('record_id', $recordId)
            ->whereNull('parent_id')->with('user', 'replies.user')
            ->orderByDesc('pinned')->orderBy('created_at')->get();

        (new self)->markRead($items);

        return $items;
    }

    /** أسماء المستخدمين للمنشن [id => name] */
    public static function userNames(): array
    {
        return User::whereNull('deleted_at')->orderBy('name')->pluck('name', 'id')->all();
    }

    /* ────────── داخلي ────────── */

    /**
     * التحقق من هدف التعليق وصلاحية رؤيته — يفوّض إلى `CommentService::guardTarget`
     * (نقطةُ التخويلِ الوحيدة، مشتركةٌ مع الجوال · Critic F2). السلوكُ غيرُ متغيّر.
     */
    protected function guardTarget(string $module, ?string $recordId): array
    {
        return CommentService::guardTarget(auth()->user(), $module, $recordId);
    }

    /** إشعارٌ حول التعليق — يفوّض إلى الخدمة (يشترك فيه toTask/react والويب/الجوال) */
    protected function notify(string $uid, string $kind, string $text, ?string $module, ?string $recordId): void
    {
        CommentService::notify($uid, $kind, $text, $module, $recordId);
    }

    /** غلافٌ عامٌّ لسجل القراءة — تستدعيه شاشةُ القناة (نفسُ سكّة read_by) */
    public function markReadPublic($comments): void
    {
        $this->markRead($comments);
    }

    /** سجل القراءة: يُضاف المستخدم الحالي لمن قرأ (التعليقات والردود المعروضة) */
    protected function markRead($comments): void
    {
        $me = auth()->id();
        // **استعلامٌ واحد** (PERF-04, v2.399): فتحُ سجلٍّ عليه خمسون تعليقاً غيرَ مقروء كان يكتب
        // خمسين UPDATE في طلب GET. تُجمَع القيمُ الجديدة وتُكتب بجملة CASE واحدة — محمولةٌ على المحرّكين.
        $pending = [];
        foreach ($comments as $c) {
            foreach ([$c, ...($c->relationLoaded('replies') ? $c->replies : [])] as $one) {
                $rb = (array) $one->read_by;
                if (! in_array($me, $rb, true)) {
                    $one->read_by = [...$rb, $me];
                    $pending[(string) $one->id] = json_encode([...$rb, $me], JSON_UNESCAPED_UNICODE);
                }
            }
        }
        if (! $pending) return;
        try {
            $case = ''; $bind = [];
            foreach ($pending as $id => $json) { $case .= ' WHEN ? THEN ?'; $bind[] = $id; $bind[] = $json; }
            $ids = array_keys($pending);
            \Illuminate\Support\Facades\DB::update(
                'UPDATE comments SET read_by = CASE id' . $case . ' END WHERE id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')',
                array_merge($bind, $ids)
            );
        } catch (\Throwable $e) {
            report($e);   // إيصالُ القراءة إثراءٌ — لا يكسر عرض التعليقات
        }
    }
}
