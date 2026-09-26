<?php

namespace App\Support\Workforce;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * دليل الفريق — وجوهٌ لا صفوف.
 *
 * ملفات الموظفين كانت جدولاً: اسمٌ ومسمّى وقسم. فلا يُعرف من في أي قسم بلمحة،
 * ولا من ينتهي جوازه الشهر القادم، ولا من ملفُّه ناقص، ولا مهاراتُ من وشهاداتُه
 * — وكلها مسجَّلةٌ أصلاً في الوحدة نفسها وفي «المهارات والشهادات».
 *
 * وصورةُ الموظف تُرفع كوثيقةٍ من ملفه (`photo`) فتصير وجهه في الدليل.
 *
 * ── الدليلُ الأدنى (الجولة 1 · F4) ──
 * موظفٌ عاديٌّ بنطاق مشاريع وبلا `hr:v` كان يرى «لا موظفين في نطاقك»: تنطيقُ
 * `hr` يحسر على `employees.project_id` وهو NULL في كل الملفات عملياً — فلا يجد
 * حتى مديرتَه بالبحث. والدليلُ حاجةُ **كل** زميلٍ داخليّ لا أداةُ HR وحدها،
 * فصار له وجهان:
 *   · **الكامل** لحامل `hr:v` — كما كان حرفياً، بتنطيقه وحقوله.
 *   · **الأدنى** لكل زميلٍ داخليٍّ نشطٍ: الاسم/المسمّى/القسم/الشركة والمديرُ
 *     المباشر **بالاسم** — لا هاتفَ ولا بريدَ ولا راتبَ ولا وثائقَ ولا انتهاءَ
 *     إقامة: الحسّاسُ يبقى خلف صلاحياته، وعزلُ الشركات قائمٌ في الوجهين.
 */
class TeamDirectory
{
    public const TTL = 300;

    /** أيُّ دليلٍ لهذا المستخدم؟ full لحامل hr:v · basic للزميل الداخليّ · null لمن سواهما */
    public static function mode($user = null): ?string
    {
        $user = $user ?? auth()->user();
        if (! $user || hub_is_client($user)) return null;
        if (hub_can($user, 'hr', 'v')) return 'full';

        return self::isColleague($user) ? 'basic' : null;
    }

    /** زميلٌ داخليّ: حسابٌ مربوطٌ بملفِّ موظفٍ مفتوحِ الخدمة (نشط/إجازة) */
    protected static function isColleague($user): bool
    {
        if (! Schema::hasTable('employees')) return false;

        return \App\Models\Employee::whereNull('deleted_at')
            ->where('user_id', $user->id)
            ->whereIn('status', Staff::OPEN)->exists();
    }

