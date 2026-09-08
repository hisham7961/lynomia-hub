<?php

namespace Tests\Feature\Ia;

use Illuminate\Support\Facades\Route;

/**
 * **تغطيةُ المسارات (P2 · المانيفست §IaRouteCoverageTest).**
 *
 * `InformationArchitecture::routeLocation()` يحلُّ **كلَّ** مسارات GET المُسمّاة دون
 * أن يرمي، وكلُّ مسارٍ يقع في نطاقٍ معروف (surface|domain|module|deprecated|bucket)
 * — لا «غيرُ مصنَّف». يُثبِت أنّ index/show/create/edit/board/export/import/workspace/
 * center/admin/utility/public/system تجد موقعها، وأنّ المؤرشفَ يبقى يُحَلّ (صفر فقدان).
 *
 * يمرُّ على قائمةِ المسارات الحقيقيّة (Route::getRoutes) لا على قائمةٍ مكتوبةٍ يدويّاً.
 */
class IaRouteCoverageTest extends IaTestCase
{
    /** أسماءُ كلِّ مسارات GET المُسمّاة (غيرُ المُسمّاة آليّةٌ لا تُقصَد بالاسم) */
    private function namedGetRoutes(): array
    {
        $out = [];
        foreach (Route::getRoutes() as $r) {
            if (! in_array('GET', $r->methods(), true)) continue;
            $name = $r->getName();
            if ($name === null || $name === '') continue;
            $out[$name] = $r->uri();
        }

        return $out;
    }

    /** المعاملاتُ الدنيا التي يحتاجها التصنيف: الوحدةُ لِـm.*، مفتاحُ المساحة لِـworkspace */
    private function paramsFor(string $name): array
    {
        if (str_starts_with($name, 'm.')) return ['module' => 'tasks'];
        if ($name === 'workspace') return ['key' => 'entities'];

        return [];
    }

    /** ١) كلُّ مسارٍ مُسمّى يُحَلُّ دون رميٍ، وصفرُ «غيرِ مصنَّف» */
    public function test_all_named_get_routes_classify_without_throwing(): void
    {
        $this->seedCore();
        $ia = $this->ia();
        $routes = $this->namedGetRoutes();
        $this->assertGreaterThan(180, count($routes), 'قائمةُ المسارات المُسمّاة أقصرُ من المتوقَّع');

        $allowed = ['surface', 'domain', 'module', 'deprecated', 'bucket'];
        $unclassified = [];
        $resolved = 0;
        foreach ($routes as $name => $uri) {
            $loc = $ia->routeLocation($name, $this->paramsFor($name));   // لا try/catch: أيُّ رميٍ يُسقط الاختبار (المقصود)
            $resolved++;
            $this->assertArrayHasKey('scope', $loc, "المسارُ {$name} بلا نطاق");
            $this->assertContains($loc['scope'], $allowed, "المسارُ {$name} في نطاقٍ غيرِ معروف: {$loc['scope']}");
            if (($loc['scope'] ?? '') === 'bucket' && ($loc['bucket'] ?? '') === 'unclassified') {
                $unclassified[] = "{$name} ({$uri})";
            }
        }
        $this->assertSame(count($routes), $resolved, 'مساراتٌ لم تُحَلّ');
        $this->assertSame([], $unclassified, 'مساراتٌ مستخدَمٌ لها غيرُ مصنَّفة: ' . implode(' · ', $unclassified));
    }

    /** ٢) كلُّ صنفٍ من المسارات يقع في نطاقه المتوقَّع (تمثيلٌ صريحٌ لكلّ نوع) */
    public function test_each_route_category_lands_in_its_expected_scope(): void
    {
        $this->seedCore();
        $ia = $this->ia();

        $cases = [
            // [route, params, expected scope, expected container-key => value]
            ['m.index',      ['module' => 'tasks'],     'domain',     'domain',  'work'],           // index
            ['m.show',       ['module' => 'tasks'],     'domain',     'domain',  'work'],           // show
            ['m.create',     ['module' => 'tasks'],     'domain',     'domain',  'work'],           // create
            ['m.edit',       ['module' => 'tasks'],     'domain',     'domain',  'work'],           // edit
            ['m.board',      ['module' => 'tasks'],     'domain',     'domain',  'work'],           // board
            ['m.export',     ['module' => 'tasks'],     'domain',     'domain',  'work'],           // export
            ['m.import',     ['module' => 'tasks'],     'domain',     'domain',  'work'],           // import
            ['workspace',    ['key' => 'entities'],     'domain',     'domain',  'entities'],       // workspace page
            ['tech.workspace', [],                      'domain',     'domain',  'digital'],        // C5: مساحةٌ متخصّصة
            ['boards.index', [],                        'domain',     'domain',  'work'],           // center (مستقلّ)
            ['support',      [],                        'domain',     'domain',  'work'],           // center (كتالوج)
            ['settings.edit', [],                       'domain',     'domain',  'administration'], // admin
            ['file.show',    [],                        'bucket',     'bucket',  'utility'],        // utility (صريح)
            ['att.dl',       [],                        'bucket',     'bucket',  'utility'],        // utility (بادئة)
            ['login',        [],                        'bucket',     'bucket',  'public'],         // public
            ['up',           [],                        'bucket',     'bucket',  'system'],         // system
            ['mobile.bootstrap', [],                    'bucket',     'bucket',  'api'],            // api (بادئة)
        ];

        foreach ($cases as [$name, $params, $scope, $ckey, $cval]) {
            $loc = $ia->routeLocation($name, $params);
            $this->assertSame($scope, $loc['scope'] ?? null, "نطاقُ {$name} غيرُ متوقَّع");
            $this->assertSame($cval, $loc[$ckey] ?? null, "حاويةُ {$name} غيرُ متوقَّعة");
        }
    }

    /** ٣) المسارُ العامُّ m.* لوحدةٍ حيّةٍ يقصد بيتها؛ ولوحدةٍ مؤرشفةٍ يبقى يُحَلّ (صفر فقدان) */
    public function test_generic_module_route_and_deprecated_route_both_resolve(): void
    {
        $this->seedCore();
        $ia = $this->ia();

        // وحدةٌ يتيمةٌ أُسنِدت (stations) — m.index يقصد بيتها في التقنية
        $live = $ia->routeLocation('m.index', ['module' => 'stations']);
        $this->assertSame('domain', $live['scope']);
        $this->assertSame('digital', $live['domain']);

        // وحدةٌ مؤرشفةٌ (autos) — لا بيتَ تنقّل، لكنّ المسارَ يُحَلُّ إلى نطاقٍ مؤرشفٍ مصنَّف
        $dep = $ia->routeLocation('m.index', ['module' => 'autos']);
        $this->assertSame('deprecated', $dep['scope']);
        $this->assertSame('autos', $dep['module']);
        $this->assertSame('DEPRECATED_CONFIRMED', $dep['status'] ?? null);

        // مسارٌ فارغ/مجهول لا يرمي — يقع في دلوٍ مُسمّى
        $unknown = $ia->routeLocation('this.route.does.not.exist');
        $this->assertSame('bucket', $unknown['scope']);
    }
}
