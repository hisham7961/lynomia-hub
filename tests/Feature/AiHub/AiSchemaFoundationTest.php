<?php

namespace Tests\Feature\AiHub;

use App\Models\AiModel;
use App\Models\AiProfile;
use App\Models\AiProfileModel;
use App\Models\AiProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * **معاييرُ قبولِ W2** — الأساسُ المخطَّطيُّ لسجلِّ المزوّدين والنماذج.
 *
 * وأثقلُ ما هنا ليس أنّ الجداولَ تُنشَأ، بل **أنّ القيودَ تُنفَّذ فعلاً**:
 * قيدٌ يُكتَب في هجرةٍ ولا يُختبَر قد يكون فهرساً عاديّاً على محرّكٍ ويُفرَض
 * على آخر — وتلك قرعةٌ تُكتشَف في الإنتاج.
 */
class AiSchemaFoundationTest extends TestCase
{
    use RefreshDatabase;

    private function provider(array $o = []): AiProvider
    {
        return AiProvider::create(array_merge([
            'catalog_key'     => 'azure_openai',
            'label'           => 'مزوّدُ اختبار',
            'credential_name' => 'cred-' . Str::random(10),
        ], $o));
    }

    private function model(AiProvider $p, array $o = []): AiModel
    {
        return AiModel::create(array_merge([
            'provider_id'        => $p->id,
            'litellm_model_name' => 'm-' . Str::random(10),
            'upstream_model'     => 'upstream-x',
            'display_name'       => 'نموذجُ اختبار',
        ], $o));
    }

    // ═══ ① الجداولُ موجودةٌ بأعمدتِها ═══

    public function test_الجداولُ_الأربعةُ_مُهاجَرة(): void
    {
        foreach (['ai_providers', 'ai_models', 'ai_profiles', 'ai_profile_models'] as $t) {
            $this->assertTrue(Schema::hasTable($t), "جدولُ $t لم يُهاجر");
        }
    }

    public function test_أعمدةُ_المعرفةِ_الأربعةُ_موجودةٌ_في_سجلِّ_النماذج(): void
    {
        foreach (['capabilities', 'limits', 'params', 'pricing', 'pricing_source', 'pricing_updated_at'] as $c) {
            $this->assertTrue(Schema::hasColumn('ai_models', $c), "عمودُ $c مفقود");
        }
    }

    // ═══ ② القيودُ تُنفَّذ فعلاً — لا تُكتَب فقط ═══

    public function test_اسمُ_الاعتمادِ_فريدٌ_فعلاً(): void
    {
        $this->provider(['credential_name' => 'dup-cred']);
        $this->expectException(\Illuminate\Database\QueryException::class);
        $this->provider(['credential_name' => 'dup-cred']);
    }

    public function test_اسمُ_النموذجِ_في_البوّابةِ_فريدٌ_فعلاً(): void
    {
        $p = $this->provider();
        $this->model($p, ['litellm_model_name' => 'dup-model']);
        $this->expectException(\Illuminate\Database\QueryException::class);
        $this->model($p, ['litellm_model_name' => 'dup-model']);
    }

    public function test_مفتاحُ_الملفِّ_فريدٌ_فعلاً(): void
    {
        AiProfile::create(['key' => 'general', 'label' => 'عام']);
        $this->expectException(\Illuminate\Database\QueryException::class);
        AiProfile::create(['key' => 'general', 'label' => 'عامٌ ثانٍ']);
    }

    public function test_لا_مرتبتانِ_متساويتانِ_في_سلسلةٍ_واحدة(): void
    {
        $pr = AiProfile::create(['key' => 'p1', 'label' => 'س']);
        $pv = $this->provider();
        AiProfileModel::create(['profile_id' => $pr->id, 'model_id' => $this->model($pv)->id, 'rank' => 0]);

        $this->expectException(\Illuminate\Database\QueryException::class);
        AiProfileModel::create(['profile_id' => $pr->id, 'model_id' => $this->model($pv)->id, 'rank' => 0]);
    }

    public function test_لا_نموذجَ_مرّتين_في_سلسلةٍ_واحدة(): void
    {
        $pr = AiProfile::create(['key' => 'p2', 'label' => 'س']);
        $m  = $this->model($this->provider());
        AiProfileModel::create(['profile_id' => $pr->id, 'model_id' => $m->id, 'rank' => 0]);

        $this->expectException(\Illuminate\Database\QueryException::class);
        AiProfileModel::create(['profile_id' => $pr->id, 'model_id' => $m->id, 'rank' => 1]);
    }

    public function test_المرتبةُ_نفسُها_مسموحةٌ_في_ملفَّين_مختلفين(): void
    {
        $a = AiProfile::create(['key' => 'p3', 'label' => 'أ']);
        $b = AiProfile::create(['key' => 'p4', 'label' => 'ب']);
        $pv = $this->provider();
        AiProfileModel::create(['profile_id' => $a->id, 'model_id' => $this->model($pv)->id, 'rank' => 0]);
        AiProfileModel::create(['profile_id' => $b->id, 'model_id' => $this->model($pv)->id, 'rank' => 0]);
        $this->assertSame(2, AiProfileModel::count());
    }

    // ═══ ③ الترتيبُ صريحٌ لا قرعة ═══

    public function test_السلسلةُ_تعود_مرتَّبةً_بالمرتبةِ_لا_بالإدراج(): void
    {
        $pr = AiProfile::create(['key' => 'p5', 'label' => 'س']);
        $pv = $this->provider();
        // تُدرَج بترتيبٍ معكوسٍ عمداً — فلو غاب ORDER BY لظهر الفرق
        foreach ([2, 0, 1] as $rank) {
            AiProfileModel::create([
                'profile_id' => $pr->id, 'model_id' => $this->model($pv)->id, 'rank' => $rank,
            ]);
        }
        $this->assertSame([0, 1, 2], $pr->links()->pluck('rank')->all(),
            'السلسلةُ لم تُرتَّب بالمرتبة — والترتيبُ غيرُ المطلوبِ قرعةٌ تفترق بين المحرّكين');
    }

