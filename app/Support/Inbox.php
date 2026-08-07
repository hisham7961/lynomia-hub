<?php

namespace App\Support;

use App\Support\ErrorLog;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * الصندوق الموحّد — ما ينتظر تصرّفي، صفّاً واحداً مرتّباً بالإلحاح.
 *
 * كان الانتظار موزّعاً على أحد عشر جدولاً: موافقةٌ في وحدة، وطلبٌ داخلي في
 * أخرى، وسياسةٌ تنتظر إقراري في ثالثة، والتزامٌ تعاقديّ عليّ في رابعة. البوابة
 * كانت تعرض بعضها في **صناديق منفصلة** لكلٍّ منها سقفُه — فلا تعرف أيُّ الأشياء
 * أعجل: متأخرٌ منذ أسبوع في صندوق، أم ما يستحق بعد شهرٍ في الصندوق المجاور.
 *
 * هذا الجامع يقرأ المصادر كلها، يوحّد شكلها، ويرتّبها بحاويةٍ واحدة:
 * متأخر ← اليوم ← هذا الأسبوع ← لاحقاً ← بلا موعد. والعدّ في الشريط الجانبي
 * من المتأخر والمستحق اليوم وحدهما — عدّادٌ يشمل كل شيء لا يُنظر إليه.
 *
 * وكل مصدرٍ خلف `hub_can` ونطاقه: الصندوق يجمع، ولا يفتح باباً مغلقاً.
 */
class Inbox
{
    public const TTL = 60;

    /** الجداول التي يقرؤها الصندوق — ختمُها جزءٌ من مفتاحه فلا يعرض ما بطل */
    public const TABLES = ['roles', 'users', 'approvals', 'record_acks', 'sign_requests', 'policies', 'policy_acks',
        'kb_articles', 'internal_requests', 'leave_requests', 'tasks', 'decisions',
        // employees: يُقرأ للوثائق الشخصية (إقامةٌ تنتهي) وكان غائباً عن الختم —
        // فتعديلُ الملف لا يُبطل الخبيئة ويبقى المُنجَز معروضاً حتى تنتهي المهلة
        'employees', 'tickets', 'meetings', 'contract_obligations', 'compliance_items',
        // assets: مصدرُ الإقرارات (Acks::pending) يقرأ عهدَ الأصول عبر holder_id،
        // وكان غائباً عن الختم — فعهدةٌ جديدة تنتظر إقراري لا تُبطل الصندوق
        'assets'];

    /** الحاويات مرتّبةً — الرقم هو الترتيب */
    public const BUCKETS = [
        0 => ['label' => 'متأخر',        'icon' => '🔴', 'tone' => 'bad'],
        1 => ['label' => 'اليوم',        'icon' => '🟠', 'tone' => 'wn'],
        2 => ['label' => 'هذا الأسبوع',  'icon' => '🟡', 'tone' => 'wn'],
        3 => ['label' => 'لاحقاً',       'icon' => '⚪', 'tone' => ''],
        4 => ['label' => 'بلا موعد',     'icon' => '⚫', 'tone' => ''],
    ];

    /**
     * كل ما ينتظر تصرّف المستخدم، مرتّباً.
     *
     * مخبّأ ببصمة النطاق: هذا القارئ يقرأ ثلاثة عشر مصدراً، وشارةُ الشريط
     * الجانبي تستدعيه في **كل صفحة** — فحسابُه في كل طلبٍ يجعل ثمنَ فتح أي
     * شاشةٍ ثمنَ فتح البوابة كاملةً.
     */
    public static function items($user = null): array
    {
        $user = $user ?? auth()->user();
        if (! $user) return [];

        return hub_cached('inbox:items:' . ($user->role_id ?? '0') . ':' . $user->id
            . hub_data_stamp(self::TABLES),
            self::TTL, (bool) request()->query('fresh'), fn () => self::build($user));
    }

