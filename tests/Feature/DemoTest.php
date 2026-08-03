<?php

namespace Tests\Feature;

use App\Models\Client;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class DemoTest extends TestCase
{
    public function test_demo_seeds_tagged_data_and_purge_removes_only_it(): void
    {
        $this->seedCore();
        $real = Client::create(['name' => 'عميل حقيقي']);

        Artisan::call('hub:demo');
        /*
         * **بمسار JSON لا بمطابقة نصّ**: MySQL 8 يحفظ JSON بنوعٍ أصليّ فيُعيد
         * صياغته `{"demo": 1}` بمسافة — فلا يطابق `%"demo":1%`. والتطبيق نفسه
         * يستعمل `meta->demo` المحايد (HubDemo::purge)، فالاختبارُ وحده كان
         * يتكلّم لهجةً لا يفهمها المحرّك الذي يعمل عليه النظام.
         */
        $this->assertTrue((bool) DB::table('clients')->where('meta->demo', 1)->count());
        $this->assertTrue((bool) DB::table('projects')->where('meta->demo', 1)->count());
        $this->assertTrue((bool) DB::table('fin_documents')->where('meta->demo', 1)->count());
        $this->assertDatabaseHas('settings', ['key' => 'demo.on']);

        Artisan::call('hub:demo', ['--purge' => true]);
        $this->assertSame(0, DB::table('clients')->where('meta->demo', 1)->count());
        $this->assertSame(0, DB::table('tasks')->where('meta->demo', 1)->count());
        $this->assertNotNull(Client::find($real->id));                 // الحقيقي سليم
        $this->assertDatabaseMissing('settings', ['key' => 'demo.on']);
    }

    public function test_reset_route_owner_only_and_banner_shows(): void
    {
        $this->seedCore();
        $this->actingAs($this->employee)->post('/admin/demo/reset')->assertForbidden();

        $this->actingAs($this->owner)->from('/admin/ops')->post('/admin/demo/reset')->assertRedirect();
        \Illuminate\Support\Facades\Cache::forget('settings:all');
        $this->actingAs($this->owner)->get('/')->assertSee('وضع تجريبي');

        $this->actingAs($this->owner)->post('/admin/demo/off')->assertRedirect();
        \Illuminate\Support\Facades\Cache::forget('settings:all');
        $this->actingAs($this->owner)->get('/')->assertDontSee('وضع تجريبي — البيانات');
    }
}
