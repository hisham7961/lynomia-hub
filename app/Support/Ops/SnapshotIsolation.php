<?php

namespace App\Support\Ops;

use Illuminate\Database\Connection;
use PDO;

/**
 * **دلالةُ القراءة القافلة كما بُنيت عليها الحرّاس — على MariaDB 11 أيضاً.**
 *
 * حرّاسُ التزامن (الإحياءُ فوق بديلٍ حيّ، السكُّ المزدوج، …) مبنيّةٌ على دلالة InnoDB في
 * 10.11 وMySQL 8: القراءةُ القافلة (`lockForUpdate`) **ترى أحدثَ المودَع** ولو ثبّتت
 * المعاملةُ صورتَها قبلها. وMariaDB 11.6+ تُشغّل `innodb_snapshot_isolation` افتراضاً فتردّ
 * القراءةَ القافلةَ على صفٍّ تغيّر بعد الصورة بخطأ 1020 («Record has changed since last read»)
 * — فيسقط الطلبُ بدل أن يقرّر الحارسُ بالحقيقة. (أمسكه صفُّ CI `mariadb:11.8` في v2.603.7.)
 *
 * فعند اتّصالٍ بـMariaDB ≥ 11 يُطفأ المتغيّرُ **للجلسة** — والإصدارُ يُقرأ من سمة PDO بلا
 * استعلام، فـ10.11 وMySQL وSQLite لا يُضاف إليها شيء. والإبقاءُ على الافتراض الجديد قرارٌ
 * يُتّخذ بعد مراجعةِ مواضع القفل الستّين لإعادة المحاولة: `DB_SNAPSHOT_ISOLATION=true`.
 */
final class SnapshotIsolation
{
    /** هل الخادمُ MariaDB بإصدارٍ رئيسٍ ≥ 11؟ (سلسلةُ الإصدار كما يعيدها PDO) */
    public static function needsLegacy(string $serverVersion): bool
    {
        if (stripos($serverVersion, 'mariadb') === false) return false;
        // «5.5.5-11.8.2-MariaDB-…» (بادئةُ التوافق القديمة) أو «11.8.2-MariaDB-…»
        $v = preg_replace('/^5\.5\.5-/', '', $serverVersion);

        return (int) $v >= 11;
    }

    /**
     * يُسجَّل على `ConnectionEstablished` — والاتّصالُ عندها **كسولٌ** (PDO خلف مُغلِّف):
     * فيُلَفّ المُغلِّفُ ويُطبَّق الإعدادُ حين ينشأ PDO فعلاً، فلا يُفتح اتّصالٌ لم يُطلب.
     */
    public static function apply(Connection $c): void
    {
        if ($c->getDriverName() !== 'mysql' || config('database.snapshot_isolation')) return;
        $raw = $c->getRawPdo();
        if ($raw instanceof \Closure) {
            $c->setPdo(function () use ($raw) {
                $pdo = $raw();
                self::onPdo($pdo);

                return $pdo;
            });
        } elseif ($raw instanceof PDO) {
            self::onPdo($raw);
        }
    }

    private static function onPdo(mixed $pdo): void
    {
        if ($pdo instanceof PDO && self::needsLegacy((string) $pdo->getAttribute(PDO::ATTR_SERVER_VERSION))) {
            $pdo->exec('SET SESSION innodb_snapshot_isolation = OFF');
        }
    }
}
