<?php

namespace App\Support\Security;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * **مَن يتوقّف لو شُدِّد؟ — قارئٌ يُجيب بقائمةِ أسماءٍ لا برأي** (§٥ · #14 · #20).
 *
 * ── **لماذا قارئٌ قبل الفرض** ──
 *
 * البندان #14 (فرضُ 2FA على رموزِ API) و#20 (فرضُ `X-Hub-Timestamp`) بقيا
 * سنواتٍ «قرارَ مالك» لأنّ كلفتَهما مكتوبةٌ بالكلام: «التكاملاتُ القائمةُ
 * تتوقّف» و«المُرسِلونَ القدامى يُرفَضون». **وكلامٌ كهذا لا يُقرَّر عليه** —
 * فليس السؤالُ «أنُشدّد؟» بل «**مَن يتوقّف بالضبط؟**»، وجوابُه قائمةٌ.
 *
 * وقد قِيس أنّ نصفَ البياناتِ موجودٌ أصلاً: `api_tokens.last_used_at`
 * و`users.totp_enabled` يكفيان للأوّل، والثاني احتاج عمودَي رصدٍ أُضيفا في
 * v2.597.0 (`inbound_hooks.ts_seen_at`/`ts_missing_at`).
 *
 * **وهذا الصنفُ لا يفرض شيئاً ولا يكتب شيئاً** — قراءةٌ محضة. الفرضُ مفتاحان
 * **مطفآن افتراضياً**، والقرارُ نقرةٌ بعد أسبوعَي رصد.
 */
class HardeningReadiness
{
    /** ما يُعَدّ «هادئاً» — لا طلبَ ناقصاً منذ هذه المدّة، فالنقطةُ جاهزة */
    public const QUIET_DAYS = 14;

    /**
     * **رموزُ API الحيّة وحالةُ صاحبِها** — #14.
     *
     * @return array<int, array{id:string, name:string, owner:string, last_used:?string, has_2fa:bool, breaks:bool}>
     */
    public static function apiTokens(): array
    {
        if (! Schema::hasTable('api_tokens') || ! Schema::hasTable('users')) return [];

        $rows = DB::table('api_tokens')
            ->leftJoin('users', 'users.id', '=', 'api_tokens.user_id')
            ->whereNull('api_tokens.revoked_at')
            ->where(fn ($w) => $w->whereNull('api_tokens.expires_at')->orWhere('api_tokens.expires_at', '>', now()))
            // ترتيبٌ حتميّ: الأحدثُ استعمالاً أوّلاً، و`id` يقطع التساوي
            ->orderByDesc('api_tokens.last_used_at')->orderBy('api_tokens.id')
            ->get([
                'api_tokens.id', 'api_tokens.name', 'api_tokens.last_used_at',
                'users.name as owner_name', 'users.totp_enabled',
            ]);

        $out = [];
        foreach ($rows as $r) {
            $has2fa = (bool) ($r->totp_enabled ?? false);
            $out[] = [
                'id'        => (string) $r->id,
                'name'      => (string) ($r->name ?? ''),
                'owner'     => (string) ($r->owner_name ?? '—'),
                'last_used' => $r->last_used_at ? substr((string) $r->last_used_at, 0, 16) : null,
                'has_2fa'   => $has2fa,
                // **هذا هو الجواب**: رمزٌ حيٌّ لصاحبٍ بلا 2FA يتوقّف لحظةَ الفرض
                'breaks'    => ! $has2fa,
            ];
        }

        return $out;
    }

    /**
     * **نقاطُ الاستقبال وجاهزيّتُها للختمِ الزمنيّ** — #20.
     *
     * والنقطةُ «جاهزة» إن لم يصلها طلبٌ بلا ترويسةٍ منذ `QUIET_DAYS`. وواحدةٌ
     * **لم يصلها شيءٌ بعد** ليست جاهزةً ولا متوقّفة — `unknown` تُقال ولا تُخمَّن.
     *
     * @return array<int, array{id:string, name:string, with:?string, without:?string, state:string}>
     */
    public static function inboundHooks(): array
    {
        if (! Schema::hasTable('inbound_hooks')
            || ! Schema::hasColumn('inbound_hooks', 'ts_missing_at')) return [];

        $rows = DB::table('inbound_hooks')->where('enabled', true)
            ->orderBy('name')->orderBy('id')
            ->get(['id', 'name', 'ts_seen_at', 'ts_missing_at']);

        $cut = now()->subDays(self::QUIET_DAYS);
        $out = [];
        foreach ($rows as $r) {
            $miss = $r->ts_missing_at ? \Illuminate\Support\Carbon::parse($r->ts_missing_at) : null;
            $seen = $r->ts_seen_at ? \Illuminate\Support\Carbon::parse($r->ts_seen_at) : null;

            if ($miss && $miss->gt($cut))      $state = 'يتوقّف';      // طلبٌ ناقصٌ حديث
            elseif ($seen || $miss)            $state = 'جاهزة';       // رُصد، ولا ناقصَ حديث
            else                               $state = 'لم تُرصَد';   // لا طلبَ بعدُ — لا يُخمَّن

            $out[] = [
                'id'      => (string) $r->id,
                'name'    => (string) ($r->name ?? ''),
                'with'    => $seen?->toDateTimeString(),
                'without' => $miss?->toDateTimeString(),
                'state'   => $state,
            ];
        }

        return $out;
    }

    /** **خلاصةٌ بسطر**: كم يتوقّف من كلِّ بابٍ لو شُدِّد اليوم */
    public static function summary(): array
    {
        $tokens = self::apiTokens();
        $hooks = self::inboundHooks();

        return [
            'tokens'        => count($tokens),
            'tokens_break'  => count(array_filter($tokens, fn ($t) => $t['breaks'])),
            'hooks'         => count($hooks),
            'hooks_break'   => count(array_filter($hooks, fn ($h) => $h['state'] === 'يتوقّف')),
            'hooks_unknown' => count(array_filter($hooks, fn ($h) => $h['state'] === 'لم تُرصَد')),
        ];
    }
}
