<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Employee;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * الموجة السادسة — الدفعة ١٤: **تسريباتُ نطاقٍ متبقّية، وضابطان ضعيفان.**
 *
 *  ١) `Innovation` — لوحةُ المساهمين ونبضُ الابتكار يقرآن **كلَّ** الأفكار
 *     خارج نطاق المستخدم، بينما قائمةُ الأفكار نفسُها منطَّقة.
 *  ٢) `team.blade.php:80` — دليلُ الفريق يطبع انتهاءَ الإقامة والجواز رغم
 *     حجبهما بقفل الحقل: الراتبُ وحده كان محروساً.
 *  ٣) `TeamDirectory:68` — «مديره» لا يظهر أبداً: `manager_id` **معرّفُ
 *     مستخدم** يُبحث عنه في خريطةِ معرّفاتِ **موظفين**.
 *  ٤) `Totp::verify:30` — تفشل **مفتوحةً** عند سرٍّ فارغ: `code('')` تُنتج رمزاً
 *     يحسبه أيُّ أحد، فحسابٌ بـ`totp_enabled` وسرٍّ فارغ يُفتَح برمزٍ معلوم.
 *  ٥) `AppServiceProvider:44` — حدُّ معدّل API مفروزٌ **بالرمز الذي يتحكّم به
 *     المهاجم**: يُدوّره فيبدأ حصّةً جديدة — الحدُّ يُتجاوَز كليّاً.
 */
class ScopeAndControlsRound6Test extends TestCase
{
    private function roleWith(array $matrix, array $flags = [], array $rules = []): Role
    {
        $none = collect(array_keys(config('hub.modules')))
            ->mapWithKeys(fn ($m) => [$m => ['v' => 0, 'a' => 0, 'e' => 0, 'd' => 0]])->all();
        $r = Role::create(['name' => 'دور ' . Str::random(5), 'scope' => 'all', 'flags' => $flags,
            'matrix' => array_replace($none, $matrix)]);
        if ($rules) $r->forceFill(['field_rules' => $rules])->save();

        return $r;
    }

    private function userWith(Role $r, ?array $companies = null): User
    {
        return User::create(['name' => 'قارئ', 'email' => 's' . Str::random(6) . '@test.local',
            'password' => 'Secret!2026x', 'role_id' => $r->id, 'status' => 'نشط',
            'companies' => $companies, 'password_changed_at' => now()]);
    }

    /* ── ١) نبضُ الابتكار ومساهموه منطَّقان ── */

    public function test_innovation_pulse_and_contributors_are_scoped(): void
    {
        $this->seedCore();

        // `ideas` بلا عمود شركة — تنطيقُها **بالمشروع**: دورٌ نطاقُه `proj`
        $role = $this->roleWith(['ideas' => ['v' => 1, 'a' => 0, 'e' => 0, 'd' => 0]]);
        $role->forceFill(['scope' => 'proj'])->save();
        $u = $this->userWith($role);

        $mine = \App\Models\Project::create(['name' => 'مشروعي', 'status' => 'تخطيط', 'manager_id' => $u->id]);
        $theirs = \App\Models\Project::create(['name' => 'مشروعٌ غريب', 'status' => 'تخطيط',
            'manager_id' => $this->owner->id]);

        \App\Models\Idea::create(['title' => 'فكرتي', 'status' => 'جديدة',
            'by_id' => $u->id, 'project_id' => $mine->id]);
        \App\Models\Idea::create(['title' => 'فكرةٌ سرّية', 'status' => 'جديدة',
            'by_id' => $this->owner->id, 'project_id' => $theirs->id,
            'impact' => 9, 'confidence' => 9, 'ease' => 9]);

        $this->actingAs($u);

        $pulse = \App\Support\Innovation::pulse();
        $this->assertSame(1, $pulse['n'],
            'نبضُ الابتكار يعدّ أفكاراً خارج نطاق القارئ — القائمةُ منطَّقة والنبضُ فوقها ليس كذلك');
        $this->assertSame(0, $pulse['scored'], 'النبضُ يقيس أفكاراً لا يراها');
        $this->assertSame(1, $pulse['people']);

        $names = collect(\App\Support\Innovation::contributors())->pluck('id')->all();
        $this->assertSame([$u->id], $names,
            'لوحةُ المساهمين تكشف مقترِحاً من مشروعٍ خارج النطاق');

        $html = $this->actingAs($u)->get('/innovation')->assertOk()->getContent();
        $this->assertStringNotContainsString('فكرةٌ سرّية', $html);
    }

    /* ── ٢) دليلُ الفريق يحترم قفلَ الحقل على الوثائق ── */

