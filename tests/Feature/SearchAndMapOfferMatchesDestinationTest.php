<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Employee;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\Route as RouteFacade;
use Tests\TestCase;

/**
 * **شرطُ العرضِ = شرطُ الباب — على الأسطحِ الثلاثةِ الباقية.**
 *
 * أُغلق هذا الصنفُ في v2.536.0 على الشريطَين وصفحةِ الصباح، وبقيت ثلاثةُ
 * أسطحٍ **فُحصت نقطيّاً لا بمسحٍ شامل**: البحثُ، ولوحةُ الأوامر، وخريطةُ
 * النظام. وكلُّها تبني وجهاتِها من خريطةِ المعلوماتِ نفسِها
 * (`InformationArchitecture`)، فكلُّ انحرافٍ فيها انحرافٌ في الثلاثةِ معاً.
 *
 * والمسحُ هنا نظيرُ `NavOfferMatchesDestinationTest` حرفاً: لكلِّ شخصيّةٍ
 * تُؤخَذ **كلُّ** وجهةٍ يعرضها السطحُ، ويُطرَق بابُها بطلبِ HTTP حقيقيّ.
 * و٤٠٣ وحدَه سقوط — فوجهةٌ تعتذر بـ٤٠٤ أو تحوّل بلطفٍ رسالةٌ صادقةٌ لا
 * دعوةٌ إلى بابٍ مغلق.
 */
class SearchAndMapOfferMatchesDestinationTest extends TestCase
{
    /** حروفٌ شائعةٌ تُغطّي عملياً كلَّ التسميات — فالمسحُ لا يعتمد على استعلامٍ واحد */
    private const PROBES = ['ا', 'ل', 'م', 'ة', 'ت', 'ر', 'ع', 'ن', 'ي', 'و', 'a', 'e'];

    private int $knocked = 0;

    private function persona(string $email, array $matrix, array $flags = [],
                             string $scope = 'all', ?array $companies = null): User
    {
        $role = Role::create(['name' => 'دورُ ' . $email, 'scope' => $scope,
            'flags' => $flags, 'matrix' => $matrix]);

        $u = User::create(['name' => 'مستخدمُ ' . $email, 'email' => $email,
            'password' => 'Secret!2026x', 'role_id' => $role->id, 'status' => 'نشط',
            'account_type' => 'internal', 'password_changed_at' => now()]
            + ($companies !== null ? ['companies' => $companies] : []));

        Employee::create(['name' => 'ملفُّ ' . $email, 'user_id' => $u->id,
            'status' => 'نشط', 'email' => $email]);

        return $u;
    }


    /** أتكفي هذه الوسائطُ لبناءِ المسار؟ — ما لا يكفي وجهةٌ سياقيّةٌ لا دعوة */
    private function routeArgsSatisfied(string $name, array $args): bool
    {
        $r = RouteFacade::getRoutes()->getByName($name);
        if (! $r) return false;

        $need = collect($r->signatureParameters())->count();
        $required = array_filter($r->parameterNames(), function ($p) use ($r) {
            return ! str_contains($r->uri(), '{' . $p . '?}');
        });

        return count($required) <= count($args);
    }

    /** كلُّ وجهةٍ يعرضها البحثُ لهذه الشخصيّة، مطروقةً — ويُعاد ما رُدَّ ٤٠٣ */
    private function refusedSearchOffers(User $u): array
    {
        $ia = app(\App\Support\InformationArchitecture::class);
        $seen = $refused = [];
        $this->knocked = 0;

        foreach (self::PROBES as $q) {
            foreach ($ia->searchDestinations($u, $q) as $d) {
                $route = $d['route'] ?? null;
                if (! $route || ! RouteFacade::has($route)) continue;
                // **وجهةٌ سياقيّةٌ ليست دعوة**: مسارٌ يلزمه معرّفٌ لا يُبَثّ بلا سجلّ
                // (`system/trace/{rid}` مثلاً) — يُرسَم شارةً لا رابطاً، ولا يُطرَق
                // هنا. واختلاقُ معرّفٍ له يقيس ٤٠٤ مصطنعاً لا دعوةً كاذبة.
                if (! $this->routeArgsSatisfied($route, $d['args'] ?? [])) continue;
                $key = $route . '|' . json_encode($d['args'] ?? []);
                if (isset($seen[$key])) continue;
                $seen[$key] = true;

                $this->knocked++;
                if ($this->actingAs($u)->get(route($route, $d['args'] ?? []))->getStatusCode() === 403) {
                    $refused[] = ($d['label'] ?? '?') . ' → ' . $route;
                }
            }
        }

        return $refused;
    }

