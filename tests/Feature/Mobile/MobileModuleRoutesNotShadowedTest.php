<?php

namespace Tests\Feature\Mobile;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * **لا مسارَ جوالٍ حرفيٌّ يخطف وحدة سجلّ** (مراجعة v2.618.0): `GET files` و`DELETE files/{id}` لقائمة
 * مرفقات السجلّ سُجّلا قبل المحرّك العامّ — و`files` مفتاحُ وحدةٍ قائمة (الوثائق) — فصار عرضُ قائمة
 * الوثائق ٤٢٢ وحذفُ وثيقةٍ يبحث في جدول المرفقات. هذا الحارس يمرّ على **كلِّ** مفتاح وحدة وكلِّ فعلٍ عامّ.
 */
class MobileModuleRoutesNotShadowedTest extends TestCase
{
    public function test_every_module_key_still_reaches_the_generic_crud_routes(): void
    {
        $routes = Route::getRoutes();
        $cases = [
            ['GET', '', 'mobile.resource.index'],
            ['POST', '', 'mobile.resource.store'],
            ['GET', '/rid-1', 'mobile.resource.show'],
            ['PUT', '/rid-1', 'mobile.resource.update'],
            ['PATCH', '/rid-1', 'mobile.resource.patch'],
            ['DELETE', '/rid-1', 'mobile.resource.destroy'],
            ['GET', '/rid-1/actions', 'mobile.resource.actions'],
        ];
        // استثناءٌ قائمٌ ومُعلَن منذ الطور الأوّل: طابورُ الموافقات المخصَّص (المعلَّق للمعتمِد) يحلّ محلّ
        // قراءتَي المحرّك العامّ للوحدة `approvals`، والكتابةُ تسقط للعامّ — عقدٌ موثَّق لا خطف
        $declared = ['GET approvals' => 'mobile.approvals.index', 'GET approvals/rid-1' => 'mobile.approvals.show'];
        $bad = [];
        foreach (array_keys((array) config('hub.modules')) as $key) {
            if ($key === 'users') continue;   // مستثنًى من سطح الجوال عمداً (404 في resolveApi)
            foreach ($cases as [$method, $suffix, $want]) {
                $req = Request::create('/api/mobile/v1/' . $key . $suffix, $method);
                try {
                    $name = $routes->match($req)->getName();
                } catch (\Throwable $e) {
                    $name = 'NONE';
                }
                if (($declared["$method $key$suffix"] ?? null) === $name) continue;
                if ($name !== $want) $bad[] = "$method $key$suffix ⇒ $name (المتوقَّع $want)";
            }
        }

        $this->assertSame([], $bad, "مسارٌ حرفيٌّ يخطف وحدةَ سجلّ:\n" . implode("\n", $bad));
    }
}