    protected static function build($user): array
    {
        $out = [];
        foreach (['approvals', 'acks', 'signatures', 'policies', 'mustRead', 'requests',
                  'leaves', 'tasks', 'decisions', 'tickets', 'meetings', 'obligations', 'compliance'] as $src) {
            try {
                foreach (self::{$src}($user) as $one) $out[] = self::stamp($one);
            } catch (\Throwable $e) {
                // مصدرٌ سقط لا يُسقط الصندوق كله — البوابة أهمّ من أيّ صفٍّ فيها
                // الصنف `App\Models\ErrorLog` لا وجود له — فكانت المِصْيدة نفسها
                // ترمي `Class not found` وتُسقط الصندوق: حارسٌ يقتل من يحرسه
                ErrorLog::capture('php', "inbox: سقط مصدر {$src} — " . $e->getMessage(),
                    __FILE__, __LINE__);
            }
        }

        usort($out, fn ($a, $b) => [$a['bucket'], $a['due'] ?? '9999', $a['label']]
                              <=> [$b['bucket'], $b['due'] ?? '9999', $b['label']]);

        return $out;
    }

    /** عدد ما لا يحتمل التأجيل — للشارة في الشريط الجانبي */
    public static function count($user = null): int
    {
        $user = $user ?? auth()->user();
        if (! $user) return 0;

        // من خبيئة الصفّ نفسه: عدٌّ منفصلٌ كان يعيد بناء الصندوق كاملاً مرةً أخرى
        return collect(self::items($user))->where('bucket', '<=', 1)->count();
    }

    /** ملخّصٌ بالحاويات — للترويسة */
    public static function summary(array $items): array
    {
        $by = collect($items)->groupBy('bucket');

        return collect(self::BUCKETS)->map(fn ($b, $k) => $b + ['n' => $by->get($k, collect())->count()])
            ->filter(fn ($b) => $b['n'] > 0)->all();
    }

    /* ────────── المصادر ────────── */

    /** موافقاتٌ أنا معتمِدها ولم تُحسم — والحسم من الصندوق مباشرةً */
    protected static function approvals($u): array
    {
        if (! Schema::hasTable('approvals') || ! hub_can($u, 'approvals', 'v')) return [];

        return hub_scope(DB::table('approvals')->whereNull('deleted_at'), 'approvals', $u)
            ->where(fn ($w) => $w->where('approver_id', $u->id)->orWhere('chain', 'LIKE', '%"' . $u->id . '"%'))
            ->tap(fn ($q) => hub_open_scope($q, 'status', ['موافق', 'موافقة', 'معتمد', 'معتمدة']))
            ->orderByRaw('due IS NULL, due')->limit(40)
            ->get(['id', 'title', 'type', 'amount', 'currency', 'due', 'mod', 'record_id'])
            ->map(fn ($a) => [
                'kind' => 'approval', 'icon' => '🔏', 'label' => 'موافقة',
                'title' => $a->title, 'due' => $a->due,
                'why' => trim(($a->type ?: 'طلب اعتماد')
                    . ($a->amount ? ' · ' . number_format((float) $a->amount, 0) . ' ' . $a->currency : '')),
                'url' => route('m.show', ['approvals', $a->id]),
                // الحسم بضغطة: مَن ينتظر منه قرارٌ لا يُفترض أن يتنقّل بين ثلاث شاشات
                'act' => hub_flag($u, 'approve') || hub_is_owner($u)
                    ? ['ok' => route('approvals.approve', $a->id), 'no' => route('approvals.reject', $a->id)]
                    : null,
            ])->all();
    }

