<?php

namespace App\Support;

use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * رادارُ الكشف الحيّ — **من طرق باباً لا يملك مفتاحه**.
 *
 * مركزُ الأمان كان يرصد المحاولات الفاشلة للدخول (كلمة مرور خاطئة) — لكن ثمّة
 * سطحٌ آخر: **محاولةُ فتح ما لا يُملَك**. زائرٌ يخمّن رابطَ توقيعٍ أو مشاركة، أو
 * مستخدمٌ يطرق مساراً خارج صلاحيته (٤٠٣) — كلاهما إشارةُ تسلّلٍ أو تقصٍّ لا يراها
 * أحد. يلتقطها هذا الرادار من ردود المنع (٤٠٣) وتخمينِ الروابط العامّة (٤٠٤ على
 * `sign/*`/`verify/*`)، ويعرضها حيّةً في مركز الأمان مع تجميعٍ للعناوين الطارقة.
 *
 * يفشل مفتوحاً دائماً: تسجيلٌ متعذّر لا يُسقط الطلبَ ولا يمنع المنعَ نفسه.
 */
class SecurityRadar
{
    /** يلتقط محاولةَ وصولٍ مرفوضة — يُستدعى من الوسيط بعد أن يتقرّر المنع */
    public static function record(Request $r, string $kind, ?string $detail = null): void
    {
        if (! Schema::hasTable('access_denials')) return;   // قبل الهجرة: لا نكسر الطلب

        try {
            $row = [
                'kind'       => mb_substr($kind, 0, 40),
                // صاحبُ المحاولة إن كان مسجّلاً — و`null` تعني **زائراً غير مستخدم**
                'user_id'    => auth()->id(),
                'ip'         => $r->ip(),
                'method'     => mb_substr((string) $r->method(), 0, 10),
                // (WP-1.3) المسارُ والتفصيل عبر المُطهِّر الواحد: رمزُ رابطٍ عامٍّ مخمَّن
                // (`/sign/<رمز>`) كان يُخزَّن بنصّه — بيانُ اعتمادٍ في متناول قارئ الرادار
                'path'       => mb_substr(Redactor::text('/' . ltrim((string) $r->path(), '/')), 0, 300),
                'detail'     => $detail !== null ? mb_substr(Redactor::text($detail), 0, 300) : null,
                'created_at' => now(),
            ];
            // (WP-1.4) ربطُ المنع بطلبه — صفحةُ `system.trace` تجمع الأثرَ بالمعرّف الواحد
            if (hub_has_col('access_denials', 'request_id')) {
                $row['request_id'] = mb_substr((string) Api::requestId(), 0, 40) ?: null;
            }
            DB::table('access_denials')->insert($row);
        } catch (\Throwable $e) {
            // رادارٌ معطوب لا يُسقط الطلب — الكشفُ إضافةٌ لا شرطٌ للخدمة
        }
    }

    /** أحدث المحاولات المرفوضة — لعرضها حيّةً بمن ومن أين وماذا طرق */
    public static function recent(int $days = 7, int $limit = 20): Collection
    {
        if (! Schema::hasTable('access_denials')) return collect();

        return DB::table('access_denials')
            ->leftJoin('users', 'users.id', '=', 'access_denials.user_id')
            ->where('access_denials.created_at', '>=', now()->subDays($days))
            ->orderByDesc('access_denials.id')->limit($limit)
            ->get(['access_denials.kind', 'access_denials.ip', 'access_denials.path',
                   'access_denials.method', 'access_denials.detail', 'access_denials.created_at',
                   'access_denials.user_id', 'users.name as uname']);
    }

