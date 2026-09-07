<?php

namespace App\Support;

use App\Http\Controllers\Web\CommentController;
use App\Models\Comment;
use App\Models\Conversation;
use App\Models\Issue;
use App\Models\Task;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * **أوامرُ المحادثة** (Work OS · الطور C · WP-C.3 · §8).
 *
 * رسالةٌ تبدأ بـ`/task` `/issue` `/assign` ليست نصّاً يُخزَّن — بل **أمرٌ يُنفَّذ
 * خدمةً حقيقيّة**. يمتدّ هذا المُوزِّعُ نمطَ `CommentController@toTask` حرفاً بحرف:
 *
 *  • **خدمةٌ حقيقيّة لا وهم:** `Task::create` / `Issue::create` — لا محرّكَ أوامرَ ثانٍ،
 *    ولا مهمةٍ زائفة. الوحداتُ هي الوحداتُ نفسُها التي يكتبها `ModuleController`.
 *  • **`hub_can(op)` على الوحدةِ الهدف أولاً:** ‏/task و/assign يتطلبان `tasks:a`،
 *    و/issue يتطلب `issues:a` — فمن لا يملك إضافةَ مهامٍ لا يخلقها بأمرٍ في قناة.
 *  • **نطاقُ السياق مضمونٌ سلفاً:** المتحكّمُ يمرّ بـ`guardConversation`/`guardTarget`
 *    على السياق (القناة/السجل) **قبل** أن يبلغ هذا المُوزِّع، فأمرٌ في قناةٍ لا يراها
 *    القارئ لا يبلغ هنا أصلاً (٤٠٤ في المتحكّم). والوحدةُ المُنشأةُ ترثُ شركةَ/عميلَ/
 *    مشروعَ السياق فتبقى ضمن نطاقِ آمِرها (نظيرُ وراثةِ `toTask`).
 *  • **أثرُ تدقيقٍ + حدثُ الوحدةِ القائم + رسالةُ ربط:** كلُّ تنفيذٍ يكتب `hub_audit`،
 *    ويُطلق `FlowRunner::fire('created', ...)` (الحدثُ نفسُه الذي يُطلقه إنشاءُ السجل
 *    من الشاشة)، ويُنشئ رسالةَ ربطٍ (back-link) في السياق تشير إلى ما أُنشئ.
 *  • **الرفضُ صريحٌ لا صامت:** أمرٌ مجهولٌ → ٤٢٢، وغيرُ مصرّحٍ → ٤٠٣، وإسنادٌ بلا مسندٍ
 *    → ٤٢٢ — لا نجاحٌ زائف، ولا أمرٌ يُبتلع.
 */
class ChatCommands
{
    /** الأوامرُ المعروفة — allowlist في التطبيق؛ ما عداها يُرفض صراحةً */
    public const COMMANDS = ['task', 'issue', 'assign'];

    /**
     * هل النصُّ **محاولةُ أمر**؟ يُرجع `['cmd' => ..., 'args' => ...]` أو `null` لرسالةٍ
     * عاديّة. شكلُ الأمر: `/كلمة` تتبعها فراغٌ أو نهايةُ النص — فـ`/etc/passwd`
     * (يتبع `etc` مائلٌ لا فراغ) يبقى رسالةً عاديّةً لا يُختطَف، بينما `/foo x`
     * محاولةُ أمرٍ (وإن مجهولاً، فيُرفض صراحةً في `dispatch`).
     */
    public static function parse(string $body): ?array
    {
        $b = trim($body);
        if ($b === '' || $b[0] !== '/') return null;
        if (! preg_match('#^/([A-Za-z][A-Za-z0-9_-]*)(?:\s+([\s\S]+))?$#u', $b, $m)) return null;

        return ['cmd' => mb_strtolower($m[1]), 'args' => isset($m[2]) ? trim($m[2]) : ''];
    }

