<?php

namespace Tests\Feature;

use App\Models\Setting;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * التوليد الشامل `hub:demo --full`: كل وحدة من السجل تنال بيانات تجريبية موسومة،
 * بمراجع صحيحة تربطها، والإعدادات الفارغة فقط تُملأ — والتصفير يمسح كل ذلك بدقة
 * ولا يمسّ سجلاً حقيقياً ولا إعداداً كان قائماً.
 */
class DemoFullTest extends TestCase
{
    /** كل وحدة في السجل (لها جدول ووسم meta) نالت صفاً تجريبياً واحداً على الأقل */
    public function test_full_seed_covers_every_module(): void
    {
        $this->seedCore();
        Artisan::call('hub:demo', ['--full' => true]);

        $missed = [];
        foreach (config('hub.modules') as $mk => $md) {
            $t = $md['table'] ?? null;
            if (! $t || $t === 'users' || ! Schema::hasTable($t) || ! Schema::hasColumn($t, 'meta')) continue;
            if (DB::table($t)->where('meta->demo', 1)->count() === 0) $missed[] = $mk;
        }

        $this->assertSame([], $missed, 'وحدات بلا بيانات تجريبية: ' . implode('، ', $missed));
    }

    /** المراجع محلولة لا معلّقة: مرجعُ صفٍّ تجريبي يشير لسجل موجود فعلاً */
    public function test_full_seed_resolves_refs_to_real_rows(): void
    {
        $this->seedCore();
        Artisan::call('hub:demo', ['--full' => true]);

        $checked = 0;
        foreach (config('hub.modules') as $mk => $md) {
            $t = $md['table'] ?? null;
            if (! $t || $t === 'users' || ! Schema::hasTable($t)) continue;
            foreach ($md['fields'] ?? [] as $f) {
                if (($f['type'] ?? '') !== 'ref' || ! empty($f['multi'])) continue;
                $ref = $f['ref'] ?? null;
                $refTable = $ref === 'users' ? 'users' : config("hub.modules.{$ref}.table");
                if (! $refTable || ! Schema::hasColumn($t, $f['col'])) continue;

                $val = DB::table($t)->where('meta->demo', 1)->whereNotNull($f['col'])->value($f['col']);
                if ($val === null) continue;
                $this->assertNotNull(DB::table($refTable)->where('id', $val)->first(),
                    "مرجع معلّق: {$mk}.{$f['col']} → {$ref} ({$val})");
                $checked++;
            }
        }

        $this->assertGreaterThan(20, $checked, 'فُحصت مراجع أقل من المتوقع — التوليد لم يملأ المراجع');
    }

    /** الإعدادات: الفارغ يُملأ ويُسجَّل، والقائم لا يُمسّ — والتصفير يعيد الحال */
    public function test_settings_fill_only_empty_and_purge_restores(): void
    {
        $this->seedCore();
        Setting::updateOrCreate(['key' => 'app.company'], ['value' => 'شركتي الحقيقية']);
        Cache::forget('settings:all');

        Artisan::call('hub:demo', ['--full' => true]);
        Cache::forget('settings:all');

        $this->assertSame('شركتي الحقيقية', setting('app.company'), 'إعدادٌ قائم كُتب فوقه');
        $this->assertSame('د.ك', setting('app.currency'), 'إعدادٌ فارغ لم يُملأ');
        $filled = json_decode((string) Setting::where('key', 'demo.settings')->value('value'), true);
        $this->assertContains('app.currency', $filled);
        $this->assertNotContains('app.company', $filled);
        // الحسّاسات لا تُملأ أبداً: الصيانة لا تُفعَّل ولا تُخترع أسرار
        $this->assertSame('', (string) setting('maintenance.on', ''));
        $this->assertSame('', (string) setting('notify.tg_token', ''));

        Artisan::call('hub:demo', ['--purge' => true]);
        Cache::forget('settings:all');

        $this->assertSame('', (string) setting('app.currency', ''), 'التصفير لم يحذف إعداداً ملأه هو');
        $this->assertSame('شركتي الحقيقية', setting('app.company'), 'التصفير حذف إعداداً حقيقياً');
        $this->assertDatabaseMissing('settings', ['key' => 'demo.settings']);
    }

    /** التصفير بعد --full يمسح كل الصفوف الموسومة في كل الجداول ويترك الحقيقي */
    public function test_purge_after_full_removes_everything_tagged(): void
    {
        $this->seedCore();
        $real = \App\Models\Client::create(['name' => 'عميل حقيقي باقٍ']);

        Artisan::call('hub:demo', ['--full' => true]);
        Artisan::call('hub:demo', ['--purge' => true]);

        foreach (config('hub.modules') as $mk => $md) {
            $t = $md['table'] ?? null;
            if (! $t || $t === 'users' || ! Schema::hasTable($t) || ! Schema::hasColumn($t, 'meta')) continue;
            $this->assertSame(0, DB::table($t)->where('meta->demo', 1)->count(),
                "بقايا تجريبية في {$mk}");
        }
        $this->assertNotNull(\App\Models\Client::find($real->id));
    }
}
