<?php

namespace App\Support;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * الأصول الرقمية — **ماذا أستفيد بعد أن أضفتُه؟**
 *
 * الخزنة وقواعد البيانات وحسابات المنصات وصناديق البريد كانت **سجلاتٍ لا
 * أدوات**: تُدخِل الحساب فيُحفظ، ثم لا شيء. لا أحد يقول لك إن كلمةً واحدة
 * تفتح أربعة حسابات، ولا إن قاعدة الإنتاج بلا نسخةٍ منذ شهرين، ولا إن هذا
 * البريد هو **مفتاح استرداد** سبعة حسابات فسقوطه يُسقطها كلها، ولا ماذا يبقى
 * بيد موظفٍ يوم يغادر.
 *
 * هنا تُقرأ الحقول القائمة معاً فتجيب أربعة أسئلة لا تجيبها أي شاشة اليوم:
 *   ١) **من يملك ماذا** — وماذا يجب نقله يوم المغادرة
 *   ٢) **نصف قطر الانفجار** — ما ينهار إن سقط هذا البريد أو الخادم أو القاعدة
 *   ٣) **صحة الخزنة** — تكرارٌ وضعفٌ وتقادمٌ ويُتْم
 *   ٤) **صحة التشغيل** — نسخٌ متقادمة واشتراكاتٌ تنتهي وحساباتٌ بلا تحقّق
 *
 * ولا تُعرض قيمة سرٍّ واحدة: المقارنة على البصمات، والنتيجة عددٌ لا نصّ.
 */
class DigitalAssets
{
    public const TTL = 600;

    /** جدولٌ موجودٌ وله أعمدة — الوحدات غير المثبَّتة تُتخطّى بلا كسر */
    protected static function has(string $table, string ...$cols): bool
    {
        if (! Schema::hasTable($table)) return false;
        foreach ($cols as $c) if (! Schema::hasColumn($table, $c)) return false;

        return true;
    }

    protected static function live(string $table)
    {
        return DB::table($table)->when(Schema::hasColumn($table, 'deleted_at'), fn ($q) => $q->whereNull('deleted_at'));
    }

    // ───────────────────── ١) الملكية والمغادرة ─────────────────────

    /**
     * ماذا بيد كل شخص من أصولٍ رقمية.
     *
     * يوم يغادر موظفٌ لا يوجد في النظام اليوم أي شاشةٍ تقول: أي حسابات منصات
     * باسمه، وأي صناديق بريدٍ مسؤولٌ عنها، وأي أسرارٍ خُوِّل عليها صراحةً.
     * فتُنسى الحسابات باسمه سنواتٍ بعد رحيله.
     */
    public static function ownership(): array
    {
        $out = [];
        $add = function (string $uid, string $kind, string $module, string $id, string $label) use (&$out) {
            if ($uid === '') return;
            $out[$uid]['items'][] = ['kind' => $kind, 'module' => $module, 'id' => $id, 'label' => $label];
        };

        if (self::has('platform_accounts', 'owner_id')) {
            foreach (self::live('platform_accounts')->get(['id', 'platform', 'username', 'owner_id', 'manager_id']) as $a) {
                $name = trim(($a->platform ?: 'حساب') . ' — ' . ($a->username ?: ''));
                $add((string) $a->owner_id, 'حساب منصة', 'accounts', $a->id, $name);
                if ($a->manager_id && $a->manager_id !== $a->owner_id) {
                    $add((string) $a->manager_id, 'إدارة حساب', 'accounts', $a->id, $name);
                }
            }
        }

        if (self::has('email_accounts', 'owner_id')) {
            foreach (self::live('email_accounts')->get(['id', 'address', 'owner_id']) as $m) {
                $add((string) $m->owner_id, 'صندوق بريد', 'emails', $m->id, (string) $m->address);
            }
        }

        if (self::has('vault_secrets', 'allowed_ids')) {
            foreach (self::live('vault_secrets')->get(['id', 'title', 'allowed_ids']) as $s) {
                $ids = is_array($s->allowed_ids) ? $s->allowed_ids : (json_decode((string) $s->allowed_ids, true) ?: []);
                foreach ((array) $ids as $uid) $add((string) $uid, 'سرّ مخوَّل عليه', 'vault', $s->id, (string) $s->title);
            }
        }

        if (self::has('servers', 'owner_id')) {
            foreach (self::live('servers')->get(['id', 'name', 'owner_id']) as $s) {
                $add((string) $s->owner_id, 'خادم', 'servers', $s->id, (string) $s->name);
            }
        }

        $names = DB::table('users')->whereNull('deleted_at')->pluck('name', 'id');
        $status = DB::table('users')->whereNull('deleted_at')->pluck('status', 'id');

        foreach ($out as $uid => &$row) {
            $row['name'] = $names[$uid] ?? '— حسابٌ محذوف —';
            $row['status'] = $status[$uid] ?? 'محذوف';
            $row['n'] = count($row['items']);
            // من غادر أو حسابه محذوف وما زال يملك أصولاً: أوّل ما يجب نقله
            $row['urgent'] = $row['status'] !== 'نشط';
        }
        unset($row);

        uasort($out, fn ($a, $b) => [$b['urgent'], $b['n']] <=> [$a['urgent'], $a['n']]);

        return $out;
    }

