<?php

namespace App\Support;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * الامتثال — الالتزام وأثره، لا جدولُ تواريخ.
 *
 * كان سجل الامتثال صفوفاً: عنوانٌ وجهةٌ وتاريخ. وثلاثة أشياء لا يقولها:
 *
 *   ١) **الأثر**: «متأخر» كلمةٌ لا ثمن لها. أما «إيقاف النشاط والتعاقد
 *      والفوترة، وتعطّل تجديد الإقامات» فقرارٌ يُتّخذ اليوم.
 *   ٢) **المهلة**: التجديد نفسه يستغرق أسابيع. من ينتبه يوم الاستحقاق تأخّر
 *      فعلاً — فالتنبيه يبدأ من مهلة النوع لا من تاريخه.
 *   ٣) **الغائب**: وهذه أخطرها. ما لا سجلَّ له لا يُفحَص ولا يُنبَّه عنه —
 *      شركةٌ بلا ترخيصٍ مسجَّل في النظام تبدو **سليمة** لأن لا صفَّ لها يحمرّ.
 */
class Compliance
{
    public const TTL = 300;

    public static function may(): bool
    {
        return hub_can(auth()->user(), 'compliance', 'v');
    }

    /** الرسوم حقلٌ قد يُحجب بقيود الدور — والمجموع يكشفه كما يكشفه الصفّ */
    public static function seesFee(): bool
    {
        return ! hub_masked('compliance', 'fee');
    }

    public static function kinds(): array
    {
        return (array) config('hub_compliance', []);
    }

    protected static function q()
    {
        return hub_scope(DB::table('compliance_items')->whereNull('deleted_at'), 'compliance');
    }

    /** الرادار: كل بندٍ مستحقٍّ أو متأخر، بأثره ومهلته */
    public static function radar(): array
    {
        return hub_screen('cmp:radar', self::TTL, fn () => self::radarCalc(),
            ['compliance_items', 'companies', 'users']);
    }

    protected static function radarCalc(): array
    {
        if (! self::may() || ! Schema::hasTable('compliance_items')) return [];

        $kinds = self::kinds();
        $names = Schema::hasTable('users') ? DB::table('users')->pluck('name', 'id') : collect();
        $cos = Schema::hasTable('companies')
            ? DB::table('companies')->whereNull('deleted_at')->pluck('name_ar', 'id') : collect();

        $rows = hub_open_scope(self::q(), 'status', ['معفى'])
            ->whereNotNull('due')->orderBy('due')->limit(120)
            ->get(['id', 'title', 'kind', 'authority', 'due', 'fee', 'owner_id', 'company_id', 'status', 'att_id']);

        $out = [];
        foreach ($rows as $r) {
            $days = (int) now()->startOfDay()->diffInDays(Carbon::parse($r->due)->startOfDay(), false);
            $k = $kinds[$r->kind] ?? null;
            $lead = (int) ($k['lead'] ?? 30);
            // خارج المهلة تماماً: لا يُعرض بعدُ كي لا يغرق الرادار بما لا يُعمَل فيه اليوم
            if ($days > $lead) continue;

            $out[] = [
                'id' => $r->id, 'title' => $r->title, 'kind' => $r->kind ?: 'غير مصنّف',
                'authority' => $r->authority, 'due' => $r->due, 'days' => $days,
                'fee' => $r->fee !== null && self::seesFee() ? (float) $r->fee : null,
                'owner' => $names[$r->owner_id] ?? null,
                'company' => $cos[$r->company_id] ?? null,
                'status' => $r->status, 'lead' => $lead,
                'impact' => $k['impact'] ?? 'مخالفةٌ تُقاس بغرامةٍ أو بتعليق إجراء — صنّف النوع ليُعرف أثره بدقّة.',
                'tone' => $days < 0 ? 'bad' : ($days <= 14 ? 'wn' : ''),
                // «بدأ وقتُ العمل» لا «حان الموعد»: الإجراء نفسه يحتاج مهلته
                'act' => $days < 0 ? 'متأخر منذ ' . abs($days) . ' يوماً'
                       : ($days === 0 ? 'يستحق اليوم' : "يستحق بعد {$days} يوماً — ومهلة إجرائه {$lead}"),
            ];
        }

        return $out;
    }

    /**
     * الغائب: ما يُتوقَّع لكل شركةٍ ولا سجلَّ له.
     * لا يحمرّ صفٌّ لأنه لا صفَّ أصلاً — وهذا أخطر من التأخر.
     */
    public static function missing(): array
    {
        return hub_screen('cmp:miss', self::TTL, fn () => self::missingCalc(), ['compliance_items', 'companies']);
    }

