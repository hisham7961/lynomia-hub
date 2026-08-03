<?php

namespace App\Support;

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
 */
class TeamDirectory
{
    public const TTL = 300;

    /** بطاقاتُ الفريق مجمَّعةً بالقسم — بصورةٍ وحالةِ ملفٍّ ومهارات */
    public static function cards($user = null): array
    {
        $user = $user ?? auth()->user();
        if (! Schema::hasTable('employees')) return [];

        $emps = hub_scope(\App\Models\Employee::query(), 'hr', $user)
            ->orderBy('name')->limit(500)->get();
        if ($emps->isEmpty()) return [];

        $ids = $emps->pluck('id')->all();

        // الصور: أحدثُ مرفقٍ نوعُه photo لكل موظف
        $photos = [];
        if (Schema::hasTable('attachments')) {
            foreach (\App\Models\Attachment::whereNull('deleted_at')->where('module', 'hr')
                ->where('kind', 'photo')->whereIn('record_id', $ids)
                ->orderByDesc('created_at')->get(['record_id', 'path']) as $a) {
                $photos[$a->record_id] ??= $a->path;
            }
        }

        $skills = Schema::hasTable('skills')
            ? DB::table('skills')->whereNull('deleted_at')->whereIn('emp_id', $ids)
                ->get(['emp_id', 'name', 'level', 'cert', 'cert_exp'])->groupBy('emp_id')
            : collect();

        $managers = $emps->pluck('name', 'id');
        // الراتب حقلٌ قد يكون محجوباً بدور القارئ — يُقرَّر مرةً لا لكل بطاقة
        $showSalary = hub_field_mode($user, 'hr', 'salary') === '';

        return $emps->map(function ($e) use ($photos, $skills, $managers, $showSalary) {
            $mine = collect($skills[$e->id] ?? []);
            $certs = $mine->filter(fn ($s) => trim((string) $s->cert) !== '');
            $expiringCert = $certs->first(fn ($s) => $s->cert_exp
                && \Illuminate\Support\Carbon::parse($s->cert_exp)->lt(now()->addDays(60)));

            $dossier = hub_dossier('hr', $e->id);

            $idExp = self::daysTo($e->iqama_exp);
            $passExp = self::daysTo($e->pass_exp);

            return [
                'id' => $e->id, 'name' => $e->name, 'title' => $e->title,
                'dept' => $e->dept ?: 'بلا قسم', 'status' => $e->status,
                'photo' => $photos[$e->id] ?? null,
                'manager' => $managers[$e->manager_id] ?? null,
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
        // المفتاح يحمل الدور والمستخدم: الحقول المحجوبة والنطاق يختلفان بينهما
        $key = 'team:dir:' . ($u?->role_id ?? '0') . ':' . ($u?->id ?? '0');
        if ($fresh) Cache::forget($key);

        return Cache::remember($key, self::TTL, function () {
            $cards = self::cards();

            return ['depts' => $cards, 'alerts' => self::alerts($cards),
                    'n' => collect($cards)->flatten(1)->count(), 'at' => now()->toDateTimeString()];
        });
    }
}