    // ───────────────────── ٢) نصف قطر الانفجار ─────────────────────

    /**
     * ما الذي يعتمد على كل صندوق بريد.
     *
     * **هذا هو جواب «شو أستفيد من إضافة البريد»**: البريد ليس سجلاً بل مفتاح
     * استرداد. صندوقٌ واحد قد يكون بريد الدخول لخمسة حسابات منصات، والبريد
     * الاحتياطي لثلاثةٍ أخرى، واسم المستخدم في سرَّين — فسقوطه أو خروج صاحبه
     * يُسقط كل ذلك، ولا شاشة تقول ذلك اليوم.
     */
    public static function mailboxBlastRadius(): array
    {
        if (! self::has('email_accounts', 'address')) return [];

        $boxes = self::live('email_accounts')->get(['id', 'address', 'owner_id', 'two_f_a', 'recovery', 'status']);
        if ($boxes->isEmpty()) return [];

        $addrs = $boxes->pluck('address')->filter()->map(fn ($a) => mb_strtolower(trim((string) $a)))->all();

        $byLogin = self::has('platform_accounts', 'email')
            ? self::live('platform_accounts')->whereIn(DB::raw('LOWER(email)'), $addrs)
                ->get(['id', 'platform', 'username', 'email'])->groupBy(fn ($a) => mb_strtolower(trim((string) $a->email)))
            : collect();

        $byRecovery = self::has('email_accounts', 'recovery')
            ? self::live('email_accounts')->whereIn(DB::raw('LOWER(recovery)'), $addrs)
                ->get(['id', 'address', 'recovery'])->groupBy(fn ($m) => mb_strtolower(trim((string) $m->recovery)))
            : collect();

        $byVault = self::has('vault_secrets', 'username')
            ? self::live('vault_secrets')->whereIn(DB::raw('LOWER(username)'), $addrs)
                ->get(['id', 'title', 'username'])->groupBy(fn ($v) => mb_strtolower(trim((string) $v->username)))
            : collect();

        $names = DB::table('users')->whereNull('deleted_at')->pluck('name', 'id');

        return $boxes->map(function ($b) use ($byLogin, $byRecovery, $byVault, $names) {
            $k = mb_strtolower(trim((string) $b->address));
            $logins = collect($byLogin[$k] ?? []);
            $recov  = collect($byRecovery[$k] ?? []);
            $vaults = collect($byVault[$k] ?? []);

            return [
                'id' => $b->id, 'address' => $b->address,
                'owner' => $names[$b->owner_id] ?? null,
                'twofa' => (bool) ($b->two_f_a ?? false),
                'status' => $b->status,
                'logins' => $logins->map(fn ($a) => ['id' => $a->id, 'label' => trim(($a->platform ?: '') . ' ' . ($a->username ?: ''))])->values()->all(),
                'recovers' => $recov->map(fn ($m) => ['id' => $m->id, 'label' => $m->address])->values()->all(),
                'vaults' => $vaults->map(fn ($v) => ['id' => $v->id, 'label' => $v->title])->values()->all(),
                'blast' => $logins->count() + $recov->count() + $vaults->count(),
            ];
        })->filter(fn ($x) => $x['blast'] > 0)->sortByDesc('blast')->values()->all();
    }