    /** توقيعاتٌ أرسلتُها ولم تُوقَّع — انتظارٌ يخصّني أنا لا الموقِّع */
    protected static function signatures($u): array
    {
        if (! Schema::hasTable('sign_requests')) return [];

        return DB::table('sign_requests')
            ->where('created_by', $u->id)->where('status', 'بانتظار التوقيع')
            ->orderBy('created_at')->limit(20)
            ->get(['id', 'title', 'signer_name', 'created_at', 'opens'])
            ->map(fn ($s) => [
                'kind' => 'sign', 'icon' => '✍️', 'label' => 'توقيع معلّق',
                'title' => $s->title, 'due' => null,
                'why' => ($s->signer_name ?: 'الموقّع')
                    . ($s->opens ? ' فتح الرابط ولم يوقّع' : ' لم يفتح الرابط بعد')
                    . ' — أُرسل ' . Carbon::parse($s->created_at)->diffForHumans(),
                'url' => route('esign.index'),
                // المرسل ينتظر منذ أيام: يُعامَل كمتأخر إن طال — لا كـ«بلا موعد»
                'bucket' => Carbon::parse($s->created_at)->lt(now()->subDays(7)) ? 0 : 3,
            ])->all();
    }

    /** سياسات إقرارها إلزاميّ ولم أُقرّ بنسختها الحالية */
    protected static function policies($u): array
    {
        if (! Schema::hasTable('policies') || ! Schema::hasTable('policy_acks')) return [];
        if (! hub_can($u, 'policies', 'v')) return [];

        // **الإقصاءُ داخل SQL قبل الحدّ** (v2.346): كان يُجلَب أربعون أقدمَ سياسةً
        // ثمّ تُقصى المُقَرّة في PHP — فلو كانت الأربعون كلُّها مُقَرّةً (والترتيبُ
        // بالأقدم) لم تصل السياسةُ الجديدةُ غيرُ المُقَرّة (الحادية والأربعون فصاعداً)
        // صندوقَ المستخدم أبداً، ولوحةُ الامتثال تعدّه مخالفاً. الآن يُطابَق الإقرارُ
        // على (policy_id, ver) داخل الاستعلام فلا يملأ المُقَرُّ النافذةَ.
        // النسخة جزءٌ من المفتاح: سياسةٌ حُدّثت تعود تنتظر إقراراً جديداً.
        return hub_scope(DB::table('policies')->whereNull('deleted_at'), 'policies', $u)
            ->where('ack_required', 1)
            ->where(fn ($w) => $w->whereNull('status')
                ->orWhereNotIn('status', ['مسودة', 'قيد الاعتماد', 'مؤرشفة', 'مؤرشف', 'ملغاة']))
            ->whereNotExists(fn ($q) => $q->from('policy_acks')
                ->whereColumn('policy_acks.policy_id', 'policies.id')
                ->whereRaw("COALESCE(policy_acks.ver, '') = COALESCE(policies.ver, '')")
                ->where('policy_acks.user_id', $u->id)
                ->whereNotNull('policy_acks.ack_at')
                ->whereNull('policy_acks.deleted_at'))
            // فاصلُ تعادلٍ على id: eff_date تاريخٌ بلا وقت، فتساوي القيم قرعةٌ بين المحرّكين
            ->orderByRaw('eff_date IS NULL, eff_date')->orderBy('id')->limit(40)
            ->get(['id', 'title', 'ver', 'cat', 'eff_date'])
            ->map(fn ($p) => [
                'kind' => 'policy', 'icon' => '📜', 'label' => 'إقرار سياسة',
                'title' => $p->title, 'due' => $p->eff_date,
                'why' => 'إقرارها إلزاميّ' . ($p->ver ? ' — النسخة ' . $p->ver : '')
                    . ($p->cat ? ' · ' . $p->cat : ''),
                'url' => route('m.show', ['policies', $p->id]),
            ])->values()->all();
    }