    /** بطاقاتُ الفريق مجمَّعةً بالقسم — بصورةٍ وحالةِ ملفٍّ ومهارات */
    public static function cards($user = null): array
    {
        $user = $user ?? auth()->user();
        if (! Schema::hasTable('employees')) return [];

        // بلا hr:v: الدليلُ الأدنى للزميل الداخليّ — لا شيء لمن سواه
        if (! hub_can($user, 'hr', 'v')) {
            return self::isColleague($user) ? self::basicCards($user) : [];
        }

        $emps = hub_scope(\App\Models\Employee::query(), 'hr', $user)
            ->orderBy('name')->limit(500)->get();

        /*
         * **نطاقٌ حاسرٌ لا يعني شركةً بلا موظّفين** (الجولة 2 · G19-الجذر): حاملُ
         * `hr:v` بنطاقِ مشاريعَ (`proj`) يأخذ مسارَ الدليلِ الكاملِ هنا، و`hub_scope`
         * تحسر على `employees.project_id` — وهو فارغٌ عمليّاً لكلّ الملفّات — فيرى
         * **دليلاً خاوياً** ويظنّ الشركةَ خالية (رصدها وكيلا المحاكاة 10 و«التعيين»:
         * «لا موظفين في نطاقك» ثم بحثٌ لا يجد حتى مديرتَه). الصوابُ أنّ حسرَ النطاق
         * يُنقص ما يُعرَض لا أن يُلغيَ حقَّ الزميلِ في دليله الأدنى: نسقط إلى
         * `basicCards` (اسمٌ ومسمّى وقسمٌ وشركةٌ ومديرٌ بالاسم — بلا راتبٍ ولا
         * هويّاتٍ ولا تنبيهات)، فلا يُكشَف شيءٌ زائدٌ ولا يبقى الزميلُ بلا زملاء.
         */
        if ($emps->isEmpty()) {
            return self::isColleague($user) ? self::basicCards($user) : [];
        }

        $ids = $emps->pluck('id')->all();

        $photos = self::photosFor($ids);

        $skills = Schema::hasTable('skills')
            ? DB::table('skills')->whereNull('deleted_at')->whereIn('emp_id', $ids)
                ->get(['emp_id', 'name', 'level', 'cert', 'cert_exp'])->groupBy('emp_id')
            : collect();

        $managers = self::managerNames($emps);
        $companies = self::companyNames($emps);
        // الراتب حقلٌ قد يكون محجوباً بدور القارئ — يُقرَّر مرةً لا لكل بطاقة.
        // ومثلُه انتهاءُ الإقامة والجواز: قفلُ الحقل يحجبهما في نموذج الملف،
        // وكان الدليلُ يطبعهما جهراً — فالقفلُ يُلتَفّ عليه بفتح شاشةٍ أخرى.
        $showSalary = hub_field_mode($user, 'hr', 'salary') === '';
        $showId = hub_field_mode($user, 'hr', 'iqamaExp') === '';
        $showPass = hub_field_mode($user, 'hr', 'passExp') === '';

        return $emps->map(function ($e) use ($photos, $skills, $managers, $companies, $showSalary, $showId, $showPass) {
            $mine = collect($skills[$e->id] ?? []);
            $certs = $mine->filter(fn ($s) => trim((string) $s->cert) !== '');
            $expiringCert = $certs->first(fn ($s) => $s->cert_exp
                && \Illuminate\Support\Carbon::parse($s->cert_exp)->lt(now()->addDays(60)));

            $dossier = hub_dossier('hr', $e->id);

            // المحجوبُ يُصفَّر عند المنبع لا عند الطباعة: `alerts()` تُبنى من
            // هذه البطاقات، فلو بقيت القيمةُ هنا لسرّبها سطرُ التنبيه نفسه.
            $idExp = $showId ? self::daysTo($e->iqama_exp) : null;
            $passExp = $showPass ? self::daysTo($e->pass_exp) : null;

            return [
                'id' => $e->id, 'name' => $e->name, 'title' => $e->title,
                'dept' => $e->dept ?: 'بلا قسم', 'status' => $e->status,
                'photo' => $photos[$e->id] ?? null,
                'manager' => $managers[$e->manager_id] ?? null,
                'company' => $companies[$e->company_id] ?? null,
                'hired' => $e->hired,
                'salary' => $showSalary && $e->salary !== null ? (float) $e->salary : null,
                'userId' => $e->user_id,
                'skills' => $mine->take(5)->map(fn ($s) => trim(($s->name ?: '') . ($s->level ? ' · ' . $s->level : '')))->all(),
                'skillsN' => $mine->count(), 'certsN' => $certs->count(),
                'certExpiring' => $expiringCert?->cert,
                'docPct' => $dossier['pct'] ?? 0,
                'docMissing' => $dossier['requiredMissing'] ?? 0,
                'idDays' => $idExp, 'passDays' => $passExp,
                'alert' => ($idExp !== null && $idExp <= 60) || ($passExp !== null && $passExp <= 60)
                        || ($dossier['requiredMissing'] ?? 0) > 0 || $expiringCert !== null,
            ];
        })->groupBy('dept')->map(fn ($g) => $g->values()->all())->all();
    }

