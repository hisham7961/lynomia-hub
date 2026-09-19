<?php

namespace Tests\Feature\UltimateReview;

use App\Models\Project;
use App\Support\SchemaGuard;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * **الحالةُ لا تخرج من سجلِّها — والخارجةُ تُقال** (المراجعةُ الشاملة · الطبقة ١ ·
 * F-06).
 *
 * دعوى التدقيق كانت: «القاعدةُ تحوي حالاتٍ خارجَ سجلِّ الوحدات — **الكتابةُ لا
 * تُقيَّد بالـenum**». والشطرُ الأوّل صحيحٌ مقيس: في قاعدةِ المحاكاة مشروعٌ
 * بحالةِ «مخطّط» وآخرُ بـ«موقوف»، والمُعلَنُ
 * `تخطيط·نشط·قيد التنفيذ·مراجعة·مكتمل·متوقف·ملغى`.
 *
 * **والشطرُ الثاني خطأ.** أبوابُ الكتابةِ الستّةُ كلُّها تُقيّد، وهذا الملفُّ
 * يمرّ عليها بابًا بابًا بدل أن يُصدّق الدعوى:
 *
 * | الباب | الحارس |
 * |---|---|
 * | النموذج (إنشاء/تعديل) | `rules()` ⇒ `Rule::in($f['options'])` |
 * | سحبُ البطاقةِ في كانبان | `applyStatusTransition()` — «حالة غير معرَّفة في هذه الوحدة» |
 * | الإجراءُ الجماعيّ | `bulk()` — «حالة غير معروفة» |
 * | الاستيراد | `ImportController` — «قيمة غير مسموحة» |
 * | ‏`/api/v1` | يُعيد استعمالَ `rules()` نفسِها |
 * | ‏`/api/mobile/v1` | يُعيد استعمالَ `rules()` و`applyStatusTransition` نفسَيهما |
 *
 * **فمن أين يأتي الانحرافُ إذن؟** من خارجِ المتحكّمات: بذرةٌ أو سكربتٌ يكتب
 * بـEloquent، أو عملٌ يدويٌّ في القاعدة، أو — وهو الأشيعُ في التشغيل الحقيقيّ —
 * **مسؤولٌ يعيد تسميةَ خيارٍ** في `config/hub.php` فتبقى الصفوفُ القديمةُ على
 * الاسمِ القديم. وصفوفُ المحاكاةِ نفسُها تشهد: كلُّها كُتبت في ثانيتَين
 * (`11:04:49` و`11:06:02`) — بذرةٌ لا شهرُ عملٍ بشريّ.
 *
 * **والفجوةُ الحقيقيّةُ هي الصمت:** الصفُّ المنحرفُ ظاهرٌ في لوحةِ كانبان تحت
 * «⚠ غير مصنّفة» (إصلاحٌ سابق)، لكن **لا أحد يُخبَر أنّ الانحرافَ وقع**،
 * ومُرشِّحُ الحالةِ يُبنى من المُعلَن فلا يسمّي قيمتَه. فـ`SchemaGuard::statusDrift()`
 * تقولها، و`hub:schema-check` تطبعها — الأمرُ الذي يقارن أصلاً «ما يقرؤه الكود
 * بما تملكه القاعدة»، وكان يقارن الأعمدةَ وحدَها.
 */
class StatusStaysInsideItsRegistryTest extends TestCase
{
    private const OUT = 'موقوف';          // ليست من خيارات المشاريع
    private const IN = 'قيد التنفيذ';      // منها

    // ── ① أبوابُ الكتابة: ستّةٌ تُقيّد ────────────────────────────────────

    public function test_the_create_form_rejects_a_status_outside_the_registry(): void
    {
        $this->seedCore();

        $this->actingAs($this->owner)
            ->post(route('m.store', 'projects'), ['name' => 'مشروعُ القياس', 'status' => self::OUT])
            ->assertSessionHasErrors('status');

        $this->assertSame(0, Project::count(), 'حالةٌ خارجَ السجلّ دخلت من النموذج');
    }