    /**
     * مقالات معرفة موسومة «يجب قراءتها» ولم أُقرّ بنسختها الحالية.
     *
     * كان يقرأ `kb_reads` — جدولٌ **لا يكتب فيه أحد**: `hub_ack_do('kb')` يكتب
     * في `policy_acks`. فالنتيجة: تقول لوحةُ السياسات «١٠٠٪ مُقَرّ» ويبقى البند
     * في صندوق المستخدم **إلى الأبد بلا فعلٍ يُزيله**. القراءة الآن من السكّة
     * التي يُكتب فيها فعلاً، وبالنسخة كما في السياسات.
     */
    protected static function mustRead($u): array
    {
        if (! Schema::hasTable('kb_articles') || ! Schema::hasTable('policy_acks')) return [];
        if (! hub_can($u, 'kb', 'v')) return [];

        $acked = DB::table('policy_acks')->whereNull('deleted_at')
            ->where('src_module', 'kb')->where('user_id', $u->id)->whereNotNull('ack_at')
            ->get(['record_id', 'ver'])
            ->map(fn ($a) => $a->record_id . '|' . (string) $a->ver)->all();

        return hub_scope(DB::table('kb_articles')->whereNull('deleted_at'), 'kb', $u)
            ->where('must_read', 1)
            ->where(fn ($w) => $w->whereNull('status')
                ->orWhereNotIn('status', ['مسودة', 'قيد المراجعة', 'مؤرشف', 'مؤرشفة']))
            ->orderByDesc('created_at')->limit(40)
            ->get(['id', 'title', 'cat', 'ver'])
            // النسخة جزءٌ من المفتاح كما في السياسات — ونصٌّ حُدّث يُقرأ من جديد
            ->reject(fn ($k) => in_array($k->id . '|' . ((string) $k->ver ?: '1.0'), $acked, true))
            ->take(20)
            ->map(fn ($k) => [
                'kind' => 'kb', 'icon' => '📕', 'label' => 'قراءة إلزامية',
                'title' => $k->title, 'due' => null, 'why' => $k->cat ?: 'من قاعدة المعرفة',
                'url' => route('m.show', ['kb', $k->id]),
            ])->values()->all();
    }

    /** طلبات داخلية أنا مقيّمها ولم تُحسم */
    protected static function requests($u): array
    {
        if (! Schema::hasTable('internal_requests') || ! hub_can($u, 'requests', 'v')) return [];

        return DB::table('internal_requests')->whereNull('deleted_at')
            ->where('reviewer_id', $u->id)
            ->where(fn ($w) => $w->whereNull('decision')->orWhere('decision', ''))
            ->tap(fn ($q) => hub_open_scope($q))
            ->orderByRaw('need_by IS NULL, need_by')->limit(20)
            ->get(['id', 'title', 'req_type', 'need_by', 'est_cost', 'prio_req'])
            ->map(fn ($r) => [
                'kind' => 'request', 'icon' => '📨', 'label' => 'طلب داخلي',
                'title' => $r->title, 'due' => $r->need_by,
                'why' => trim(($r->req_type ?: 'طلب') . ($r->prio_req ? ' · ' . $r->prio_req : '')
                    . ($r->est_cost ? ' · تقدير ' . number_format((float) $r->est_cost, 0) : '')),
                'url' => route('m.show', ['requests', $r->id]),
            ])->all();
    }

    /** طلبات إجازة تنتظر بتّي — لمن يملك تعديل وحدة الإجازات */
    protected static function leaves($u): array
    {
        if (! Schema::hasTable('leave_requests') || ! hub_can($u, 'leaves', 'e')) return [];

        $names = Schema::hasTable('employees')
            ? DB::table('employees')->whereNull('deleted_at')->pluck('name', 'id')
            : collect();

        return hub_scope(DB::table('leave_requests')->whereNull('deleted_at'), 'leaves', $u)
            // الحالات المعلّقة من سجل الوحدة: ما قبل «معتمد» و«مرفوض» و«ملغى»
            ->whereIn('status', ['مقدّم', 'مقدم', 'موافقة المدير', 'موافقة الموارد البشرية'])
            ->orderBy('date_from')->limit(20)
            ->get(['id', 'emp_id', 'type', 'date_from', 'days', 'status'])
            ->map(fn ($l) => [
                'kind' => 'leave', 'icon' => '🏖️', 'label' => 'طلب إجازة',
                'title' => ($names[$l->emp_id] ?? 'موظف') . ' — ' . ($l->type ?: 'إجازة'),
                // موعد البتّ هو بدء الإجازة: قرارٌ بعده لا معنى له
                'due' => $l->date_from,
                'why' => $l->days ? $l->days . ' يوم من ' . substr((string) $l->date_from, 0, 10) : 'بانتظار البتّ',
                'url' => route('m.show', ['leaves', $l->id]),
            ])->all();
    }