    /**
     * الدليلُ الأدنى (F4): زملاءُ الخدمة المفتوحة بأقلِّ الحقول وأسلمِها —
     * البطاقةُ على **الشكل نفسه** كي لا تتفرّع الشاشة، والحسّاسُ مصفَّرٌ عند
     * المنبع (راتب/إقامة/جواز/وثائق/مهارات) لا محجوبٌ عند الطباعة.
     */
    protected static function basicCards($user): array
    {
        $q = \App\Models\Employee::whereNull('deleted_at')->whereIn('status', Staff::OPEN);
        // عزلُ الشركات قائم — والملفُّ بلا شركةٍ عامٌّ يراه الجميع (كقائمة gaps)
        if (($cids = hub_company_ids($user)) !== null) {
            $q->where(fn ($w) => $w->whereIn('company_id', $cids)->orWhereNull('company_id'));
        }
        $emps = $q->orderBy('name')->orderBy('id')->limit(500)
            ->get(['id', 'name', 'title', 'dept', 'status', 'company_id', 'manager_id', 'user_id']);
        if ($emps->isEmpty()) return [];

        $photos = self::photosFor($emps->pluck('id')->all());
        $managers = self::managerNames($emps);
        $companies = self::companyNames($emps);

        return $emps->map(fn ($e) => [
            'id' => $e->id, 'name' => $e->name, 'title' => $e->title,
            'dept' => $e->dept ?: 'بلا قسم', 'status' => null,
            'photo' => $photos[$e->id] ?? null,
            'manager' => $managers[$e->manager_id] ?? null,
            'company' => $companies[$e->company_id] ?? null,
            'hired' => null, 'salary' => null, 'userId' => $e->user_id,
            'skills' => [], 'skillsN' => 0, 'certsN' => 0, 'certExpiring' => null,
            'docPct' => 100, 'docMissing' => 0, 'idDays' => null, 'passDays' => null,
            'alert' => false,
        ])->groupBy('dept')->map(fn ($g) => $g->values()->all())->all();
    }

    /** الصور: أحدثُ مرفقٍ نوعُه photo لكل موظف */
    protected static function photosFor(array $ids): array
    {
        $photos = [];
        if ($ids && Schema::hasTable('attachments')) {
            foreach (\App\Models\Attachment::whereNull('deleted_at')->where('module', 'hr')
                ->where('kind', 'photo')->whereIn('record_id', $ids)
                ->orderByDesc('created_at')->orderByDesc('id')->get(['record_id', 'path']) as $a) {
                $photos[$a->record_id] ??= $a->path;
            }
        }

        return $photos;
    }

    /**
     * «مديره المباشر» معرّفُ **مستخدم** (‏`hr.managerId` من نوع ref→users)،
     * وكانت الخريطةُ تُبنى بمعرّفات **الموظفين** — فالبحثُ لا يُصيب أبداً
     * والحقلُ يظهر فارغاً مهما مُلئ (أو UUID خاماً حيث يُطبع بلا حلّ). تُحلّ من
     * `users`، ويُبقى على مرتجَعِ الموظفين احتياطاً لبياناتٍ قديمة كُتب فيها
     * معرّفُ موظف — والمجهولُ يبقى null فلا يتسرّب UUID خامٌ إلى الشاشة أبداً.
     */
    protected static function managerNames($emps)
    {
        $mgrIds = $emps->pluck('manager_id')->filter()->unique()->all();
        $managers = ($mgrIds && Schema::hasTable('users'))
            ? DB::table('users')->whereNull('deleted_at')->whereIn('id', $mgrIds)->pluck('name', 'id')
            : collect();

        return $managers->union($emps->pluck('name', 'id'));
    }

    /** اسمُ الشركة لكل بطاقة — «في أي شركةٍ زميلي؟» جزءٌ من الحدّ الأدنى (F4) */
    protected static function companyNames($emps)
    {
        $ids = $emps->pluck('company_id')->filter()->unique()->all();

        return ($ids && Schema::hasTable('companies'))
            ? DB::table('companies')->whereNull('deleted_at')->whereIn('id', $ids)->pluck('name_ar', 'id')
            : collect();
    }