    public function test_the_edit_form_rejects_it(): void
    {
        $this->seedCore();
        $p = Project::create(['name' => 'مشروعٌ سليم', 'status' => self::IN]);

        $this->actingAs($this->owner)
            ->put(route('m.update', ['projects', $p->id]), ['name' => $p->name, 'status' => self::OUT])
            ->assertSessionHasErrors('status');

        $this->assertSame(self::IN, $p->fresh()->status, 'التعديلُ زرع حالةً خارجَ السجلّ');
    }

    public function test_dragging_the_card_rejects_it(): void
    {
        $this->seedCore();
        $p = Project::create(['name' => 'بطاقةٌ تُسحب', 'status' => self::IN]);

        $this->actingAs($this->owner)
            ->post(route('m.status', ['projects', $p->id]), ['status' => self::OUT])
            ->assertStatus(422);

        $this->assertSame(self::IN, $p->fresh()->status, 'سحبُ البطاقةِ التفّ على قائمةِ الخيارات');
    }

    public function test_the_bulk_action_rejects_it(): void
    {
        $this->seedCore();
        $p = Project::create(['name' => 'مشروعٌ في تحديد', 'status' => self::IN]);

        $this->actingAs($this->owner)
            ->post(route('m.bulk', 'projects'), ['do' => 'status', 'ids' => [$p->id], 'status' => self::OUT])
            ->assertStatus(422);

        $this->assertSame(self::IN, $p->fresh()->status, 'الإجراءُ الجماعيُّ بابٌ خلفيٌّ للحالةِ الخارجة');
    }

    public function test_the_api_rejects_it(): void
    {
        $this->seedCore();
        $token = $this->apiToken($this->owner);

        $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson('/api/v1/projects', ['name' => 'مشروعُ الـAPI', 'status' => self::OUT])
            ->assertStatus(422);

        $this->assertSame(0, Project::count(), 'سطحُ الـAPI زرع حالةً خارجَ السجلّ');
    }

    public function test_the_import_rejects_it(): void
    {
        $this->seedCore();
        $csv = "الاسم,الحالة\nمشروعٌ مستورَدٌ سليم," . self::IN . "\nمشروعٌ مستورَدٌ منحرف," . self::OUT . "\n";

        $this->actingAs($this->owner)->post('/m/projects/import',
            ['file' => UploadedFile::fake()->createWithContent('p.csv', $csv)])->assertOk();
        $res = $this->actingAs($this->owner)->post('/m/projects/import/run',
            ['map' => [0 => 'name', 1 => 'status']]);

        $res->assertOk();
        $this->assertSame(1, Project::count(), 'الاستيرادُ زرع الصفَّ المنحرفَ مع السليم');
        $this->assertSame(self::IN, Project::first()->status);
        $this->assertCount(1, (array) $res->viewData('skipped'), 'الصفُّ المنحرفُ لم يُسمَّ متخطّىً');
    }

    // ── ② ومع ذلك: الانحرافُ يقع من خارجِ الأبواب — فيُقال ────────────────

    /** كتابةٌ مباشرةٌ في القاعدة كما تفعل بذرةٌ أو سكربتٌ أو يدٌ بشريّة */
    private function drift(string $name, string $status): Project
    {
        $p = Project::create(['name' => $name, 'status' => self::IN]);
        DB::table('projects')->where('id', $p->id)->update(['status' => $status]);

        return $p->fresh();
    }

    public function test_a_clean_database_reports_no_status_drift(): void
    {
        $this->seedCore();
        Project::create(['name' => 'مشروعٌ سليم', 'status' => self::IN]);

        $this->assertSame([], SchemaGuard::statusDrift(),
            'قاعدةٌ كلُّ حالاتِها معلنةٌ أُبلغ عنها انحرافاً — إنذارٌ كاذبٌ يُعلّم تجاهلَ التقرير');
    }