    public function test_team_directory_respects_the_field_lock_on_documents(): void
    {
        $this->seedCore();

        Employee::create(['name' => 'موظفُ الإقامة', 'status' => 'نشط',
            'iqama_exp' => today()->addDays(10)->toDateString(),
            'pass_exp' => today()->addDays(20)->toDateString()]);

        $u = $this->userWith($this->roleWith(
            ['hr' => ['v' => 1, 'a' => 0, 'e' => 0, 'd' => 0]],
            [],
            ['hr' => ['iqamaExp' => 'hide', 'passExp' => 'hide']]));

        // البطاقاتُ مجمَّعةٌ بالقسم — تُسطَّح قبل البحث
        $arr = json_decode(json_encode(\App\Support\TeamDirectory::cards($u), JSON_UNESCAPED_UNICODE), true);
        $card = collect($arr)->flatten(1)->firstWhere('name', 'موظفُ الإقامة');
        $this->assertNotNull($card, 'البطاقةُ غائبة — الاختبارُ يمرّ فراغاً لا حراسة');

        $this->assertNull($card['idDays'] ?? null,
            'دليلُ الفريق يكشف انتهاءَ الإقامة رغم حجبها بقفل الحقل — الراتبُ وحده محروس');
        $this->assertNull($card['passDays'] ?? null,
            'دليلُ الفريق يكشف انتهاءَ الجواز رغم حجبه');
    }

    /* ── ٣) «مديره» يظهر فعلاً ── */

    public function test_the_manager_name_actually_resolves(): void
    {
        $this->seedCore();

        // ما يكتبه النموذج فعلاً: `hr.managerId` حقلُ ref→**users**
        $boss = User::create(['name' => 'المديرُ المباشر', 'email' => 'b' . Str::random(6) . '@test.local',
            'password' => 'Secret!2026x', 'role_id' => $this->employee->role_id, 'status' => 'نشط',
            'password_changed_at' => now()]);
        Employee::create(['name' => 'مرؤوس', 'status' => 'نشط', 'manager_id' => $boss->id]);

        // وبياناتٌ قديمة كُتب فيها معرّفُ موظف — لا تُكسَر
        $old = Employee::create(['name' => 'مديرٌ قديم', 'status' => 'نشط']);
        Employee::create(['name' => 'مرؤوسٌ قديم', 'status' => 'نشط', 'manager_id' => $old->id]);

        $arr = json_decode(json_encode(\App\Support\TeamDirectory::cards($this->owner), JSON_UNESCAPED_UNICODE), true);
        $cards = collect($arr)->flatten(1);
        $card = $cards->firstWhere('name', 'مرؤوس');
        $this->assertNotNull($card, 'البطاقةُ غائبة — الاختبارُ يمرّ فراغاً لا حراسة');

        $this->assertSame('المديرُ المباشر', $card['manager'] ?? null,
            '«مديره» لا يظهر أبداً — الخريطةُ تُبنى بمعرّفات الموظفين ويُبحث فيها بمعرّف المستخدم');
        $this->assertSame('مديرٌ قديم', $cards->firstWhere('name', 'مرؤوسٌ قديم')['manager'] ?? null,
            'مرتجَعُ البيانات القديمة كُسر');
    }

    /* ── ٤) TOTP لا تفشل مفتوحة ── */

    public function test_totp_never_verifies_against_an_empty_secret(): void
    {
        $this->assertFalse(\App\Support\Totp::verify('', \App\Support\Totp::code('')),
            'Totp::verify تفشل مفتوحةً على سرٍّ فارغ — رمزٌ يحسبه أيُّ أحد يفتح الحساب');
        $this->assertFalse(\App\Support\Totp::verify('', '000000'));

        // والسرُّ الحقيقيّ يبقى يعمل
        $secret = \App\Support\Totp::secret();
        $this->assertTrue(\App\Support\Totp::verify($secret, \App\Support\Totp::code($secret)),
            'الحارسُ كسر التحقق السليم');
    }

    /* ── ٥) حدُّ معدّل API لا يُفرَز بما يملكه المهاجم ── */

    public function test_the_api_rate_limit_is_not_keyed_only_by_the_attackers_token(): void
    {
        // المفاتيحُ تُستخرج من المُحدِّد نفسه لا من نصّ الملف: طلبان من **عنوانٍ
        // واحد** برمزين مختلفين لا بدّ أن يتقاسما دلواً واحداً على الأقل، وإلا
        // فتدويرُ الرمز يبدأ حصّةً جديدة بلا نهاية.
        $keys = function (string $token): array {
            $r = \Illuminate\Http\Request::create('/api/v1/clients', 'GET');
            $r->headers->set('Authorization', 'Bearer ' . $token);
            $r->server->set('REMOTE_ADDR', '203.0.113.9');

            $limits = \Illuminate\Support\Facades\RateLimiter::limiter('api')($r);

            return collect(\Illuminate\Support\Arr::wrap($limits))->map(fn ($l) => (string) $l->key)->all();
        };

        $a = $keys('token-' . Str::random(20));
        $b = $keys('token-' . Str::random(20));

        $this->assertNotEmpty(array_intersect($a, $b),
            'حدُّ معدّل API مفروزٌ بالرمز وحده — والرمزُ يملكه المهاجم فيُدوّره '
            . 'ويبدأ حصّةً جديدة: الحدُّ يُتجاوَز كليّاً');

        // وفرزُ المفتاح يبقى قائماً: تكاملان شرعيّان لا ينهبان حصّةَ بعضهما كاملةً
        $this->assertNotSame($a, $b, 'الحدُّ صار جماعيّاً بحتاً — مفتاحٌ ثرثار يخنق البقية');
    }
}
