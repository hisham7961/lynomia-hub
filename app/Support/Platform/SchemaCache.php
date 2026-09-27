<?php

namespace App\Support\Platform;

use Illuminate\Database\Events\MigrationsEnded;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

/**
 * **خريطةُ أعمدةٍ مخبوءة — لا استبطانَ للمخطَّط في كلِّ نداء** (TECH_DEBT #25 · PERF-05).
 *
 * كان في `app/` نحوُ سبعين نداءً لـ`Schema::hasColumn` يعمل كلٌّ منها استعلاماً
 * كاملاً على `information_schema` (MySQL) أو `pragma_table_xinfo` (SQLite) —
 * **في كلِّ نداء**، وبعضُها داخلَ حلقاتٍ على الوحدات وفي قُرّاءٍ تعمل في كلِّ صفحة.
 * فالصفحةُ الواحدة تسأل القاعدةَ عن شكلها عشراتِ المرّات والجوابُ لا يتغيّر.
 *
 * هنا **استعلامٌ واحدٌ لكلِّ جدول** يُجيب عن كلِّ أعمدته، على طبقتين:
 *
 *   ١) **ذاكرةُ العمليّة** (ساكنة): النداءُ الثاني في الطلب/العمليّة نفسها بلا أيِّ استعلام.
 *   ٢) **خبيئةُ التطبيق** (`Cache`) بعمرِ {@see TTL}: تعبر الطلباتِ في PHP-FPM حيث
 *      تُصفَّر الساكنةُ مع كلِّ طلب.
 *
 * **والتقادمُ مُحكَم لا مُحتمَل** — فالميزةُ كلُّها أنّ «الهجرةَ المتأخّرةَ تُنقص
 * ميزةً ولا تُطفئ نظاماً» (انظر `hub_has_col`)، والخبيئةُ التي تكذب بعد `migrate`
 * تُطفئ تلك الميزةَ نفسَها:
 *
 *   · **كلُّ DDL** يمرّ في هذه العمليّة (`ALTER/CREATE/DROP/RENAME`) — هجرةً كان
 *     أو `SchemaGuard::fix()` أو `hub:payroll-months --fix-index` أو اختباراً يُسقط
 *     عموداً — يُفرّغ الذاكرةَ **ويرفع جيلَ الخبيئة المشترك** فتسقط مفاتيحُ كلِّ
 *     العمليّات الأخرى دفعةً (المفتاحُ يحمل الجيل).
 *   · و`MigrationsEnded` يرفعه مرّةً أخيرة بعد اكتمال الهجرات.
 *   · وذاكرةُ العمليّة نفسُها تنتهي بعد {@see TTL} — فعاملُ طابورٍ طويلُ العمر يرى
 *     هجرةً شغّلتها عمليّةٌ أخرى خلال دقائق كما كان `hub_has_col` يفعل، لا بعد إعادة تشغيله.
 *
 * **وما لا يمرّ من هنا عمداً**: كاشفا الانحراف والجاهزيّة (`SchemaGuard::gaps/statusDrift`،
 * `HardeningReadiness`، `AiReleaseCheck`) وأوامرُ الصيانة — سؤالُها «ما شكلُ القاعدة
 * **الآن**؟» لا «ما شكلُها المعتاد؟»، وهي تعمل مرّةً لا في كلِّ صفحة.
 *
 * والمفتاحُ: الاتّصالُ + قاعدتُه + بادئةُ جداوله + الجدول — فلا تتقاطع خريطتا قاعدتين.
 */
final class SchemaCache
{
    /** عمرُ الخريطة بالثواني — هو عمرُ خبيئة `hub_has_col` السابقة نفسُه */
    public const TTL = 300;

    private const GEN_KEY = 'hub:schema:gen';

    /** @var array<string, array{exp: float, cols: array<string, true>}> */
    private static array $memo = [];

    /** @var array{exp: float, gen: string}|null */
    private static ?array $gen = null;

    /** عدّادُ الاستبطانِ الفعليّ (للاختبار والتشخيص) — كم مرّةً سُئلت القاعدةُ فعلاً */
    private static int $introspections = 0;

    /** نمطُ DDL: أوّلُ كلمةٍ في الجملة — يكفي لتفريغٍ متحفّظ (تفريغٌ زائدٌ لا يضرّ) */
    private const DDL = '/^\s*(alter|create|drop|rename)\s/i';