    // ═══ ④ ثابتُ السرّ — لا عمودَ سرٍّ في سجلِّ المزوّدين ═══

    public function test_لا_عمودَ_سرٍّ_في_سجلِّ_المزوّدين(): void
    {
        $cols = Schema::getColumnListing('ai_providers');
        foreach (['api_key', 'secret', 'token', 'password', 'credential_value', 'credential_values', 'key'] as $bad) {
            $this->assertNotContains($bad, $cols,
                "عمودُ `$bad` في ai_providers — والسرُّ لا يُخزَّن في Hub إطلاقاً (القرار B)");
        }
        $this->assertContains('credential_name', $cols, 'مرجعُ الاعتمادِ مفقود');
    }

    public function test_لا_عمودَ_سرٍّ_في_سجلِّ_النماذج(): void
    {
        $cols = Schema::getColumnListing('ai_models');
        foreach (['api_key', 'secret', 'token', 'password'] as $bad) {
            $this->assertNotContains($bad, $cols, "عمودُ `$bad` في ai_models");
        }
    }

    // ═══ ⑤ قرارُ المالك B — السماتُ حيث قرّرها لا حيث اعتدنا ═══

    public function test_سماتُ_التدقيقِ_والنسخِ_كما_قرّر_المالك(): void
    {
        $expect = [
            AiProvider::class     => ['audit' => false, 'versions' => false],
            AiModel::class        => ['audit' => false, 'versions' => false],
            AiProfile::class      => ['audit' => true,  'versions' => false],
            AiProfileModel::class => ['audit' => true,  'versions' => false],
        ];

        foreach ($expect as $class => $want) {
            $traits = class_uses_recursive($class);
            $this->assertSame($want['audit'], in_array(\App\Traits\Auditable::class, $traits, true),
                "$class · Auditable خالف القرار B");
            $this->assertSame($want['versions'], in_array(\App\Traits\HasVersions::class, $traits, true),
                "$class · HasVersions خالف القرار B — والمزامنةُ الآليّةُ لا تُسجَّل حدثاً بشريّاً");
        }
    }

    public function test_تحديثُ_معرفةِ_نموذجٍ_لا_يُنتج_أثراً_ولا_نسخة(): void
    {
        $m = $this->model($this->provider());
        $audits   = \DB::table('audits')->count();
        $versions = \DB::table('record_versions')->count();

        // مزامنةٌ نموذجيّةٌ من البوّابة — أربعةُ أعمدةِ معرفةٍ دفعةً واحدة
        $m->update([
            'capabilities'       => ['chat' => ['v' => true, 'src' => 'litellm']],
            'limits'             => ['context_window' => ['v' => 128000, 'src' => 'litellm']],
            'params'             => ['temperature' => ['supported' => true]],
            'pricing'            => ['input_per_1k' => ['v' => 0.0025, 'src' => 'litellm']],
            'pricing_updated_at' => now(),
        ]);

        $this->assertSame($audits, \DB::table('audits')->count(),
            'المزامنةُ أنتجت أثرَ تدقيق — وهي ليست قراراً بشريّاً');
        $this->assertSame($versions, \DB::table('record_versions')->count(),
            'المزامنةُ أنتجت لقطةَ نسخة — وثلاثون نموذجاً أسبوعيّاً تُنتج ألفاً وخمسَمئةٍ سنويّاً');
    }

    public function test_تغييرُ_التوجيهِ_يُنتج_أثراً(): void
    {
        $pr = AiProfile::create(['key' => 'p6', 'label' => 'س']);
        $l  = AiProfileModel::create([
            'profile_id' => $pr->id, 'model_id' => $this->model($this->provider())->id, 'rank' => 0,
        ]);
        $before = \DB::table('audits')->count();
        $l->update(['rank' => 1]);

        $this->assertGreaterThan($before, \DB::table('audits')->count(),
            'تغييرُ ترتيبِ الاحتياطِ لم يُسجَّل — وهو يغيّر من يجيب المستخدمَ وبأيِّ كلفة');
    }

    // ═══ ⑥ الجداولُ عامّةٌ بلا انتماءِ شركة (قرارُ المالك · A) ═══

    public function test_الجداولُ_عامّةٌ_بلا_عمودِ_شركة(): void
    {
        foreach (['ai_providers', 'ai_models', 'ai_profiles', 'ai_profile_models'] as $t) {
            $this->assertNotContains('company_id', Schema::getColumnListing($t),
                "$t يحمل company_id — والخطّةُ (§15) وقرارُ المالك (A) يجعلانه عامّاً للنظام");
        }
    }

    public function test_الجداولُ_ليست_وحداتٍ_في_سجلِّ_الوحدات(): void
    {
        $modules = array_keys(config('hub.modules', []));
        foreach (['ai_providers', 'ai_models', 'ai_profiles', 'ai_profile_models'] as $t) {
            $this->assertNotContains($t, $modules,
                "$t مُسجَّلٌ وحدةً — فيُطبَّق عليه hub_scope ومسارات CRUD، وW2 حزمةُ مخطَّطٍ بلا واجهةٍ ولا مسار");
        }
    }

    // ═══ ⑦ العلاقاتُ تعمل ═══

    public function test_العلاقاتُ_تربط_في_الاتّجاهين(): void
    {
        $p = $this->provider();
        $m = $this->model($p);
        $this->assertSame($p->id, $m->provider->id);
        $this->assertSame(1, $p->models()->count());
    }
}