    /** مهامي المفتوحة — المستحقة وما تأخر منها */
    protected static function tasks($u): array
    {
        if (! Schema::hasTable('tasks') || ! hub_can($u, 'tasks', 'v')) return [];

        return hub_open_scope(DB::table('tasks')->whereNull('deleted_at')->where('assignee_id', $u->id))
            ->orderByRaw('due IS NULL, due')->limit(30)
            ->get(['id', 'title', 'due', 'priority', 'progress', 'status'])
            ->map(fn ($t) => [
                'kind' => 'task', 'icon' => '✅', 'label' => 'مهمة',
                'title' => $t->title, 'due' => $t->due,
                'why' => trim(($t->status ?: '') . ($t->priority ? ' · ' . $t->priority : '')
                    . ($t->progress !== null ? ' · ' . (int) $t->progress . '٪' : '')),
                'url' => route('m.show', ['tasks', $t->id]),
            ])->all();
    }

    /** قرارات أنفّذها ولم تُنجز */
    protected static function decisions($u): array
    {
        if (! Schema::hasTable('decisions') || ! hub_can($u, 'decisions', 'v')) return [];

        return hub_scope(DB::table('decisions')->whereNull('deleted_at'), 'decisions', $u)
            ->where('exec_id', $u->id)
            ->whereIn('status', ['لم يبدأ', 'قيد التنفيذ', 'متعثر'])
            ->orderByRaw('due IS NULL, due')->limit(20)
            ->get(['id', 'title', 'due', 'status'])
            ->map(fn ($d) => [
                'kind' => 'decision', 'icon' => '🧭', 'label' => 'قرار أنفّذه',
                'title' => $d->title, 'due' => $d->due, 'why' => $d->status,
                'url' => route('m.show', ['decisions', $d->id]),
            ])->all();
    }

    /** تذاكري المفتوحة */
    protected static function tickets($u): array
    {
        if (! Schema::hasTable('tickets') || ! hub_can($u, 'tickets', 'v')) return [];

        return hub_scope(hub_open_scope(DB::table('tickets')->whereNull('deleted_at')->where('assignee_id', $u->id)), 'tickets', $u)
            ->orderByDesc('created_at')->limit(20)
            ->get(['id', 'subject', 'customer', 'priority', 'status', 'created_at'])
            ->map(fn ($t) => [
                'kind' => 'ticket', 'icon' => '🎫', 'label' => 'تذكرة',
                // لا استحقاق للتذكرة — لكن «عاجلة منذ أسبوع» ليست «بلا موعد»
                'title' => $t->subject, 'due' => null,
                'bucket' => Carbon::parse($t->created_at)->lt(now()->subDays(3)) ? 0 : 3,
                'why' => trim(($t->customer ?: '') . ($t->priority ? ' · ' . $t->priority : '')
                    . ' · فُتحت ' . Carbon::parse($t->created_at)->diffForHumans()),
                'url' => route('m.show', ['tickets', $t->id]),
            ])->all();
    }

    /** إقرارات تنتظرني: محضرٌ لم أعتمده، عهدةٌ لم أستلمها، قرارٌ لم أُقرّ به */
    protected static function acks($u): array
    {
        return collect(\App\Support\Acks::pending($u))->map(fn ($a) => [
            'kind' => 'ack', 'icon' => '🖊️', 'label' => $a['label'],
            'title' => $a['title'], 'due' => null, 'why' => $a['why'],
            'url' => route('m.show', [$a['module'], $a['id']]),
            // إقرارٌ معلّق ليس «لاحقاً»: قيمته في وقته، وبعده يصير جدلاً
            'bucket' => 1,
        ])->all();
    }