    /** ما يعتمد على كل خادم وكل قاعدة بيانات */
    public static function infraBlastRadius(): array
    {
        $out = [];

        if (self::has('servers', 'name')) {
            $servers = self::live('servers')->get(['id', 'name', 'status']);
            foreach ($servers as $s) {
                $deps = [];
                foreach ([['websites', 'server_id', 'المواقع'], ['databases_reg', 'server_id', 'قواعد البيانات'],
                          ['vault_secrets', 'server_id', 'أسرار الخزنة'], ['applications', 'server_id', 'التطبيقات']] as [$t, $c, $lbl]) {
                    if (! self::has($t, $c)) continue;
                    $n = self::live($t)->where($c, $s->id)->count();
                    if ($n) $deps[$lbl] = $n;
                }
                if ($deps) $out[] = ['kind' => 'خادم', 'module' => 'servers', 'id' => $s->id,
                                     'name' => $s->name, 'status' => $s->status,
                                     'deps' => $deps, 'blast' => array_sum($deps)];
            }
        }

        if (self::has('databases_reg', 'name')) {
            foreach (self::live('databases_reg')->get(['id', 'name', 'status', 'env', 'last_bk', 'backup']) as $d) {
                $deps = [];
                if (self::has('applications', 'db_id')) {
                    $n = self::live('applications')->where('db_id', $d->id)->count();
                    if ($n) $deps['التطبيقات'] = $n;
                }
                if (self::has('vault_secrets', 'notes')) {
                    // ربطٌ صريح فقط — لا تخمين
                }
                $out[] = ['kind' => 'قاعدة بيانات', 'module' => 'dbs', 'id' => $d->id,
                          'name' => $d->name, 'status' => $d->status, 'env' => $d->env,
                          'deps' => $deps, 'blast' => array_sum($deps)];
            }
        }

        usort($out, fn ($a, $b) => $b['blast'] <=> $a['blast']);

        return $out;
    }

    // ───────────────────── ٣) صحة الخزنة ─────────────────────

    /**
     * صحة الأسرار — **بلا كشف قيمةٍ واحدة**.
     *
     * التكرار يُكشف بمقارنة بصمات SHA-256 للنصّ المفكوك داخل الخادم، فلا تخرج
     * القيمة ولا تُخزَّن البصمة: تُحسب في الذاكرة وتُرمى. والنتيجة عددٌ وأسماء
     * مداخل، لا سرّ.
     */
    public static function vaultHealth(): array
    {
        if (! self::has('vault_secrets', 'secret_cipher')) {
            return ['reused' => [], 'weak' => [], 'stale' => [], 'orphan' => [], 'total' => 0];
        }

        $rows = \App\Models\VaultSecret::whereNull('deleted_at')
            ->get(['id', 'title', 'type', 'secret_cipher', 'updated_at',
                   'company_id', 'project_id', 'app_id', 'server_id']);

        $byHash = [];
        $weak = [];
        foreach ($rows as $r) {
            $v = (string) ($r->secret_cipher ?? '');
            if ($v === '') continue;
            $byHash[hash('sha256', $v)][] = ['id' => $r->id, 'title' => (string) $r->title];

            if (self::isWeak($v)) $weak[] = ['id' => $r->id, 'title' => (string) $r->title, 'type' => (string) $r->type];
        }

        $reused = [];
        foreach ($byHash as $group) {
            if (count($group) > 1) $reused[] = $group;      // نفس القيمة في أكثر من مدخل
        }

        $stale = $rows->filter(fn ($r) => $r->updated_at && $r->updated_at->lt(now()->subDays(180)))
            ->map(fn ($r) => ['id' => $r->id, 'title' => (string) $r->title,
                              'days' => (int) $r->updated_at->diffInDays(now())])
            ->sortByDesc('days')->values()->all();

        $orphan = $rows->filter(fn ($r) => ! $r->company_id && ! $r->project_id && ! $r->app_id && ! $r->server_id)
            ->map(fn ($r) => ['id' => $r->id, 'title' => (string) $r->title])->values()->all();

        return ['reused' => $reused, 'weak' => $weak, 'stale' => $stale,
                'orphan' => $orphan, 'total' => $rows->count()];
    }

    /**
     * سرٌّ ضعيف: قصيرٌ أو أحاديّ التركيب.
     * ثمانيةُ محارف فأقل، أو بلا تنوّعٍ في صنفين على الأقل (حروف/أرقام/رموز).
     */
    protected static function isWeak(string $v): bool
    {
        if (mb_strlen($v) <= 8) return true;
        $kinds = (preg_match('/[a-z]/', $v) ? 1 : 0) + (preg_match('/[A-Z]/', $v) ? 1 : 0)
               + (preg_match('/\d/', $v) ? 1 : 0) + (preg_match('/[^a-zA-Z0-9]/', $v) ? 1 : 0);

        return $kinds < 2;
    }