    /** تسجيلُ مُفرِّغاتِ الخبيئة — مرّةً من `AppServiceProvider::boot` */
    public static function register(): void
    {
        Event::listen(QueryExecuted::class, static function (QueryExecuted $e): void {
            if (preg_match(self::DDL, $e->sql) === 1) self::flush();
        });
        Event::listen(MigrationsEnded::class, static fn () => self::flush());
    }

    /** هل العمودُ موجود؟ (والجدولُ ضمناً) — بلا حساسيّةِ حالةِ الأحرف كـ`Schema::hasColumn` */
    public static function hasColumn(string $table, string $column, ?string $connection = null): bool
    {
        return isset(self::map($table, $connection)[strtolower($column)]);
    }

    /** هل الأعمدةُ كلُّها موجودة؟ */
    public static function hasColumns(string $table, array $columns, ?string $connection = null): bool
    {
        $map = self::map($table, $connection);
        foreach ($columns as $c) if (! isset($map[strtolower((string) $c)])) return false;

        return true;
    }

    /** هل الجدولُ موجود؟ — الجدولُ الموجودُ له عمودٌ واحدٌ على الأقل، فالخريطةُ نفسُها تُجيب */
    public static function hasTable(string $table, ?string $connection = null): bool
    {
        return self::map($table, $connection) !== [];
    }

    /**
     * تفريغُ الخبيئة: ذاكرةُ العمليّة دائماً، و(افتراضاً) رفعُ الجيل المشترك كي
     * تسقط خرائطُ العمليّات الأخرى أيضاً. الفشلُ في الخبيئة المشتركة لا يُسقط
     * صاحبَ النداء — يقع هذا داخل هجرةٍ قد لا يكون جدولُ `cache` فيها قد أُنشئ بعد.
     */
    public static function flush(bool $shared = true): void
    {
        self::$memo = [];
        self::$gen = null;
        if (! $shared) return;
        try {
            Cache::forever(self::GEN_KEY, bin2hex(random_bytes(6)));
        } catch (\Throwable $e) {
        }
    }

    /** عددُ مرّاتِ الاستبطانِ الفعليّ منذ بدء العمليّة */
    public static function introspections(): int
    {
        return self::$introspections;
    }

    /** @return array<string, true> خريطةُ أعمدة الجدول (بأحرفٍ صغيرة) — فارغةٌ إن غاب الجدول */
    private static function map(string $table, ?string $connection): array
    {
        $conn = DB::connection($connection);
        $key = $conn->getName() . '|' . $conn->getDatabaseName() . '|' . $conn->getTablePrefix() . '|' . $table;
        $now = microtime(true);

        $hit = self::$memo[$key] ?? null;
        if ($hit !== null && $hit['exp'] > $now) return $hit['cols'];

        $cacheKey = 'hub:schema:cols:' . self::generation($now) . ':' . md5($key);
        $cols = null;
        try {
            $cached = Cache::get($cacheKey);
            if (is_array($cached)) $cols = $cached;
        } catch (\Throwable $e) {
        }

        if ($cols === null) {
            self::$introspections++;
            $cols = [];
            foreach ($conn->getSchemaBuilder()->getColumnListing($table) as $c) $cols[strtolower((string) $c)] = true;
            try {
                Cache::put($cacheKey, $cols, self::TTL);
            } catch (\Throwable $e) {
            }
        }

        self::$memo[$key] = ['exp' => $now + self::TTL, 'cols' => $cols];

        return $cols;
    }

    /** الجيلُ المشترك — يُقرأ مرّةً لكلِّ عمرِ ذاكرة، ويُنشأ إن غاب */
    private static function generation(float $now): string
    {
        if (self::$gen !== null && self::$gen['exp'] > $now) return self::$gen['gen'];

        $gen = '0';
        try {
            $gen = (string) (Cache::get(self::GEN_KEY) ?? '');
            if ($gen === '') {
                $gen = bin2hex(random_bytes(6));
                Cache::forever(self::GEN_KEY, $gen);
            }
        } catch (\Throwable $e) {
            $gen = '0';
        }
        self::$gen = ['exp' => $now + self::TTL, 'gen' => $gen];

        return $gen;
    }
}
