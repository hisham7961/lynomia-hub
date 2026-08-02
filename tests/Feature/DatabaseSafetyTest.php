<?php

namespace Tests\Feature;

use App\Support\SchemaGuard;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * **التحديثُ لا يمسّ البيانات.**
 *
 * مخاوفُ صاحب النظام في محلّها، وإن كان الواقعُ أسلمَ ممّا خشي: فحصُ الهجرات
 * كلِّها يقول إنّ **لا واحدةً منها تحذف بيانات** في `up()` — الموجودُ توسيعُ
 * أعمدةٍ وإضافتُها. لكنّ ثلاثة أبوابٍ للخطر مفتوحة:
 *
 *   ١) **لا شيء يمنع الأمر الهادم**: `migrate:fresh` و`migrate:refresh`
 *      و`migrate:reset` و`db:wipe` تمحو القاعدة كلَّها بسطر. ونصفُها يُكتب
 *      سهواً بدل `migrate` — حرفان يفرقان بين تحديثٍ ومحو.
 *   ٢) **و`migrate:rollback` يُشغّل `down()`** — وهي حيث تُحذف الأعمدة فعلاً.
 *      «تراجعٌ» في الاسم، وحذفُ بياناتٍ في الأثر.
 *   ٣) **ولا نسخةَ قبل الترحيل**: يُشغَّل على القاعدة الحيّة بلا شبكةٍ تحت.
 *
 * والقاعدةُ المفروضة هنا: **ما يمحو لا يعمل إلا بإذنٍ صريح مكتوب، والترحيلُ
 * يُسبق بنسخة، والهجرةُ لا تهدم.**
 */
class DatabaseSafetyTest extends TestCase
{
    /* ── ١) الأوامر الهادمة ── */

    public function test_the_destroying_commands_are_blocked_by_default(): void
    {
        foreach (['migrate:fresh', 'migrate:refresh', 'migrate:reset', 'db:wipe', 'migrate:rollback'] as $cmd) {
            $this->assertNotNull(SchemaGuard::blocks($cmd),
                "«{$cmd}» يمحو أو يهدم ولا شيء يمنعه — وحرفان يفرقان بينه وبين migrate");
        }
    }

    public function test_the_ordinary_migrate_is_never_blocked(): void
    {
        foreach (['migrate', 'migrate:status', 'migrate:install', 'hub:backup', 'queue:work'] as $cmd) {
            $this->assertNull(SchemaGuard::blocks($cmd), "مُنع «{$cmd}» وهو غير هادم");
        }
    }

    /** والمنعُ يقول العلاج: كيف يُؤذن عمداً حين يُراد فعلاً */
    public function test_the_refusal_names_the_deliberate_way_through(): void
    {
        $this->assertStringContainsString('HUB_ALLOW_DESTRUCTIVE', (string) SchemaGuard::blocks('migrate:fresh'));
    }

    /** وبالإذن الصريح يمرّ — قفلٌ لا سجن */
    public function test_an_explicit_permission_lets_it_through(): void
    {
        config(['hub.allow_destructive' => true]);

        $this->assertNull(SchemaGuard::blocks('migrate:fresh'));
    }

    /**
     * والمنعُ يعمل حيّاً لا نظرياً.
     * (الحاجزُ مُستثنىً داخل الحزمة — فأداةُ الاختبار تُعيد بناء قاعدةٍ مؤقتة
     * بحقّ ولا بياناتٍ فيها تُفقد — فيُستدعى هنا صراحةً ليُفحَص.)
     */
    public function test_running_the_command_actually_fails(): void
    {
        SchemaGuard::shield();
        $code = Artisan::call('migrate:fresh');

        $this->assertNotSame(0, $code, 'نُفّذ migrate:fresh فعلاً — والقاعدة تُمحى');
        $this->assertTrue(Schema::hasTable('users'), 'مُحيت الجداول رغم المنع');
    }

    /* ── ٢) لا هجرةَ تهدم ── */