    /**
     * (WP-4.4) **القارئُ الواحد لكل عنوان** — يوحّد نسختَي «العناوين الطارقة»
     * (threats هنا وknocking في مركز الأمان): صفٌّ لكل IP فيه أوّل/آخر ظهور،
     * دخولٌ ناجح/فاشل، أهدافُ فشلٍ متمايزة، مستخدمون، أفعالٌ مريبة، ورفضٌ —
     * ثم وسمٌ (عاديّ/جديد/فشل متكرّر/تعدّد حسابات/مريب). **بلا geo خارجيّ.**
     *
     * كلُّ عمودٍ إما مفتاحُ التجميع (ip) وإما تجميعيّ — فيعمل تحت
     * ONLY_FULL_GROUP_BY على MySQL 8 كما على SQLite. المفرداتُ من
     * `SecurityEvents::actions` الواحدة لا قوائمَ حرفيةً متباعدة.
     *
     * **أوّلُ الظهور تقريبيّ** (critic: لا مصدرَ لما قبل `user_ips.first_seen_at`):
     * يُشتقّ عند القراءة من `MIN(audits.created_at)` على كامل السجلّ — والشاشةُ
     * تصرّح بذلك بوسم «تقريبيّ» لا تدّعي تاريخاً مضبوطاً.
     */
    public static function intel(\DateTimeInterface $from, ?\DateTimeInterface $to = null,
        ?string $ip = null, int $cap = 300): Collection
    {
        // `$to = null` ذيلٌ حيّ حتى هذه اللحظة (الرادار)؛ ومع TimeRange يبقى
        // الحدُّ الأعلى حصرياً `< to` كاصطلاح المنصّة كلِّها
        if (! Schema::hasTable('audits')) return collect();

        $ok = SecurityEvents::actions('AUTH_SUCCESS');
        $fail = array_merge(SecurityEvents::actions('AUTH_FAILURE'), SecurityEvents::actions('MFA_FAILURE'));
        $sus = SecurityEvents::actions('SUSPICIOUS_ACTIVITY');
        $ph = fn (array $a) => implode(',', array_fill(0, count($a), '?'));

        // ١) التدقيق مجمَّعاً بالعنوان — والفشلُ في التجميع يُنقص بطاقةً لا شاشة
        $rows = collect();
        try {
            $q = DB::table('audits')->whereNotNull('ip')->where('ip', '!=', '')
                ->where('created_at', '>=', $from)
                    ->when($to !== null, fn ($w) => $w->where('created_at', '<', $to));
            if ($ip !== null) $q->where('ip', $ip);
            $rows = $q->groupBy('ip')
                ->selectRaw('ip, COUNT(*) as events, MAX(created_at) as last_seen'
                    . ', SUM(CASE WHEN action IN (' . $ph($ok) . ') THEN 1 ELSE 0 END) as success'
                    . ', SUM(CASE WHEN action IN (' . $ph($fail) . ') THEN 1 ELSE 0 END) as fails'
                    . ', COUNT(DISTINCT CASE WHEN action IN (' . $ph($fail) . ') THEN name END) as fail_targets'
                    . ', COUNT(DISTINCT user_id) as users'
                    . ', SUM(CASE WHEN action IN (' . $ph($sus) . ') THEN 1 ELSE 0 END) as suspicious',
                    array_merge($ok, $fail, $fail, $sus))
                ->orderByRaw('MAX(created_at) DESC')->orderBy('ip')
                ->limit($cap)->get()->keyBy('ip');
        } catch (\Throwable $e) {
            ErrorLog::capture('php', 'security-radar: تعذّر تجميعُ ذكاء العناوين — ' . $e->getMessage(),
                $e->getFile(), $e->getLine());
        }

        // ٢) الرفضُ المسجَّل مجمَّعاً بالعنوان — عنوانٌ يطرق ٤٠٣ فقط يظهر أيضاً
        $den = collect();
        if (Schema::hasTable('access_denials')) {
            try {
                $dq = DB::table('access_denials')->whereNotNull('ip')->where('ip', '!=', '')
                    ->where('created_at', '>=', $from)
                    ->when($to !== null, fn ($w) => $w->where('created_at', '<', $to));
                if ($ip !== null) $dq->where('ip', $ip);
                $den = $dq->groupBy('ip')
                    ->selectRaw('ip, COUNT(*) as denials, COUNT(DISTINCT path) as paths'
                        . ', SUM(CASE WHEN user_id IS NULL THEN 1 ELSE 0 END) as anon'
                        . ', MAX(created_at) as last_denial, MIN(created_at) as first_denial')
                    ->orderByRaw('COUNT(*) DESC')->orderBy('ip')->limit($cap)->get()->keyBy('ip');
            } catch (\Throwable $e) {
                $den = collect();
            }
        }

        // ٣) الدمجُ: اتحادُ العنوانين — وقيمُ الغائب أصفارٌ صريحة لا null غامض
        $out = collect();
        foreach ($rows as $k => $r) {
            $d = $den->get($k);
            $r->denials = (int) ($d->denials ?? 0);
            $r->denial_paths = (int) ($d->paths ?? 0);
            $r->anon = (int) ($d->anon ?? 0);
            // آخرُ الظهور من المصدرين معاً (صيغةُ التاريخ تقارَن نصياً بأمان)
            if ($d && $d->last_denial && (string) $d->last_denial > (string) $r->last_seen) {
                $r->last_seen = $d->last_denial;
            }
            $out->put($k, $r);
        }
        foreach ($den as $k => $d) {
            if ($out->has($k)) continue;
            $out->put($k, (object) ['ip' => $k, 'events' => 0, 'last_seen' => $d->last_denial,
                'success' => 0, 'fails' => 0, 'fail_targets' => 0, 'users' => 0, 'suspicious' => 0,
                'denials' => (int) $d->denials, 'denial_paths' => (int) $d->paths, 'anon' => (int) $d->anon]);
        }

        // ٤) أوّلُ ظهورٍ تقريبيّ من كامل سجلّ التدقيق (لا من النافذة وحدها)
        $firsts = [];
        if ($out->isNotEmpty()) {
            try {
                $firsts = DB::table('audits')->whereIn('ip', $out->keys()->all())
                    ->groupBy('ip')->selectRaw('ip, MIN(created_at) as first_seen')
                    ->pluck('first_seen', 'ip')->all();
            } catch (\Throwable $e) {
            }
        }
        foreach ($out as $k => $r) {
            $r->first_seen = $firsts[$k] ?? ($den->get($k)->first_denial ?? null);
            $r->label = self::ipLabel($r);
        }

        // الترتيبُ الحتميّ: الأحدثُ ظهوراً أولاً وفاصلُ التعادل العنوانُ نفسُه (فريد)
        return $out->sortBy([['last_seen', 'desc'], ['ip', 'asc']])->values();
    }

