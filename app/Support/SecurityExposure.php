<?php

namespace App\Support;

use App\Http\Controllers\Web\RoleController;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * **خريطةُ الانكشاف — نطاقُ الأثر لو اختُرق حساب** (blast radius).
 *
 * لا رقمٌ أسود ولا جدولٌ جديد: تُبنى بعلاقاتٍ فعليّةٍ من الجداول القائمة —
 * الأدوارُ (نطاقٌ ورايات) والمستخدمون والجلساتُ الحيّة والأجهزةُ الموثوقة —
 * فتجيب سؤالاً واحداً لكل حسابٍ عالي الامتياز: **ماذا يطاله لو سُرق؟** درجةُ
 * الانكشاف مفسَّرةُ العوامل (لا حكمٌ اعتباطيّ)، والقائمةُ مرتّبةٌ الأعلى أولاً.
 */
class SecurityExposure
{
    /** بعد هذه المدة تُعدّ الجلسة منتهيةً لا حيّة (كمركز الأمان) */
    protected const LIVE_MIN = 30;

    /** وزنُ النطاق في الانكشاف: «كل الشركات» يطال أكثرَ من «مشروعٍ واحد» */
    protected const SCOPE_WEIGHT = ['all' => 40, 'company' => 20, 'proj' => 10, 'client' => 10];

    /**
     * خريطةُ الحسابات عالية الامتياز مع نطاق أثر كلٍّ.
     *
     * الامتيازُ العالي = مالكٌ، أو دورٌ يحمل رايةً حسّاسة، أو نطاقُه «كل الشركات».
     * يُرجّح كلٌّ منها في الدرجة، ويُضاف وزنُ الجلسات الحيّة (سطحُ الهجوم الآنيّ).
     *
     * @return array<int, array<string, mixed>>
     */
    public static function map(int $limit = 40): array
    {
        if (! Schema::hasTable('roles') || ! Schema::hasTable('users')) return [];

        // الأدوار مرّةً واحدة: منها النطاقُ والرايات وحالةُ الملكية
        $roles = DB::table('roles')->get()->keyBy('id');

        $liveByUser = Schema::hasTable('sessions_log')
            ? DB::table('sessions_log')->where('revoked', false)
                ->where('last_seen_at', '>=', now()->subMinutes(self::LIVE_MIN))
                ->select('user_id', DB::raw('COUNT(*) as c'))->groupBy('user_id')
                ->pluck('c', 'user_id')
            : collect();

        // عمودُ الثقة اسمُه `trust` (معلّق/موثوق/مبطَل) لا `status`
        $devByUser = Schema::hasTable('user_devices')
            ? DB::table('user_devices')->where('trust', 'موثوق')
                ->select('user_id', DB::raw('COUNT(*) as c'))->groupBy('user_id')
                ->pluck('c', 'user_id')
            : collect();

        $rows = [];
        $users = DB::table('users')->whereNull('deleted_at')
            ->where('status', '!=', 'موقوف')
            ->get(['id', 'name', 'email', 'role_id', 'totp_enabled', 'last_login_at', 'locked_until']);

        foreach ($users as $u) {
            $role = $u->role_id ? ($roles[$u->role_id] ?? null) : null;
            $isOwner = $role && (bool) $role->is_owner;
            $scope = $role->scope ?? 'proj';
            $flags = $role ? array_keys(array_filter(json_decode($role->flags ?? '[]', true) ?: [])) : [];
            $risky = array_values(array_intersect($flags, RoleController::RISKY_FLAGS));

            // غيرُ المنكشف يُطوى: من لا ملكيةَ له ولا رايةَ حسّاسة ولا نطاقَ شامل
            if (! $isOwner && ! $risky && $scope !== 'all') continue;

            $live = (int) ($liveByUser[$u->id] ?? 0);
            $devices = (int) ($devByUser[$u->id] ?? 0);

            // الدرجةُ مفسَّرةُ العوامل — تُجمع بنودُها لا تُخترع
            $factors = [];
            $score = 0;
            if ($isOwner) { $score += 60; $factors[] = 'مالكُ النظام (وصولٌ كامل)'; }
            foreach ($risky as $rf) {
                $score += 15;
                $factors[] = 'راية: ' . (RoleController::FLAGS[$rf] ?? $rf);
            }
            $sw = self::SCOPE_WEIGHT[$scope] ?? 10;
            $score += $sw;
            $factors[] = 'نطاق: ' . self::scopeLabel($scope);
            if ($live > 0) { $score += min(20, $live * 5); $factors[] = "جلساتٌ حيّة: {$live}"; }
            // التحقّقُ بخطوتين يُخفّف الانكشاف (لا يُلغيه) — عاملٌ مضادّ ظاهر
            if ($u->totp_enabled) { $score = max(0, $score - 10); $factors[] = 'مخفَّف: تحقّقٌ بخطوتين مفعَّل'; }

            $rows[] = [
                'id' => $u->id,
                'name' => $u->name,
                'email' => $u->email,
                'role' => $role->name ?? '—',
                'is_owner' => $isOwner,
                'scope' => $scope,
                'scope_label' => self::scopeLabel($scope),
                'flags' => $risky,
                'flag_labels' => array_map(fn ($rf) => RoleController::FLAGS[$rf] ?? $rf, $risky),
                'live' => $live,
                'devices' => $devices,
                'twofa' => (bool) $u->totp_enabled,
                'locked' => $u->locked_until && \Illuminate\Support\Carbon::parse($u->locked_until)->gt(now()),
                'last_login_at' => $u->last_login_at,
                'score' => $score,
                'band' => self::band($score),
                'factors' => $factors,
            ];
        }

        usort($rows, fn ($a, $b) => $b['score'] <=> $a['score']);

        return array_slice($rows, 0, $limit);
    }

    /** ملخّصٌ للوحة: كم حساباً منكشفاً، وكم منها بلا تحقّقٍ بخطوتين، وكم حيٌّ الآن */
    public static function summary(?array $map = null): array
    {
        $map ??= self::map();

        return [
            'exposed' => count($map),
            'owners' => count(array_filter($map, fn ($r) => $r['is_owner'])),
            'no2fa' => count(array_filter($map, fn ($r) => ! $r['twofa'])),
            'live' => count(array_filter($map, fn ($r) => $r['live'] > 0)),
            'high' => count(array_filter($map, fn ($r) => $r['band'] !== 'ok')),
        ];
    }

    protected static function scopeLabel(string $scope): string
    {
        return [
            'all' => 'كل الشركات',
            'company' => 'شركةٌ واحدة',
            'proj' => 'مشروعٌ واحد',
            'client' => 'عميلٌ واحد',
        ][$scope] ?? $scope;
    }

    /** نطاقاتُ اللون: عالٍ/متوسط/عاديّ — مفسَّرةٌ لا اعتباطية */
    protected static function band(int $score): string
    {
        if ($score >= 90) return 'bad';
        if ($score >= 55) return 'wn';

        return 'ok';
    }
}