    /** اجتماعاتي القادمة — التزامُ وقتٍ لا يقلّ عن التزام عمل */
    protected static function meetings($u): array
    {
        if (! Schema::hasTable('meetings') || ! hub_can($u, 'meetings', 'v')) return [];

        return hub_scope(DB::table('meetings')->whereNull('deleted_at'), 'meetings', $u)
            ->where('parts', 'LIKE', '%"' . $u->id . '"%')
            ->where('dt', '>=', now()->startOfDay())
            ->orderBy('dt')->limit(12)
            ->get(['id', 'title', 'dt', 'link'])
            ->map(fn ($m) => [
                'kind' => 'meeting', 'icon' => '📅', 'label' => 'اجتماع',
                'title' => $m->title, 'due' => $m->dt,
                'why' => Carbon::parse($m->dt)->translatedFormat('l j F · H:i')
                    . ($m->link ? ' · عن بُعد' : ''),
                'url' => route('m.show', ['meetings', $m->id]),
            ])->all();
    }

    /** التزامات تعاقدية أنا مالكها ولم تُنجز */
    protected static function obligations($u): array
    {
        if (! Schema::hasTable('contract_obligations') || ! hub_can($u, 'obligations', 'v')) return [];

        return hub_scope(hub_open_scope(DB::table('contract_obligations')->whereNull('deleted_at')
                ->where('owner_id', $u->id)), 'obligations', $u)
            ->orderByRaw('due IS NULL, due')->limit(20)
            ->get(['id', 'title', 'due', 'amount', 'currency', 'status'])
            ->map(fn ($o) => [
                'kind' => 'obligation', 'icon' => '📑', 'label' => 'التزام تعاقدي',
                'title' => $o->title, 'due' => $o->due,
                'why' => trim(($o->status ?: 'قائم')
                    . ($o->amount ? ' · ' . number_format((float) $o->amount, 0) . ' ' . $o->currency : '')),
                'url' => route('m.show', ['obligations', $o->id]),
            ])->all();
    }

    /** بنود امتثال أنا مالكها ويقترب استحقاقها */
    protected static function compliance($u): array
    {
        if (! Schema::hasTable('compliance_items') || ! hub_can($u, 'compliance', 'v')) return [];

        return hub_scope(hub_open_scope(DB::table('compliance_items')->whereNull('deleted_at')
                ->where('owner_id', $u->id)->whereNotNull('due')), 'compliance', $u)
            ->where('due', '<=', now()->addDays(60)->toDateString())
            ->orderBy('due')->limit(20)
            ->get(['id', 'title', 'due', 'kind', 'authority', 'status'])
            ->map(fn ($c) => [
                'kind' => 'compliance', 'icon' => '⚖️', 'label' => 'التزام نظامي',
                'title' => $c->title, 'due' => $c->due,
                'why' => trim(($c->kind ?: '') . ($c->authority ? ' · ' . $c->authority : '')),
                'url' => route('m.show', ['compliance', $c->id]),
            ])->all();
    }

    /* ────────── داخلي ────────── */

    /** توحيد الشكل: الحاوية والأيام المتبقية من الاستحقاق */
    protected static function stamp(array $i): array
    {
        $due = $i['due'] ?? null;
        $days = null;
        $bucket = $i['bucket'] ?? 4;

        if ($due) {
            $d = Carbon::parse(substr((string) $due, 0, 10))->startOfDay();
            $days = (int) now()->startOfDay()->diffInDays($d, false);
            $bucket = $days < 0 ? 0 : ($days === 0 ? 1 : ($days <= 7 ? 2 : 3));
        }

        return array_merge(['act' => null, 'why' => ''], $i,
            ['bucket' => $bucket, 'days' => $days, 'due' => $due]);
    }
}
