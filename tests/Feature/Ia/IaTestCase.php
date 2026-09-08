<?php

namespace Tests\Feature\Ia;

use App\Models\Client;
use App\Models\Role;
use App\Models\User;
use App\Support\InformationArchitecture;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * قاعدةُ اختباراتِ الهندسة المعلوماتية (IA Phase 2) — تبني الحساباتِ التمثيليّة
 * التي تُثبت **قاعدةَ الصحّة C1**: IA لا يمنحُ صلاحيةً بل يفوّضُ الرؤيةَ للمُسنِد
 * القائم. كلُّ حسابٍ يُنشأ **حيّاً في قاعدة البيانات** (لا كائنٌ مُصطنَع) لأن
 * `hub_is_client`/`hub_client_ids` يقرآن `account_type`/جدولَ العضويّات فعليّاً —
 * فحسابٌ صوريٌّ لا يُثبت شيئاً (تحذيرُ المانيفست).
 *
 * لا يُكرّر منطقَ الحرّاس: يُنشئ أدواراً/مستخدمين فقط ثمّ يسأل الخدمةَ والمتحكّمات.
 */
abstract class IaTestCase extends TestCase
{
    /** اسمُ دور الرقابة المُسنَد في الإعداد collab.oversight_role — دورٌ غيرُ المالك */
    protected const OVERSIGHT_ROLE = 'مراقبُ الامتثال';

    /** كلمةُ مرورٍ موحّدةٌ تُمكّن نافذةَ التصعيد (StepUp) في اختبارات الرقابة */
    protected const PW = 'Secret!2026x';

    protected function ia(): InformationArchitecture
    {
        return new InformationArchitecture();
    }

    /** مستخدمٌ حيٌّ بدورٍ مُعطى — النمطُ الموحّد */
    protected function makeUser(Role $role, array $extra = []): User
    {
        return User::create(array_merge([
            'name' => 'حساب ' . Str::random(4),
            'email' => Str::random(10) . '@ia.local',
            'password' => self::PW,
            'role_id' => $role->id,
            'status' => 'نشط',
            'password_changed_at' => now(),
        ], $extra));
    }

    /** مراقبٌ: رايةُ monitor فقط، بلا مصفوفةِ صلاحيّات (hub_monitor=true، hub_can=false) */
    protected function monitorUser(): User
    {
        $role = Role::create(['name' => 'مراقب ' . Str::random(4), 'scope' => 'all',
            'flags' => ['monitor' => 1], 'matrix' => []]);

        return $this->makeUser($role);
    }

    /**
     * موظفٌ مُنطَّقٌ ضيّقاً: عرضٌ (v) على الوحدات المُمرَّرة **فقط**، بلا رايات —
     * لا مالكٌ ولا مراقبٌ ولا مسؤولُ إدارة. عمودُ «الموظف مُنطَّق الوحدة» في المانيفست.
     */
    protected function scopedUser(array $viewModules): User
    {
        $matrix = collect($viewModules)->mapWithKeys(fn ($m) => [$m => ['v' => 1]])->all();
        $role = Role::create(['name' => 'مُنطَّق ' . Str::random(4), 'scope' => 'all',
            'flags' => [], 'matrix' => $matrix]);

        return $this->makeUser($role);
    }

    /** رايةٌ + مصفوفةٌ معاً — لاختبار حارس field (owner || (hr.v && monitor)) */
    protected function flaggedUser(array $flags, array $viewModules = []): User
    {
        $matrix = collect($viewModules)->mapWithKeys(fn ($m) => [$m => ['v' => 1]])->all();
        $role = Role::create(['name' => 'دور ' . Str::random(4), 'scope' => 'all',
            'flags' => $flags, 'matrix' => $matrix]);

        return $this->makeUser($role);
    }

    /**
     * ضابطُ رقابةٍ: اسمُ الدور = الإعداد `collab.oversight_role` صراحةً، بلا رايةٍ
     * ولا مصفوفة — فالبوّابةُ على الاسم لا على امتيازٍ مضمَّن (ليست باباً للمالك).
     */
    protected function oversightOfficer(): User
    {
        $this->hubSetting('collab.oversight_role', self::OVERSIGHT_ROLE);
        $role = Role::create(['name' => self::OVERSIGHT_ROLE, 'scope' => 'all', 'flags' => [], 'matrix' => []]);

        return $this->makeUser($role);
    }

    /** حسابُ عميلٍ صلبٌ (account_type=client) — hub_is_client=true */
    protected function clientAccount(): User
    {
        $role = Role::create(['name' => 'عميل ' . Str::random(4), 'scope' => 'all', 'flags' => [], 'matrix' => []]);

        return $this->makeUser($role, ['account_type' => 'client']);
    }

    /**
     * داخليٌّ معزولٌ بعملاءَ بأعيانهم (hub_client_ids != null، وليس حسابَ عميل) —
     * بمصفوفةٍ كاملةٍ عمداً كي يكون العزلُ العملاءيُّ وحدَه هو الحاجز.
     */
    protected function clientScopedUser(): User
    {
        $client = Client::create(['name' => 'عميلُ العزل ' . Str::random(4), 'stage' => 'عميل حالي']);
        $all = collect(array_keys(config('hub.modules')))
            ->mapWithKeys(fn ($m) => [$m => ['v' => 1, 'a' => 1, 'e' => 1, 'd' => 1]])->all();
        $role = Role::create(['name' => 'مخصَّصٌ لعميل ' . Str::random(4), 'scope' => 'all',
            'flags' => ['monitor' => 1], 'matrix' => $all]);

        return $this->makeUser($role, ['clients' => [$client->id]]);
    }

    /** يختم نافذةَ تصعيدٍ حقيقيّةً بكلمة المرور — كما في الإنتاج (لا تزوير للحالة) */
    protected function freshStepUp(User $u): void
    {
        $this->actingAs($u)->post('/stepup', ['answer' => self::PW, 'next' => '/'])->assertRedirect();
    }
}