    public function test_a_row_that_escaped_the_registry_is_named_with_its_count(): void
    {
        $this->seedCore();
        $this->drift('مشروعٌ منحرفٌ أوّل', self::OUT);
        $this->drift('مشروعٌ منحرفٌ ثانٍ', self::OUT);
        $this->drift('مشروعٌ منحرفٌ ثالث', 'مخطّط');

        $drift = collect(SchemaGuard::statusDrift())->where('module', 'projects')->values();

        $this->assertCount(2, $drift, 'قيمتان منحرفتان ولم تُقالا كلتاهما');
        // الترتيبُ صريحٌ على القيمة — فلا يقترعه المحرّك
        $this->assertSame('مخطّط', $drift[0]['value']);
        $this->assertSame(1, $drift[0]['count']);
        $this->assertSame(self::OUT, $drift[1]['value']);
        $this->assertSame(2, $drift[1]['count'], 'العدُّ لا يطابق عددَ الصفوفِ الحاملةِ للقيمة');
        $this->assertContains(self::IN, $drift[1]['options'], 'التقريرُ لا يذكر المُعلَنَ ليُقارَن به');
    }

    /** والسلّةُ خارجَ الحساب — سجلٌّ محذوفٌ ناعماً ليس حالةً قائمة */
    public function test_a_trashed_row_is_not_reported_as_drift(): void
    {
        $this->seedCore();
        $p = $this->drift('مشروعٌ منحرفٌ ثمّ محذوف', self::OUT);
        $p->delete();

        $this->assertSame([], collect(SchemaGuard::statusDrift())->where('module', 'projects')->all(),
            'صفٌّ في السلّة أُبلغ عنه انحرافاً قائماً');
    }

    /** والتقريرُ يمسح **كلَّ** وحدةٍ لها سجلُّ خيارات — لا المشاريعَ وحدَها */
    public function test_the_report_sweeps_every_module_that_declares_options(): void
    {
        $this->seedCore();
        $swept = 0;
        foreach (config('hub.modules') as $k => $d) {
            if (($d['table'] ?? '') && hub_status_col($k) && ! empty(hub_status_field($k)['options'])) $swept++;
        }

        $this->assertGreaterThan(20, $swept,
            'المسحُ يغطّي وحداتٍ قليلةً — والدعوى أنّه شاملٌ لكلِّ ما يعلن خياراته');
    }

    /**
     * **والمسحُ يعبر الأعمدةَ المحجوزةَ أسماؤها.**
     *
     * `domains.ssl` عمودُ حالةٍ اسمُه **كلمةٌ محجوزةٌ في MariaDB**. أوّلُ نسخةٍ
     * من القارئ لصقت المعرّفَ نصّاً في `selectRaw` فسقط الأمرُ كلُّه بـ1064 —
     * وSQLite لا تحجز `ssl` فكانت الحزمةُ خضراء والأمرُ ميّتاً على الخادم.
     * كُشف بتشغيلٍ حيٍّ على القاعدة لا باختبار، فهذا الحارسُ يُبقيه مكشوفاً.
     */
    public function test_the_sweep_survives_a_reserved_word_status_column(): void
    {
        $this->seedCore();

        $this->assertSame('ssl', hub_status_col('domains'),
            'العمودُ المحجوزُ اسمُه لم يعد عمودَ حالةِ الدومينات — الحارسُ فقد هدفَه');
        $this->assertIsArray(SchemaGuard::statusDrift(),
            'المسحُ سقط على عمودٍ اسمُه كلمةٌ محجوزة');
    }

    // ── ③ والصفُّ المنحرفُ يبقى ظاهراً — لا يُبتلع ────────────────────────

    public function test_the_board_still_shows_a_drifted_row(): void
    {
        $this->seedCore();
        $this->drift('بطاقةٌ حالتُها خارجَ الأعمدة', self::OUT);

        $html = $this->actingAs($this->owner)->get(route('m.board', 'projects'))->assertOk()->getContent();

        $this->assertStringContainsString('بطاقةٌ حالتُها خارجَ الأعمدة', $html,
            'سجلٌّ حالتُه خارجَ أعمدةِ اللوحة اختفى من اللوحة — بطاقةٌ ضائعةٌ لا يعلم بها أحد');
        $this->assertStringContainsString('غير مصنّفة', $html, 'عمودُ الملتقَطِ غاب عن اللوحة');
    }
}
