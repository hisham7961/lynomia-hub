<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\ClientMembership;
use App\Models\Employee;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * **مجلسُ الخبراء · AUT-07 — قالبُ الأتمتةِ يُخرج ما منعته طبقةُ الصلاحيّات.**
 *
 * أُثبت البلاغُ بشقَّيه في إعادةِ الفحص:
 *
 * ١) **حلّالُ القوالب** (`FlowRunner::tpl`) يستثني `sec`/`file`/`img` **بالنوع**،
 *    ولا يستشير **أمنَ الحقول** (`hub_field_sensitive` · `config/hub_field_sec`).
 *    و«الراتب الأساسي» نوعُه `num` وهو في `hub_field_sec['hr']` — فيُحَلُّ خامّاً.
 *    فطبقةُ الأتمتةِ تُخرج من الحقلِ ما منعته طبقةُ الصلاحيّات، **بلا علمِ صاحبةِ
 *    المجال** التي لا تملك `/admin/flows` أصلاً.
 *
 * ٢) **لائحةُ الوجهات** (`FlowController:66,194`) تُبنى من `User::whereNull('deleted_at')`
 *    كاملاً — **فحساباتُ بوّابةِ العملاء فيها بلا تمييز**، والمبنيُّ عليه إشعارٌ
 *    قد يقصد موظّفاً فيبلغ عميلاً.
 *
 * **والعلاجُ إضافةٌ وضبطٌ لا حذف** (كما أوصى البلاغُ نفسُه): الحقلُ الحسّاسُ
 * **يُقنَّع** فلا يُمنع القالبُ ولا يسقط الإشعار — تُضبط رؤيتُه كما تُضبط في
 * الشاشات؛ والوجهاتُ تبقى كلُّها متاحةً **موسومةً** فلا تُنزَع قدرةُ إشعارِ عميلٍ
 * وهي قصدٌ مشروعٌ أحياناً.
 */
class CouncilFlowFieldLeakTest extends TestCase
{
    private function hrEmployee(): Employee
    {
        return Employee::create(['name' => 'حسين علي', 'status' => 'نشط', 'salary' => 1401.000]);
    }

    private function resolve(string $tpl, Employee $e): string
    {
        $ref = new \ReflectionMethod(\App\Support\Platform\FlowRunner::class, 'tpl');
        $ref->setAccessible(true);

        return $ref->invoke(null, $tpl, hub_mod('hr'), 'hr', $e);
    }

    // ═══════════ ١ · الحقلُ الحسّاسُ لا يخرج خامّاً ═══════════

    public function test_a_field_security_protected_field_is_never_rendered_raw(): void
    {
        $this->seedCore();
        $e = $this->hrEmployee();

        $this->assertTrue(hub_field_sensitive('hr', 'salary'),
            'تهيئةٌ خاطئة: «الراتب» ليس في كتالوج أمنِ الحقول');

        $out = $this->resolve('راتب {name}: {salary}', $e);

        $this->assertStringNotContainsString('1401', $out,
            'قالبُ الأتمتةِ حلَّ الراتبَ خامّاً (AUT-07): طبقةُ الأتمتةِ تُخرج من الحقلِ '
            . 'ما منعته طبقةُ الصلاحيّات، والإشعارُ الناتجُ قد يبلغ حساباً خارجيّاً.');
        $this->assertStringContainsString('حسين علي', $out,
            'الاسمُ حقلٌ غيرُ حسّاسٍ فيجب أن يبقى — التقنيعُ ضبطٌ لا تعطيل');
    }

    public function test_every_catalogued_sensitive_hr_field_is_masked(): void
    {
        $this->seedCore();
        $e = $this->hrEmployee();
        // الأعمدةُ الحقيقيّة: `civil_id` و`iban` (المفاتيحُ في القالبِ `civilId`/`iban`)
        $e->forceFill(['civil_id' => '290010112345', 'iban' => 'KW81CBKU0000000000001234560101'])->save();

        foreach (['civilId', 'iban'] as $key) {
            $out = $this->resolve('{' . $key . '}', $e);
            foreach (['290010112345', 'KW81CBKU'] as $secret) {
                $this->assertStringNotContainsString($secret, $out,
                    "الحقلُ «{$key}» مصنَّفٌ حسّاساً وخرج خامّاً في القالب");
            }
        }
    }

    public function test_an_ordinary_field_still_resolves(): void
    {
        $this->seedCore();
        $e = $this->hrEmployee();
        $e->forceFill(['dept' => 'التشغيل'])->save();

        $this->assertStringContainsString('التشغيل', $this->resolve('{dept}', $e),
            'التقنيعُ أصاب حقلاً غيرَ حسّاس — نزعُ قدرةٍ لا إصلاح');
    }

    // ═══════════ ٢ · وجهةُ الإشعارِ تُعلن أنّها حسابُ عميل ═══════════

    public function test_the_recipient_list_marks_client_portal_accounts(): void
    {
        $this->seedCore();
        $client = Client::create(['name' => 'الخليج للتأمين']);
        $role = Role::create(['name' => 'عميل' . Str::random(4), 'scope' => 'all',
            'flags' => [], 'matrix' => []]);
        // التصنيفُ **بنيويٌّ** (`users.account_type`) لا يُستنتج من العضويّات — SF-1
        $cu = User::create(['name' => 'عبير الرومي', 'email' => Str::random(9) . '@test.local',
            'password' => 'Secret!2026x', 'role_id' => $role->id, 'account_type' => 'client',
            'status' => 'نشط', 'password_changed_at' => now()]);
        ClientMembership::create(['client_id' => $client->id, 'user_id' => $cu->id, 'status' => 'active']);

        $this->assertTrue(hub_is_client($cu), 'تهيئةٌ خاطئة: ليس حسابَ عميل');

        $this->actingAs($this->owner);
        // نموذجُ البناءِ يُعرض عند اختيارِ وحدة — وفيه قائمةُ الوجهات
        $res = $this->get(route('flows.index', ['m' => 'hr']))->assertOk();

        $html = $res->getContent();
        $this->assertStringContainsString('عبير الرومي', $html,
            'حسابُ العميلِ اختفى من القائمة — والقرارُ كان **وسماً لا حذفاً**');
        $this->assertMatchesRegularExpression('/عبير الرومي[^<]*(عميل|بوّابة|خارجي)/u', $html,
            'حسابُ بوّابةِ عميلٍ معروضٌ في وجهاتِ الإشعارِ **بلا تمييز** (AUT-07): '
            . 'من يبني مساراً لا يعلم أنّ مستقبِلَه خارجُ المنشأة.');
    }
}