    protected static function daysTo($date): ?int
    {
        if (! $date) return null;

        return (int) now()->startOfDay()->diffInDays(\Illuminate\Support\Carbon::parse($date)->startOfDay(), false);
    }

    /** ما يستحق التفاتاً في ملفات الفريق */
    public static function alerts(array $byDept): array
    {
        $all = collect($byDept)->flatten(1);
        $out = [];

        foreach ($all as $c) {
            if ($c['idDays'] !== null && $c['idDays'] <= 60) {
                $out[] = ['tone' => $c['idDays'] < 0 ? 'bad' : 'wn', 'icon' => '🪪',
                    'title' => $c['idDays'] < 0 ? 'إقامة منتهية' : 'إقامة تنتهي قريباً',
                    'what' => $c['name'], 'id' => $c['id'],
                    'why' => $c['idDays'] < 0 ? 'انتهت منذ ' . abs($c['idDays']) . ' يوماً — مخالفةٌ قائمة.'
                                              : 'تنتهي بعد ' . $c['idDays'] . ' يوماً.'];
            }
            if ($c['passDays'] !== null && $c['passDays'] <= 60) {
                $out[] = ['tone' => $c['passDays'] < 0 ? 'bad' : 'wn', 'icon' => '🛂',
                    'title' => $c['passDays'] < 0 ? 'جواز منتهٍ' : 'جواز ينتهي قريباً',
                    'what' => $c['name'], 'id' => $c['id'],
                    'why' => 'السفر والتجديد يحتاجان مهلة.'];
            }
            if ($c['docMissing']) {
                $out[] = ['tone' => 'wn', 'icon' => '📂', 'title' => 'ملفٌّ ناقص',
                    'what' => $c['name'], 'id' => $c['id'],
                    'why' => "{$c['docMissing']} وثيقة إلزامية مفقودة — يُطلب أوّلَ ما يُطلب عند أي تدقيق."];
            }
            if ($c['certExpiring']) {
                $out[] = ['tone' => 'wn', 'icon' => '🎓', 'title' => 'شهادة تنتهي',
                    'what' => $c['name'] . ' — ' . $c['certExpiring'], 'id' => $c['id'],
                    'why' => 'الشهادة المنتهية تُسقط الأهلية في العطاءات والاعتمادات.'];
            }
            if (! $c['userId'] && $c['status'] === 'نشط') {
                $out[] = ['tone' => 'wn', 'icon' => '👤', 'title' => 'موظفٌ بلا حساب',
                    'what' => $c['name'], 'id' => $c['id'],
                    'why' => 'نشطٌ في السجل ولا وصول له — إمّا يعمل بحساب غيره أو لا يستعمل النظام.'];
            }
        }

        usort($out, fn ($a, $b) => ['bad' => 0, 'wn' => 1][$a['tone']] <=> ['bad' => 0, 'wn' => 1][$b['tone']]);

        return $out;
    }

    public static function all(bool $fresh = false): array
    {
        $u = auth()->user();
        // المفتاح يحمل الدور والمستخدم **وختم جداوله**: موظفٌ جديد أو مهارةٌ
        // مُضافة يظهران فوراً لا بعد انقضاء المهلة (الختم يسبق المهلة).
        $key = 'team:dir:' . ($u?->role_id ?? '0') . ':' . ($u?->id ?? '0')
            . hub_data_stamp(['employees', 'skills', 'users']);
        if ($fresh) Cache::forget($key);

        return Cache::remember($key, self::TTL, function () {
            $mode = self::mode() ?? 'full';
            $cards = self::cards();

            // تنبيهاتُ الملفات (إقامات/وثائق/رواتب الاهتمام) شأنُ HR — لا تُعرض في الأدنى
            return ['depts' => $cards, 'mode' => $mode,
                    'alerts' => $mode === 'full' ? self::alerts($cards) : [],
                    'n' => collect($cards)->flatten(1)->count(), 'at' => now()->toDateTimeString()];
        });
    }
}