    /** من كشف أي سرٍّ خلال ثلاثين يوماً — من سجل التدقيق نفسه */
    public static function recentReveals(int $days = 30): array
    {
        if (! Schema::hasTable('audits')) return [];

        return DB::table('audits')->where('audits.action', 'عرض حساس')
            ->where('audits.created_at', '>=', now()->subDays($days))
            ->leftJoin('users', 'users.id', '=', 'audits.user_id')
            ->orderByDesc('audits.created_at')->limit(20)
            ->get(['users.name as uname', 'audits.name', 'audits.module', 'audits.record_id',
                   'audits.ip', 'audits.created_at'])->map(fn ($r) => (array) $r)->all();
    }

    // ───────────────────── ٤) صحة التشغيل ─────────────────────

    public static function opsHealth(): array
    {
        $out = ['dbNoBackup' => [], 'dbStaleBackup' => [], 'subsSoon' => [],
                'accountsNoTwofa' => [], 'accountsNoOwner' => [], 'accountsNoSecret' => [],
                'mailNoTwofa' => [], 'mailNoRecovery' => [], 'yearlyCost' => 0.0];

        if (self::has('databases_reg', 'name')) {
            $dbs = self::live('databases_reg')->get(['id', 'name', 'env', 'backup', 'last_bk', 'status']);
            foreach ($dbs as $d) {
                $prod = in_array((string) $d->env, ['إنتاج', 'production'], true);
                if (blank($d->backup)) $out['dbNoBackup'][] = ['id' => $d->id, 'name' => $d->name, 'prod' => $prod];
                elseif (blank($d->last_bk)) $out['dbStaleBackup'][] = ['id' => $d->id, 'name' => $d->name, 'days' => null, 'prod' => $prod];
                else {
                    $days = (int) \Illuminate\Support\Carbon::parse($d->last_bk)->diffInDays(now());
                    if ($days > 7) $out['dbStaleBackup'][] = ['id' => $d->id, 'name' => $d->name, 'days' => $days, 'prod' => $prod];
                }
            }
        }

        if (self::has('platform_accounts', 'platform')) {
            $accs = self::live('platform_accounts')->get(['id', 'platform', 'username', 'owner_id',
                'verified', 'sub_exp', 'cost', 'email']);
            $vaultLogins = self::has('vault_secrets', 'username')
                ? self::live('vault_secrets')->pluck('username')->filter()
                    ->map(fn ($u) => mb_strtolower(trim((string) $u)))->all() : [];

            foreach ($accs as $a) {
                $label = trim(($a->platform ?: 'حساب') . ' — ' . ($a->username ?: $a->email ?: ''));
                if (! $a->owner_id) $out['accountsNoOwner'][] = ['id' => $a->id, 'label' => $label];
                if (! $a->verified) $out['accountsNoTwofa'][] = ['id' => $a->id, 'label' => $label];

                $login = mb_strtolower(trim((string) ($a->username ?: $a->email ?: '')));
                if ($login !== '' && ! in_array($login, $vaultLogins, true)) {
                    $out['accountsNoSecret'][] = ['id' => $a->id, 'label' => $label];
                }

                if ($a->cost) $out['yearlyCost'] += (float) $a->cost;
                if ($a->sub_exp) {
                    $d = (int) \Illuminate\Support\Carbon::parse($a->sub_exp)->diffInDays(now(), false);
                    if ($d >= -45) $out['subsSoon'][] = ['id' => $a->id, 'label' => $label,
                        'at' => substr((string) $a->sub_exp, 0, 10), 'days' => -$d];
                }
            }
            usort($out['subsSoon'], fn ($x, $y) => $x['days'] <=> $y['days']);
        }

        if (self::has('email_accounts', 'address')) {
            foreach (self::live('email_accounts')->get(['id', 'address', 'two_f_a', 'recovery']) as $m) {
                if (! ($m->two_f_a ?? false)) $out['mailNoTwofa'][] = ['id' => $m->id, 'label' => $m->address];
                if (blank($m->recovery)) $out['mailNoRecovery'][] = ['id' => $m->id, 'label' => $m->address];
            }
        }

        return $out;
    }

    /** كل ما تحتاجه الشاشة — بمخبأٍ واحد */
    public static function all(bool $fresh = false): array
    {
        if ($fresh) Cache::forget('da:all');

        return Cache::remember('da:all', self::TTL, fn () => [
            'ownership' => self::ownership(),
            'mail'      => self::mailboxBlastRadius(),
            'infra'     => self::infraBlastRadius(),
            'vault'     => self::vaultHealth(),
            'reveals'   => self::recentReveals(),
            'ops'       => self::opsHealth(),
            'at'        => now()->toDateTimeString(),
        ]);
    }
}
