<?php

namespace Tests\Feature\AiHub;

use App\Models\AiModel;
use App\Models\AiProfile;
use App\Models\AiProfileModel;
use App\Models\AiProvider;
use App\Support\Ai\Routing\AiProfiles;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * **شاشةُ الأغراضِ وسلاسلِ التوجيه** (المرحلة ٢ · W7) — البابُ والهويّةُ والصدق.
 *
 * وأهمُّ ما تحرسه: أنّ الشاشةَ **تقول لماذا** لا أن تُخفي. نموذجٌ لا يصلح لغرضٍ
 * يُعرَض مع سببِه، وحلقةٌ خرجت من السلسلةِ يُقال سببُ خروجِها — **والإخفاءُ
 * يُنتج بحثاً عن عطلٍ لا وجودَ له**.
 */
class AiProfileScreenTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'sk-W7PROFILE9c1a4e7b55dd8822f6b3';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedCore();
        AiProfiles::seed();
    }

    private function stepped()
    {
        return $this->withSession(['stepup.ok_until' => now()->addMinutes(10)->timestamp]);
    }

    private function provider(): AiProvider
    {
        return AiProvider::create([
            'catalog_key'      => 'openai',
            'label'            => 'مزوّدٌ وهميّ',
            'enabled'          => true,
            'credential_name'  => 'hub-fake-' . substr(sha1(self::SECRET), 0, 10),
            'credential_state' => 'configured',
            'config'           => ['note' => 'بلا سرّ'],
        ]);
    }

    private function model(AiProvider $p, string $name, array $caps = ['chat' => true]): AiModel
    {
        $facts = [];
        foreach ($caps as $k => $v) $facts[$k] = ['v' => $v, 'src' => $v === null ? 'unknown' : 'litellm'];

        return AiModel::create([
            'provider_id'        => $p->id,
            'litellm_model_name' => $name,
            'upstream_model'     => 'fake/' . $name,
            'display_name'       => $name,
            'enabled'            => true,
            'capabilities'       => $facts,
            'limits'             => [], 'params' => [], 'pricing' => [],
        ]);
    }

    private function profile(string $key): AiProfile
    {
        return AiProfile::query()->where('key', $key)->firstOrFail();
    }

    // ═══ البابُ والهويّة ═══

    public function test_موظّفٌ_عاديٌّ_يُصَدّ(): void
    {
        $this->actingAs($this->employee)->get(route('ai.profiles.index'))->assertForbidden();
    }

    public function test_المالكُ_يفتح_الشاشة(): void
    {
        $this->actingAs($this->owner)->get(route('ai.profiles.index'))->assertOk();
    }

    /** **وكلُّ كتابةٍ خلف هويّةٍ طازجة** — وترتيبُ السلسلةِ يغيّر من يُجيب وبأيِّ كلفة */
    public function test_كلُّ_كتابةٍ_تحتاج_هويّةً_طازجة(): void
    {
        $g    = $this->profile('general');
        $m    = $this->model($this->provider(), 'alpha');
        AiProfiles::attach($g, $m);
        $link = AiProfileModel::query()->firstOrFail();

        $writes = [
            ['post',   route('ai.profiles.seed'),                 []],
            ['post',   route('ai.profiles.attach', $g),           ['model_id' => $m->id]],
            ['post',   route('ai.profiles.reorder', $g),          ['order' => [$link->id]]],
            ['post',   route('ai.profiles.toggle', $g),           ['enabled' => 0]],
            ['post',   route('ai.profiles.ask', $g),              []],
            ['post',   route('ai.profiles.link.toggle', $link),   ['enabled' => 0]],
            ['delete', route('ai.profiles.detach', $link),        []],
        ];

        foreach ($writes as [$verb, $uri, $payload]) {
            $res = $this->actingAs($this->owner)->{$verb}($uri, $payload);
            $res->assertRedirect();
            $this->assertStringContainsString('/stepup', (string) $res->headers->get('Location'),
                "الكتابةُ {$uri} مرّت بلا تصعيدِ هويّة");
        }
    }

    // ═══ الدورةُ من الشاشة ═══

    public function test_الضمُّ_والترتيبُ_والإخراجُ_تعمل_من_الشاشة(): void
    {
        $g = $this->profile('general');
        $p = $this->provider();
        $a = $this->model($p, 'alpha');
        $b = $this->model($p, 'beta');

        foreach ([$a, $b] as $m) {
            $this->actingAs($this->owner)->stepped()
                ->post(route('ai.profiles.attach', $g), ['model_id' => $m->id])
                ->assertRedirect()->assertSessionHasNoErrors();
        }

        $this->assertSame(['alpha', 'beta'],
            AiProfiles::chain($g->fresh())->pluck('litellm_model_name')->all());

        $ids = AiProfileModel::query()->where('profile_id', $g->id)->orderBy('rank')->pluck('id')->all();
        $this->actingAs($this->owner)->stepped()
            ->post(route('ai.profiles.reorder', $g), ['order' => [$ids[1], $ids[0]]])
            ->assertSessionHasNoErrors();

        $this->assertSame(['beta', 'alpha'],
            AiProfiles::chain($g->fresh())->pluck('litellm_model_name')->all());

        $this->actingAs($this->owner)->stepped()
            ->delete(route('ai.profiles.detach', $ids[0]))->assertSessionHasNoErrors();

        $this->assertSame(['beta'],
            AiProfiles::chain($g->fresh())->pluck('litellm_model_name')->all());
    }

    /** **وغرضٌ يشترط قدرةً يَرُدّ الضمَّ من الشاشةِ برسالةٍ تقول لماذا** */
    public function test_الشاشةُ_تَرُدّ_ضمَّ_نموذجٍ_قدرتُه_غيرُ_مثبتة(): void
    {
        $v = $this->profile('vision');
        $m = $this->model($this->provider(), 'no-proof');   // لا ذكرَ للرؤية

        $this->actingAs($this->owner)->stepped()
            ->post(route('ai.profiles.attach', $v), ['model_id' => $m->id])
            ->assertRedirect()->assertSessionHasErrors('model_id');

        $this->assertSame(0, AiProfileModel::count(),
            '**ضُمّ نموذجٌ قدرتُه مجهولةٌ إلى غرضٍ يشترطها**');
    }

    // ═══ الصدقُ في العرض ═══

    /** الشاشةُ تعرض **جدولَ القرارِ نفسَه** فلا يُقرَأ من شيفرة */
    public function test_الشاشةُ_تعرض_جدولَ_القرار(): void
    {
        $this->actingAs($this->owner)->get(route('ai.profiles.index'))->assertOk()
            ->assertSee('جدولُ القرارِ عند الإخفاق', false)
            ->assertSee('stream_interrupted')
            ->assertSee('budget_exceeded')
            ->assertSee('يُخفي العطل', false)
            ->assertSee('يُضاعف الإنفاقَ الممنوع', false);
    }

    /** وتُعلن الحرّاسَ الأربعةَ بأرقامِها الحيّة */
    public function test_الشاشةُ_تُعلن_الحرّاسَ_الأربعة(): void
    {
        $this->actingAs($this->owner)->get(route('ai.profiles.index'))->assertOk()
            ->assertSee('عمقُ الاحتياط', false)
            ->assertSee('ميزانيّةُ الطلب', false)
            ->assertSee('تهدئةُ المزوّد', false)
            ->assertSee('كلُّ قفزةٍ تُسجَّل', false)
            ->assertSee('كلفةٌ مجهولةٌ لا تمرّ تحت سقف', false);
    }

    /** **ولا حلقةَ تُخفى بلا سبب** */
    public function test_الشاشةُ_تقول_لماذا_خرجت_الحلقةُ_من_السلسلة(): void
    {
        $g = $this->profile('general');
        $m = $this->model($this->provider(), 'later-off');
        AiProfiles::attach($g, $m);
        $m->forceFill(['enabled' => false])->save();

        $this->actingAs($this->owner)->get(route('ai.profiles.index'))->assertOk()
            ->assertSee('خارجَ السلسلةِ الآن', false)
            ->assertSee('النموذجُ مُعطَّل', false);
    }

    /** والغرضُ الفارغُ يقول الخطوةَ التاليةَ بدل أن يصمت */
    public function test_الغرضُ_الفارغُ_يقول_الخطوةَ_التالية(): void
    {
        $this->actingAs($this->owner)->get(route('ai.profiles.index'))->assertOk()
            ->assertSee('سلسلةٌ فارغة', false)
            ->assertSee('طلبٌ بلا سلسلةٍ لا يُوجَّه إلى أحد', false);
    }

    // ═══ الملاءمةُ للمساعدِ تُقرَأ من الشاشة (المرحلة ٥ · W6) ═══

    /**
     * **شاشةُ التوجيهِ تقول لماذا يصلح النموذجُ للمساعدِ أو لا يصلح.**
     *
     * و«خارجَ السلسلةِ الآن» وحدَها تخلط ستَّ بوّاباتٍ في جملةٍ واحدة: قدرةٌ
     * وتوافرٌ وصحّةٌ وسياسةٌ وميزانيّةٌ وملاءمة. فالمديرُ يقرؤها ويذهب يُراجع
     * الاعتمادَ بينما النقصُ **قدرةٌ لا يُصلحها اعتماد** — ويعود بعد نصفِ
     * ساعةٍ إلى العطلِ نفسِه.
     */
    public function test_شاشةُ_التوجيهِ_تُسمّي_القدرةَ_الناقصةَ_للمساعد(): void
    {
        $ask = $this->profile(\App\Support\Ai\Ask\AskPolicy::profileKey());
        AiProfiles::attach($ask, $this->model($this->provider(), 'chat-only', ['chat' => true]));

        $this->actingAs($this->owner)->get(route('ai.profiles.index'))->assertOk()
            ->assertSee('يلزمه: chat + tools', false)
            ->assertSee(\App\Support\Ai\Routing\AiPurposes::TAG[\App\Support\Ai\Routing\AiPurposes::UNFIT], false)
            ->assertSee('لا يُلائم «اسأل Hub»', false)
            ->assertSee('ينقص النموذجَ لهذا الغرض', false);
    }

    /** **ونموذجٌ مُلائمٌ يُوسَم مُلائماً** — فالوسمُ خبرٌ لا إنذارٌ دائم */
    public function test_النموذجُ_المُلائمُ_يُوسَم_ولا_يُنذَر_عنه(): void
    {
        $ask = $this->profile(\App\Support\Ai\Ask\AskPolicy::profileKey());
        AiProfiles::attach($ask, $this->model($this->provider(), 'chat-and-tools',
            ['chat' => true, 'tools' => true]));

        $this->actingAs($this->owner)->get(route('ai.profiles.index'))->assertOk()
            ->assertSee(\App\Support\Ai\Routing\AiPurposes::TAG[\App\Support\Ai\Routing\AiPurposes::FIT], false)
            ->assertDontSee('لا يُلائم «اسأل Hub»', false);
    }

    /** **وغرضٌ غيرُ غرضِ المساعدِ لا يُوسَم بملاءمةٍ لا تعنيه** */
    public function test_غرضٌ_آخرُ_لا_يحمل_وسمَ_ملاءمةِ_المساعد(): void
    {
        $coding = $this->profile('coding');
        AiProfiles::attach($coding, $this->model($this->provider(), 'coder', ['chat' => true]));

        $html = (string) $this->actingAs($this->owner)
            ->get(route('ai.profiles.index'))->assertOk()->getContent();

        $this->assertStringNotContainsString('ينقص النموذجَ لهذا الغرض', $html,
            '**غرضٌ لا يُدير دورةَ أدواتٍ وُسم بحاجةِ المساعد**');
    }

    public function test_لا_سرَّ_في_صفحةِ_الأغراض(): void
    {
        $g = $this->profile('general');
        AiProfiles::attach($g, $this->model($this->provider(), 'alpha'));

        $html = $this->actingAs($this->owner)->get(route('ai.profiles.index'))->assertOk()->getContent();

        $this->assertMaskedValueAbsent(self::SECRET, (string) $html,
            '**تسريب**: سرُّ المزوّدِ ظهر في صفحةِ الأغراض');
    }
}