    protected static function missingCalc(): array
    {
        if (! self::may() || ! Schema::hasTable('compliance_items') || ! Schema::hasTable('companies')) return [];

        $expected = collect(self::kinds())->filter(fn ($k) => $k['baseline'] ?? false)->keys();
        if ($expected->isEmpty()) return [];

        $ids = hub_company_ids();
        $cos = DB::table('companies')->whereNull('deleted_at')
            ->when($ids !== null, fn ($q) => $q->whereIn('id', $ids ?: ['-']))
            ->limit(50)->get(['id', 'name_ar', 'status']);
        if ($cos->isEmpty()) return [];

        $have = self::q()->whereIn('company_id', $cos->pluck('id'))
            ->get(['company_id', 'kind'])
            ->groupBy('company_id')->map(fn ($g) => $g->pluck('kind')->filter()->unique()->all());

        $out = [];
        foreach ($cos as $c) {
            // الشركة المغلقة لا يُطالَب لها بتراخيص — لكن المتوقفة يُطالَب: رسومُها تجري
            if (in_array($c->status, ['مغلقة', 'مصفّاة'], true)) continue;
            $mine = $have[$c->id] ?? [];
            $gaps = $expected->reject(fn ($k) => in_array($k, $mine, true))->values()->all();
            if (! $gaps) continue;
            $out[] = ['id' => $c->id, 'company' => $c->name_ar, 'gaps' => $gaps];
        }

        return $out;
    }

    /** ثغراتٌ في السجل نفسه: بلا مالك، بلا دليل، بلا تاريخ، وحالةٌ تكذب */
    public static function gaps(): array
    {
        return hub_screen('cmp:gaps', self::TTL, fn () => self::gapsCalc(), ['compliance_items']);
    }

    protected static function gapsCalc(): array
    {
        if (! self::may() || ! Schema::hasTable('compliance_items')) return [];

        $out = [];

        foreach (hub_open_scope(self::q(), 'status', ['معفى'])
                    ->whereNull('owner_id')->limit(10)->get(['id', 'title']) as $r) {
            $out[] = ['icon' => '👤', 'tone' => 'wn', 'id' => $r->id, 'title' => $r->title,
                'kind' => 'بلا مالك', 'why' => 'لا اسمَ مسؤولٍ عنه — والتزامٌ مسؤوليتُه للجميع مسؤوليةُ لا أحد.'];
        }

        foreach (hub_open_scope(self::q(), 'status', ['معفى'])
                    ->whereNull('due')->limit(10)->get(['id', 'title']) as $r) {
            $out[] = ['icon' => '📅', 'tone' => 'wn', 'id' => $r->id, 'title' => $r->title,
                'kind' => 'بلا تاريخ استحقاق', 'why' => 'لا يدخل رادار التجديد أبداً — يُكتشف يوم يُطلب.'];
        }

        // «ملتزم» بلا دليل: الشهادة هي ما يُبرَز عند التفتيش لا الحالة في النظام
        foreach (self::q()->where('status', 'ملتزم')->whereNull('att_id')
                    ->limit(10)->get(['id', 'title']) as $r) {
            $out[] = ['icon' => '📎', 'tone' => 'wn', 'id' => $r->id, 'title' => $r->title,
                'kind' => 'ملتزمٌ بلا دليل', 'why' => 'الحالة «ملتزم» ولا مرفقَ يُثبتها — ما يُبرَز عند التفتيش هو الشهادة لا الشاشة.'];
        }

        // حالةٌ تكذب: «ملتزم» واستحقاقه فات
        foreach (self::q()->where('status', 'ملتزم')->whereNotNull('due')
                    ->where('due', '<', now()->toDateString())
                    ->limit(10)->get(['id', 'title', 'due']) as $r) {
            $out[] = ['icon' => '🚩', 'tone' => 'bad', 'id' => $r->id, 'title' => $r->title,
                'kind' => 'حالةٌ تكذب', 'why' => 'مكتوبٌ «ملتزم» وتاريخ استحقاقه ' . substr((string) $r->due, 0, 10)
                    . ' قد مضى — إمّا جُدِّد ولم يُحدَّث، أو لم يُجدَّد ويُظنّ أنه جُدّد.'];
        }

        usort($out, fn ($a, $b) => ['bad' => 0, 'wn' => 1][$a['tone']] <=> ['bad' => 0, 'wn' => 1][$b['tone']]);

        return $out;
    }

    /** كلفة الامتثال ونبضه — الرسوم المستحقة خلال ٩٠ يوماً تدفّقٌ نقديّ لا أحد يخطّط له */
    public static function pulse(): array
    {
        return hub_screen('cmp:pulse', self::TTL, fn () => self::pulseCalc(), ['compliance_items']);
    }

    protected static function pulseCalc(): array
    {
        if (! self::may() || ! Schema::hasTable('compliance_items')) return [];

        $open = hub_open_scope(self::q(), 'status', ['معفى']);

        return [
            'n' => self::q()->count(),
            'late' => (clone $open)->whereNotNull('due')->where('due', '<', now()->toDateString())->count(),
            'soon' => (clone $open)->whereNotNull('due')
                ->whereBetween('due', [now()->toDateString(), now()->addDays(90)->toDateString()])->count(),
            'fees90' => self::seesFee() ? (float) (clone $open)->whereNotNull('due')
                ->whereBetween('due', [now()->toDateString(), now()->addDays(90)->toDateString()])->sum('fee') : null,
            'cur' => setting('app.currency', 'د.ك'),
        ];
    }
}
