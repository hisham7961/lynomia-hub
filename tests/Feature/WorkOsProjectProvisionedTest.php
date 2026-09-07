<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Project;
use App\Models\Quote;
use App\Models\QuoteLine;
use App\Support\HubEvents;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * **حدثُ توفيرِ المشروع `project.provisioned`** (Work OS · الطور D · WP-D.4 · §63–73).
 *
 * لا مسارَ توفيرٍ ثانٍ: الحدثُ يُطلَق **داخلَ** معاملةِ `QuoteController::toProject`
 * المقفلةِ نفسِها (lockForUpdate + حارسُ meta.project_id)، بجانبِ `quote.converted`
 * القائم — عند صيرورةِ العرضِ مشروعاً. الخامُّ `provisioned` على وحدة projects يشتقّ
 * الدلاليَّ المُصرَّحَ في config('hub.events.projects').
 *
 * يمتدّ سابقةَ idempotency في `WorkOsProvisioningTest`/`QuoteConversionTest`
 * (حارسُ meta.project_id) لا يستنسخها. ما يحرسه هذا الملف:
 *  ١) تحويلٌ واحد ⇐ `project.provisioned` يُطلَق مرّةً واحدةً بالضبط، بجانبِ quote.converted.
 *  ٢) قبولان متتاليان لا يُضاعفانه — العدد ١ (الحارسُ يعود قبل المعاملة).
 *  ٣) يُطلَق **داخلَ** المعاملةِ (مستوى المعاملةِ لحظةَ الإطلاق أعمقُ من المحيط) —
 *     فيرتدّ معها لو ارتدّت (لا مسارَ توفيرٍ خارجَ القفل).
 *  ٤) الحدثُ الدلاليّ مُصرَّحٌ في السجل (كـWorkOsProvisioningTest).
 */
class WorkOsProjectProvisionedTest extends TestCase
{
    protected function tearDown(): void
    {
        HubEvents::forgetListeners();
        parent::tearDown();
    }

    /** عرضٌ مقبولٌ لعميلٍ ببريدِ جهةِ اتصالٍ — مرشَّحٌ للتحويل والتوفير */
    private function acceptedQuote(string $clientName, string $email): array
    {
        $c = Client::create(['name' => $clientName, 'contact' => 'جهةُ الاتصال', 'email' => $email]);
        $q = Quote::create(['client_id' => $c->id, 'title' => 'تطوير منصّة', 'total' => 12000,
            'cost' => 7000, 'currency' => 'د.ك', 'billing' => 'دفعات مراحل',
            'scope' => 'نطاق العمل الكامل', 'status' => 'مقبول', 'accepted_at' => now()]);
        QuoteLine::create(['quote_id' => $q->id, 'title' => 'اكتشاف', 'kind' => 'مرحلة', 'qty' => 1, 'unit_price' => 4000]);
        QuoteLine::create(['quote_id' => $q->id, 'title' => 'تطوير', 'kind' => 'مرحلة', 'qty' => 1, 'unit_price' => 8000]);

        return [$c, $q];
    }

    /* ───────── ١) تحويلٌ واحد ⇐ project.provisioned مرّةً، بجانبِ quote.converted ───────── */

    public function test_converting_a_quote_fires_project_provisioned_exactly_once(): void
    {
        $this->seedCore();
        [$c, $q] = $this->acceptedQuote('عميلُ التوفير', 'provision.once@client.test');

        HubEvents::forgetListeners();
        $fired = [];
        HubEvents::listen(function (string $e) use (&$fired) {
            $fired[] = $e;
        });

        $this->actingAs($this->owner)->post('/quote/' . $q->id . '/act', ['do' => 'project'])->assertRedirect();

        $this->assertSame(1, Project::where('client_id', $c->id)->count(), 'مشروعٌ واحدٌ أُنشئ من العرض');

        // الحدثُ الدلاليّ project.provisioned أُطلق مرّةً واحدةً بالضبط
        $this->assertSame(1, count(array_keys($fired, 'project.provisioned', true)),
            'project.provisioned يجب أن يُطلَق مرّةً واحدةً بالضبط عند التحويل');

        // ويُطلَق بجانبِ quote.converted القائم — لا يُزيحه ولا يُكرّره
        $this->assertSame(1, count(array_keys($fired, 'quote.converted', true)),
            'quote.converted القائم يجب أن يبقى يُطلَق مرّةً — الحدثُ الجديد إضافةٌ لا إزاحة');
    }

    /* ───────── ٢) قبولان متتاليان لا يُضاعفان الحدث ───────── */

    public function test_double_accept_does_not_double_fire_project_provisioned(): void
    {
        $this->seedCore();
        [$c, $q] = $this->acceptedQuote('عميلُ التكرار', 'provision.dup@client.test');

        HubEvents::forgetListeners();
        $fired = [];
        HubEvents::listen(function (string $e) use (&$fired) {
            $fired[] = $e;
        });

        // تحويلٌ ثم إعادةُ الفعلِ نفسِه — الحارسُ meta.project_id يعود قبل المعاملة
        $this->actingAs($this->owner)->post('/quote/' . $q->id . '/act', ['do' => 'project'])->assertRedirect();
        $this->actingAs($this->owner)->post('/quote/' . $q->id . '/act', ['do' => 'project'])->assertRedirect();

        $this->assertSame(1, Project::where('client_id', $c->id)->count(), 'مشروعٌ واحدٌ لا اثنان');
        $this->assertSame(1, count(array_keys($fired, 'project.provisioned', true)),
            'قبولٌ مكرَّرٌ أعاد إطلاقَ project.provisioned — يجب أن يبقى العددُ ١');
    }

    /* ───────── ٣) يُطلَق داخلَ المعاملة (يرتدّ معها) ───────── */

    public function test_project_provisioned_fires_inside_the_transaction(): void
    {
        $this->seedCore();
        [$c, $q] = $this->acceptedQuote('عميلُ المعاملة', 'provision.tx@client.test');

        // مستوى المعاملةِ المحيطُ (RefreshDatabase يلفّ كلَّ اختبارٍ بمعاملة) — المرجع
        $base = DB::transactionLevel();

        HubEvents::forgetListeners();
        $levelAtFire = null;
        HubEvents::listen(function (string $e) use (&$levelAtFire) {
            if ($e === 'project.provisioned') {
                $levelAtFire = DB::transactionLevel();
            }
        });

        $this->actingAs($this->owner)->post('/quote/' . $q->id . '/act', ['do' => 'project'])->assertRedirect();

        $this->assertNotNull($levelAtFire, 'project.provisioned لم يُطلَق أصلاً');
        // أعمقُ من المحيطِ بمعاملةِ toProject — دليلٌ أنّه داخلَ القفلِ لا خارجَه،
        // فيرتدّ مع ارتدادِ المعاملةِ (لا مسارَ توفيرٍ ثانٍ منفصلٌ عن الالتزام).
        $this->assertGreaterThan($base, $levelAtFire,
            'project.provisioned يجب أن يُطلَق داخلَ معاملةِ التحويلِ المقفلة، لا بعد التزامِها');
    }

    /* ───────── ٤) الحدثُ الدلاليّ مُصرَّحٌ في السجل ───────── */

    public function test_project_provisioned_event_is_declared_in_config(): void
    {
        $emits = collect(config('hub.events.projects'))->pluck('emit')->all();
        $this->assertContains('project.provisioned', $emits,
            'project.provisioned يجب أن يكون مُصرَّحاً في config(hub.events.projects) ليُشترَك عليه');
    }
}