    /** عتبتا الوسم: فشلٌ متكرّرٌ يستحق النظر، ورفضٌ غزيرٌ وحدَه يكفي ريبةً */
    public const REPEAT_FAILS = 5;
    public const SUSPECT_DENIALS = 10;

    /**
     * وسمُ العنوان — بأولويةٍ تنازلية: مريب (نشاطٌ مريبٌ مرصود أو رفضٌ غزير) ثم
     * تعدّدُ حسابات (فشلٌ على أكثر من هدف) ثم فشلٌ متكرّر ثم جديدٌ (أوّلُ ظهورٍ
     * خلال ٧ أيام) ثم عاديّ.
     *
     * @return array{key: string, label: string, tone: string}
     */
    public static function ipLabel(object $x): array
    {
        if ((int) ($x->suspicious ?? 0) > 0 || (int) ($x->denials ?? 0) >= self::SUSPECT_DENIALS) {
            return ['key' => 'suspicious', 'label' => 'مريب', 'tone' => 'bad'];
        }
        if ((int) ($x->fail_targets ?? 0) > 1) return ['key' => 'multi', 'label' => 'تعدّد حسابات', 'tone' => 'bad'];
        if ((int) ($x->fails ?? 0) >= self::REPEAT_FAILS) return ['key' => 'fails', 'label' => 'فشل متكرّر', 'tone' => 'wn'];
        if (! empty($x->first_seen)
            && \Illuminate\Support\Carbon::parse($x->first_seen)->gt(now()->subDays(7))) {
            return ['key' => 'new', 'label' => 'جديد', 'tone' => 'wn'];
        }

        return ['key' => 'normal', 'label' => 'عاديّ', 'tone' => 'ok'];
    }

    /**
     * العناوين الطارقة: من كثُرت محاولاتُه المرفوضة وتعدّدت أهدافُه — تقصٍّ لا خطأ.
     * (WP-4.4) صارت إسقاطاً على القارئ الواحد `intel` — لا نسخةَ تجميعٍ ثانية.
     */
    public static function threats(int $days = 7, int $limit = 6, ?Collection $intel = null): Collection
    {
        // ذكاءٌ محسوبٌ لتوّه في الطلب نفسه (النافذةُ ذاتُها) يُغني عن تجميعٍ ثانٍ
        return ($intel ?? self::intel(now()->subDays($days)))
            ->filter(fn ($r) => (int) $r->denials > 0)
            ->sortBy([['denials', 'desc'], ['ip', 'asc']])->take($limit)
            ->map(fn ($r) => (object) ['ip' => $r->ip, 'hits' => (int) $r->denials,
                'targets' => (int) $r->denial_paths, 'anon' => (int) $r->anon])
            ->values();
    }

    /** ملخّصٌ للشريط العلوي: كم محاولة، من كم عنوان، وكم منها من غير مستخدم */
    public static function summary(int $days = 7): array
    {
        if (! Schema::hasTable('access_denials')) return ['total' => 0, 'ips' => 0, 'anon' => 0];

        $since = now()->subDays($days);
        $base = fn () => DB::table('access_denials')->where('created_at', '>=', $since);

        return [
            'total' => (int) $base()->count(),
            'ips'   => (int) $base()->whereNotNull('ip')->distinct()->count('ip'),
            'anon'  => (int) $base()->whereNull('user_id')->count(),   // محاولاتُ غير المستخدمين
        ];
    }

    /**
     * تقليمُ الجدول — **حدٌّ زمنيٌّ وسقفٌ صلب معاً**.
     *
     * كلُّ منعٍ يكتب صفّاً، فالجدولُ سطحٌ قد يفيض تحت طرقٍ متعمَّد. تسعون يوماً
     * تكفي للتقصّي، وسقفٌ صلبٌ (أحدث `maxRows`) يحدّ فيضاً **داخل** النافذة كي
     * لا يملأ القرصَ على الاستضافة المشتركة. يُنفَّذ في دورة الأتمتة اليومية لا
     * في طلب المستخدم — كما تُقلَّم إخوتُه (زيارات الصفحات، نقاط المقاييس).
     */
    public static function prune(int $keepDays = 90, int $maxRows = 50000): int
    {
        if (! Schema::hasTable('access_denials')) return 0;

        $n = DB::table('access_denials')->where('created_at', '<', now()->subDays($keepDays))->delete();

        // السقفُ الصلب: معرّفُ الصفّ عند حدّ الاحتفاظ، فيُحذف كلُّ ما قبله
        $cut = DB::table('access_denials')->orderByDesc('id')->skip($maxRows)->take(1)->value('id');
        if ($cut) $n += DB::table('access_denials')->where('id', '<=', $cut)->delete();

        return $n;
    }
}