    /**
     * ينفّذ أمراً مُحلَّلاً بعد التحقّق. يُجهض (٤٠٣/٤٢٢) عند الفشل، ويُرجع نتيجةً عند
     * النجاح: `['message' => نصٌّ للمستخدم, 'model' => Task|Issue, 'backlink' => Comment]`.
     *
     * @param array{cmd:string,args:string}                                 $parsed
     * @param array{module:string,record_id:?string,conversation_id:?string} $context سياقُ الرسالة — مضمونُ النطاق سلفاً
     */
    public static function dispatch(array $parsed, User $user, array $context): array
    {
        $cmd = $parsed['cmd'];
        $args = trim($parsed['args'] ?? '');

        // أمرٌ مجهولٌ — رفضٌ صريحٌ لا صامت (لا يُخزَّن كرسالة، ولا نجاحٌ زائف)
        abort_unless(in_array($cmd, self::COMMANDS, true), 422,
            "أمرٌ غيرُ معروف: /{$cmd} — الأوامرُ المتاحة: /task ‏/issue ‏/assign");

        return match ($cmd) {
            'task'   => self::runTask($user, $args, $context),
            'assign' => self::runAssign($user, $args, $context),
            'issue'  => self::runIssue($user, $args, $context),
        };
    }

    /* ────────── الأوامرُ الثلاثة ────────── */

    /** ‏/task «عنوان» — مهمةٌ حقيقيّة، المسؤولُ أوّلُ مذكورٍ أو الآمِر */
    private static function runTask(User $user, string $args, array $context): array
    {
        abort_unless(hub_can($user, 'tasks', 'a'), 403, 'الأمرُ /task يتطلب صلاحيةَ إضافةِ مهام');

        [$title, $mentions] = self::titleAndMentions($args);
        abort_if($title === '', 422, 'الأمرُ /task يتطلب عنواناً — مثال: ‏/task إصلاحُ الدخول');

        $assignee = $mentions[0] ?? (string) $user->getKey();

        return self::makeTask($user, $title, $assignee, $context, 'task');
    }

    /** ‏/assign @اسم «عنوان» — مهمةٌ مُسنَدةٌ صراحةً؛ بلا مسندٍ صالحٍ تُرفض */
    private static function runAssign(User $user, string $args, array $context): array
    {
        abort_unless(hub_can($user, 'tasks', 'a'), 403, 'الأمرُ /assign يتطلب صلاحيةَ إضافةِ مهام');

        // الإسنادُ يذكر مسنداً إليه صراحةً (لا استبعادَ الذات — «أسنِد لي» مشروع)
        [$title, $mentions] = self::titleAndMentions($args, false);
        abort_if(empty($mentions), 422, 'الأمرُ /assign يتطلب تحديدَ المسند إليه (@اسم)');
        abort_if($title === '', 422, 'الأمرُ /assign يتطلب عنواناً بعد المسند إليه');

        return self::makeTask($user, $title, $mentions[0], $context, 'assign');
    }

    /** ‏/issue «عنوان» — مشكلةٌ حقيقيّة */
    private static function runIssue(User $user, string $args, array $context): array
    {
        abort_unless(hub_can($user, 'issues', 'a'), 403, 'الأمرُ /issue يتطلب صلاحيةَ إضافةِ مشاكل');

        [$title] = self::titleAndMentions($args);
        abort_if($title === '', 422, 'الأمرُ /issue يتطلب عنواناً — مثال: ‏/issue تسرّبٌ محتمل');

        $inherit = self::inheritScope($user, $context, 'issues');

        $issue = Issue::create(array_filter($inherit) + [
            'title'  => mb_substr($title, 0, 200),
            'kind'   => 'مشكلة',
            'status' => 'مفتوحة',
            'cause'  => 'أُنشئت من أمرِ محادثة بواسطة ' . $user->name,
        ]);

        FlowRunner::fire('created', 'issues', $issue);
        hub_audit('command.issue', 'issues', (string) $issue->id, $issue->title, ['via' => self::ctxLabel($context)]);

        // لا عمودَ issue_id على comments — فالربطُ نصٌّ يحمل مسارَ المشكلةِ ومعرّفَها
        $link = url('/m/issues/' . $issue->id);
        $back = self::postMessage($user, "🐞 أُنشئت مشكلة «{$issue->title}» من أمرِ محادثة — {$link}", $context);

        return ['message' => 'أُنشئت مشكلة من الأمر', 'model' => $issue, 'backlink' => $back];
    }