    /**
     * **حارسُ المصدر**: لا هجرةٍ تُسقط عموداً أو جدولاً في `up()`. فالترحيلُ
     * إضافةٌ لا كسر — وهجرةٌ هادمةٌ واحدة تمحو ما لا يُستعاد.
     */
    public function test_no_migration_destroys_anything_on_the_way_up(): void
    {
        $bad = [];
        foreach (glob(database_path('migrations/*.php')) as $f) {
            $src = file_get_contents($f);
            if (! str_contains($src, 'function up(')) continue;
            $up = explode('function down(', explode('function up(', $src, 2)[1], 2)[0];

            /*
             * **إسقاطُ جدولٍ أنشأته الهجرةُ نفسها ليس هدماً** — لا بياناتٍ فيه
             * تُفقد (نمطُ الجدول المؤقّت). الهدمُ ما يمسّ ما كان قائماً قبلها.
             */
            preg_match_all("/Schema::create\\(\s*'([a-z_]+)'/", $up, $mk);
            $born = $mk[1] ?? [];

            preg_match_all("/Schema::dropIfExists\\(\s*'([a-z_]+)'/", $up, $md);
            foreach ($md[1] ?? [] as $t) {
                if (! in_array($t, $born, true)) $bad[] = basename($f) . " → dropIfExists('{$t}')";
            }

            foreach (['dropColumn', 'dropAllTables', 'truncate'] as $op) {
                if (str_contains($up, $op)) $bad[] = basename($f) . " → {$op}";
            }
        }

        $this->assertSame([], $bad,
            'هجرةٌ تهدم في طريق الصعود — والترحيلُ إضافةٌ لا كسر: ' . implode(' · ', $bad));
    }

    /* ── ٣) الفروقات تُكشف ── */

    /** يكشف عموداً يتوقّعه الكود ولا تملكه القاعدة — قبل أن يسقط النظام به */
    public function test_the_schema_check_finds_a_missing_column(): void
    {
        $this->seedCore();
        Schema::table('employees', fn ($t) => $t->dropColumn('iban'));

        $gaps = SchemaGuard::gaps();

        $this->assertContains('employees.iban', collect($gaps)->pluck('what')->all(),
            'عمودٌ يتوقّعه سجلُّ الوحدات وتفتقده القاعدة ولم يُكشف — فيسقط النظام به لا بإنذاره');
    }

    public function test_a_sound_database_reports_no_gaps(): void
    {
        $this->seedCore();

        $this->assertSame([], SchemaGuard::gaps(), 'أُنذر عن فروقاتٍ في قاعدةٍ سليمة');
    }

    /** ويسدّها **بالإضافة وحدها** — لا يمسّ عموداً قائماً ولا بيانةً واحدة */
    public function test_the_fix_adds_only_what_is_missing(): void
    {
        $this->seedCore();
        \App\Models\Employee::create(['name' => 'موظف', 'status' => 'نشط', 'iban' => 'KW00']);
        Schema::table('employees', fn ($t) => $t->dropColumn('iban'));

        $added = SchemaGuard::fix();

        $this->assertTrue(Schema::hasColumn('employees', 'iban'));
        $this->assertContains('employees.iban', $added);
        $this->assertSame(1, \App\Models\Employee::count(), 'مُسّت البيانات أثناء سدّ الفرق');
        $this->assertSame('موظف', \App\Models\Employee::first()->name);
    }

    /** والأمر متاحٌ من الطرفية بلا تغييرٍ افتراضيّ: يفحص ولا يكتب حتى يُطلب */
    public function test_the_command_reports_without_changing_by_default(): void
    {
        $this->seedCore();
        Schema::table('employees', fn ($t) => $t->dropColumn('iban'));

        Artisan::call('hub:schema-check');

        $this->assertStringContainsString('employees.iban', Artisan::output());
        $this->assertFalse(Schema::hasColumn('employees', 'iban'), 'كتب دون أن يُطلب منه');
    }

    public function test_the_command_fixes_when_asked(): void
    {
        $this->seedCore();
        Schema::table('employees', fn ($t) => $t->dropColumn('iban'));

        Artisan::call('hub:schema-check', ['--fix' => true]);

        $this->assertTrue(Schema::hasColumn('employees', 'iban'));
    }
}