    /** وكلُّ رابطٍ **حيٍّ** ترسمه صفحةُ خريطةِ النظام — لا الشاراتُ غيرُ القابلةِ للنقر */
    private function refusedMapLinks(User $u): array
    {
        $res = $this->actingAs($u)->get(route('system-map'));
        if ($res->getStatusCode() !== 200) return [];

        preg_match_all('~href="([^"\#]+)"~', $res->getContent(), $m);
        $base = rtrim(config('app.url'), '/');
        $refused = $seen = [];

        foreach ($m[1] as $href) {
            $href = html_entity_decode($href, ENT_QUOTES);
            if (str_starts_with($href, $base)) $href = substr($href, strlen($base));
            if (! str_starts_with($href, '/') || str_starts_with($href, '/logout')) continue;
            if (isset($seen[$href])) continue;
            $seen[$href] = true;

            $this->knocked++;
            if ($this->actingAs($u)->get($href)->getStatusCode() === 403) $refused[] = $href;
        }

        return $refused;
    }

    /** الشخصيّةُ التي كشفت الصنفَ أوّلاً: معزولةٌ على شركةٍ وتحمل الراياتِ الثلاث */
    public function test_a_company_isolated_analytics_holder_is_not_invited_by_search_or_the_map(): void
    {
        $this->seedCore();
        $co = Company::create(['name_ar' => 'شركةُ راشد']);

        $u = $this->persona('rashed.map@test.local',
            ['projects' => ['v' => 1, 'fieldsec' => 1], 'hr' => ['v' => 1], 'assets' => ['v' => 1],
             'suppliers' => ['v' => 1], 'quality' => ['v' => 1], 'meetings' => ['v' => 1]],
            ['opsAnalytics' => 1, 'finAnalytics' => 1, 'secOps' => 1],
            'all', [(string) $co->id]);

        $search = $this->refusedSearchOffers($u);
        $map = $this->refusedMapLinks($u);

        $this->assertGreaterThan(15, $this->knocked,
            'لم يُطرَق بابٌ يُذكَر — المسحُ نفسُه لا يقيس شيئاً');
        $this->assertSame([], $search, 'البحثُ عرض وجهاتٍ يردّها المنتجُ ٤٠٣');
        $this->assertSame([], $map, 'خريطةُ النظامِ رسمت روابطَ حيّةً تردّ ٤٠٣');
    }

    /** ومحاسبٌ تحجبه رافعةُ حقولِ المشاريعِ عن لوحتَي التكاليف */
    public function test_the_finance_flag_without_the_field_lever_is_not_invited_by_search(): void
    {
        $this->seedCore();
        $u = $this->persona('yousef.map@test.local',
            ['projects' => ['v' => 1], 'fin' => ['v' => 1]], ['finAnalytics' => 1]);

        $refused = $this->refusedSearchOffers($u);
        $this->assertGreaterThan(10, $this->knocked, 'لم يُطرَق بابٌ يُذكَر');
        $this->assertSame([], $refused, 'البحثُ يدعو إلى لوحتَي تكاليفٍ يردّهما البابُ');
    }

    /** ولا قدرةَ تُحذف: المالكُ يجد كلَّ وجهاته وتُفتَح له */
    public function test_the_owner_finds_everything_and_every_door_opens(): void
    {
        $this->seedCore();

        $refused = $this->refusedSearchOffers($this->owner);
        $this->assertGreaterThan(30, $this->knocked,
            'انكمش ما يجده المالكُ — فقد حُذفت قدرةٌ لا دعوةٌ كاذبة');
        $this->assertSame([], $refused);
        $this->assertSame([], $this->refusedMapLinks($this->owner));
    }
}