    /* ────────── مشترَكٌ داخليّ ────────── */

    /** يخلق المهمةَ ويُطلق حدثَها ويكتب أثرَها ويُنشئ ربطَها ويُشعر المسند إليه */
    private static function makeTask(User $user, string $title, ?string $assignee, array $context, string $kind): array
    {
        $inherit = self::inheritScope($user, $context, 'tasks');

        $task = Task::create(array_filter($inherit) + [
            'title'       => mb_substr($title, 0, 200),
            'assignee_id' => $assignee,
            'status'      => 'جديدة',
            'description' => 'أُنشئت من أمرِ محادثة (/' . $kind . ') بواسطة ' . $user->name,
        ]);

        FlowRunner::fire('created', 'tasks', $task);
        hub_audit('command.' . $kind, 'tasks', (string) $task->id, $task->title, ['via' => self::ctxLabel($context)]);

        // إشعارُ المسند إليه — نفسُ سكّة toTask/hub_notify، وبنفسِ نوعِ الإشعار 'assign'
        if ($assignee && $assignee !== (string) $user->getKey()) {
            hub_notify($assignee, 'assign',
                'أُسندت إليك مهمةٌ من أمرِ محادثة: ' . Str::limit($task->title, 60) . ' — بواسطة ' . $user->name,
                'tasks', $task->id);
        }

        $verb = $kind === 'assign' ? 'أُسندت مهمة' : 'أُنشئت مهمة';
        $back = self::postMessage($user, "🗂️ {$verb} «{$task->title}» من أمرِ محادثة.", $context, ['task_id' => $task->id]);

        return ['message' => $verb . ' من الأمر', 'model' => $task, 'backlink' => $back];
    }

    /**
     * رسالةُ ربطٍ (أو رسالةُ افتتاحٍ عاديّة) في السياق — تعليقٌ على المحرّكِ الوحيد
     * `comments`، لا جدولَ رسائلَ ثانٍ. تُنسَب للحاويةِ عبر `conversation_id` كما يفعل
     * `CommentController@store` تماماً، وتُوسَم بشركةِ ناشرها في قناةِ الفريق (feed).
     */
    public static function postMessage(User $user, string $body, array $context, array $extra = []): Comment
    {
        $module = (string) ($context['module'] ?? 'feed');
        $recordId = $context['record_id'] ?? null;
        $conversationId = $context['conversation_id'] ?? null;

        $attrs = $extra + [
            'module'     => $module,
            'record_id'  => $recordId,
            'user_id'    => $user->getKey(),
            'body'       => $body,
            'read_by'    => [(string) $user->getKey()],
            'created_at' => now(),
        ];

        if ($conversationId !== null && hub_has_col('comments', 'conversation_id')) {
            $attrs['conversation_id'] = (string) $conversationId;
        }

        // وسمُ الشركة لمنشورِ قناةِ الفريق فقط (نظيرُ CommentController@store) —
        // خيوطُ القنوات تُعزَل بالحاوية لا بهذا العمود.
        if ($module === 'feed' && hub_has_col('comments', 'company_id')
            && ($cids = hub_company_ids($user)) !== null && $cids) {
            $attrs['company_id'] = $cids[0];
        }

        return Comment::create($attrs);
    }

