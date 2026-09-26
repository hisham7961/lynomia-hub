<?php

namespace Tests\Feature;

use App\Support\Ops\SnapshotIsolation;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use Mockery;
use PDO;
use Tests\TestCase;

/**
 * **دلالةُ القراءة القافلة على MariaDB 11** (v2.603.8) — صفُّ CI `mariadb:11.8` أسقط
 * `test_guard_decision_reads_see_a_mint_committed_after_the_snapshot_on_mysql` بخطأ 1020:
 * `innodb_snapshot_isolation` صار مفعّلاً افتراضاً فترفض القراءةُ القافلةُ صفّاً تغيّر بعد الصورة.
 * هنا: متى يُطفأ (الإصدار)، وأنّه لا يفتح اتّصالاً لم يُطلب، وأنّ المفتاحَ يُبقي الافتراضَ الجديد.
 */
class SnapshotIsolationTest extends TestCase
{
    public function test_only_mariadb_11_and_later_need_the_legacy_semantics(): void
    {
        foreach ([
            '11.8.2-MariaDB-ubu2404' => true,
            '5.5.5-11.8.2-MariaDB-ubu2404' => true,
            '11.4.5-MariaDB-log' => true,
            '12.0.1-MariaDB' => true,
            '10.11.8-MariaDB-0ubuntu0.24.04.1' => false,
            '5.5.5-10.11.6-MariaDB' => false,
            '8.0.36' => false,
            '8.4.2-0ubuntu' => false,
            '' => false,
        ] as $version => $expected) {
            $this->assertSame($expected, SnapshotIsolation::needsLegacy($version), "الإصدار «{$version}»");
        }
    }

    /** @return array{0: Connection, 1: \Closure(): ?\Closure} */
    private function lazyConnection(string $serverVersion, int $expectExec, bool $keepNewDefault = false): array
    {
        // مستقلٌّ عن بيئة التشغيل (DB_SNAPSHOT_ISOLATION) — كلُّ اختبارٍ يصرّح بما يفحص
        config(['database.snapshot_isolation' => $keepNewDefault]);
        $pdo = Mockery::mock(PDO::class);
        $pdo->shouldReceive('getAttribute')->with(PDO::ATTR_SERVER_VERSION)->andReturn($serverVersion);
        $pdo->shouldReceive('exec')->with('SET SESSION innodb_snapshot_isolation = OFF')->times($expectExec)->andReturn(0);

        $wrapped = null;
        $c = Mockery::mock(Connection::class);
        $c->shouldReceive('getDriverName')->andReturn('mysql');
        $c->shouldReceive('getRawPdo')->andReturn(fn () => $pdo);
        $c->shouldReceive('setPdo')->andReturnUsing(function ($p) use (&$wrapped, $c) {
            $wrapped = $p;

            return $c;
        });

        return [$c, function () use (&$wrapped) { return $wrapped; }];
    }

    public function test_the_session_switch_is_applied_lazily_when_pdo_is_created_on_mariadb_11(): void
    {
        [$c, $wrapped] = $this->lazyConnection('11.8.2-MariaDB-ubu2404', 1);
        SnapshotIsolation::apply($c);

        $this->assertInstanceOf(\Closure::class, $wrapped(), 'لم يُلَفّ مُغلِّفُ PDO — فالإعدادُ لا يُطبَّق عند الاتّصال');
        $this->assertInstanceOf(PDO::class, ($wrapped())(), 'المُغلِّفُ الملفوفُ لا يُعيد PDO نفسه');
    }

    public function test_mariadb_10_11_and_mysql_get_no_statement(): void
    {
        foreach (['10.11.8-MariaDB-0ubuntu0.24.04.1', '8.0.36'] as $v) {
            [$c, $wrapped] = $this->lazyConnection($v, 0);
            SnapshotIsolation::apply($c);
            ($wrapped())();
        }
        $this->addToAssertionCount(1);   // times(0) على exec يُتحقَّق عند إغلاق Mockery
    }

    public function test_the_switch_keeps_the_new_mariadb_default_when_asked(): void
    {
        [$c, $wrapped] = $this->lazyConnection('11.8.2-MariaDB', 0, keepNewDefault: true);
        SnapshotIsolation::apply($c);

        $this->assertNull($wrapped(), 'المفتاحُ مفعَّلٌ ومع ذلك لُفَّ الاتّصال');
    }

    /** على المحرّك نفسه: صفُّ CI `mariadb:11.8` يُثبت أنّ الجلسةَ الحقيقيّة أُطفئت */
    public function test_a_real_mariadb_11_session_runs_with_snapshot_isolation_off(): void
    {
        if (DB::connection()->getDriverName() !== 'mysql' || config('database.snapshot_isolation')
            || ! SnapshotIsolation::needsLegacy((string) DB::connection()->getPdo()->getAttribute(PDO::ATTR_SERVER_VERSION))) {
            $this->markTestSkipped('خاصٌّ بـMariaDB ≥ 11 (صفُّ CI mariadb:11.8)');
        }

        $this->assertSame(0, (int) DB::selectOne('SELECT @@SESSION.innodb_snapshot_isolation AS v')->v);
    }
}