    /**
     * عنوانُ الأمرِ ومذكوروه: يُستخرج المذكورون (@اسم) بمطابقةِ بادئةِ الاسم — نظيرُ
     * `CommentController::extractMentions` — ويُنظَّف العنوانُ من رموزهم. `$excludeSelf`
     * يُستبعد الآمِرُ في /task (لا يُذكَر نفسَه) ويُبقى في /assign («أسنِد لي»).
     *
     * @return array{0:string,1:array<int,string>} [العنوان, معرّفاتُ المذكورين]
     */
    private static function titleAndMentions(string $args, bool $excludeSelf = true): array
    {
        $users = CommentController::userNames();

        $ids = [];
        if (preg_match_all('/@([\p{Arabic}\w]+)/u', $args, $m)) {
            foreach ($m[1] as $token) {
                foreach ($users as $uid => $name) {
                    if (mb_stripos($name, $token) === 0) { $ids[] = (string) $uid; break; }
                }
            }
        }
        if ($excludeSelf) $ids = array_diff($ids, [(string) auth()->id()]);
        $ids = array_values(array_unique($ids));

        // العنوانُ: النصُّ بلا رموزِ المنشن، بمسافاتٍ مضغوطة
        $title = trim(preg_replace('/\s+/u', ' ', preg_replace('/@[\p{Arabic}\w]+/u', ' ', $args)));

        return [$title, $ids];
    }

    /**
     * يرثُ شركةَ/عميلَ/مشروعَ السياقِ فتبقى الوحدةُ المُنشأةُ ضمن نطاقِ آمِرها (نظيرُ
     * وراثةِ `toTask`): من الحاويةِ إن كان السياقُ قناةً، ومن السجلِ الأصلِ إن كان
     * خيطَ سجل، ومن نطاقِ الآمِرِ إن بقي فارغاً. تُرشَّح بأعمدةِ الجدولِ الهدفِ الموجودةِ فقط.
     */
    private static function inheritScope(User $user, array $context, string $targetModule): array
    {
        $module = (string) ($context['module'] ?? '');
        $recordId = $context['record_id'] ?? null;
        $conversationId = $context['conversation_id'] ?? null;

        $company = $client = $project = null;

        if ($module === 'channel' && $conversationId) {
            $conv = Conversation::find($conversationId);
            $company = $conv?->company_id;
            $client  = $conv?->client_id;
            $project = $conv?->project_id;
        } elseif ($module !== '' && $module !== 'feed' && $recordId && ($md = hub_mod($module))) {
            $table = $md['table'];
            if ($ccol = hub_company_col($module)) $company = DB::table($table)->where('id', $recordId)->value($ccol);
            if ($kcol = hub_client_col($module))  $client  = DB::table($table)->where('id', $recordId)->value($kcol);
            if ($pcol = hub_project_col($module)) $project = DB::table($table)->where('id', $recordId)->value($pcol);
        }

        // احتياطاً: نطاقُ الآمِرِ المقيَّدِ — كي لا يخلق مهمةً بلا شركةٍ فلا يراها بعد ثانية
        if (empty($company) && ($cids = hub_company_ids($user)) !== null && $cids) $company = $cids[0];
        if (empty($client)  && ($kids = hub_client_ids($user)) !== null && $kids)  $client  = $kids[0];

        $out = [];
        if (hub_has_col($targetModule, 'company_id')) $out['company_id'] = $company;
        if (hub_has_col($targetModule, 'client_id'))  $out['client_id']  = $client;
        if (hub_has_col($targetModule, 'project_id')) $out['project_id'] = $project;

        return $out;
    }

    /** وسمُ السياقِ في أثرِ التدقيق — أين صدر الأمر */
    private static function ctxLabel(array $context): string
    {
        if (($context['module'] ?? '') === 'channel' && ! empty($context['conversation_id'])) {
            return 'channel:' . $context['conversation_id'];
        }
        if (! empty($context['module']) && ! empty($context['record_id'])) {
            return $context['module'] . ':' . $context['record_id'];
        }

        return (string) ($context['module'] ?? 'feed');
    }
}
