<?php

namespace Tests\Feature;

use App\Models\HubNotification;
use App\Models\OutboxMessage;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class DigestTest extends TestCase
{
    public function test_digest_composes_report_and_queues_for_owners(): void
    {
        $this->seedCore();
        // إشارة حرجة: خدمة تبيع بخسارة
        DB::table('services')->insert(['id' => (string) Str::uuid(), 'name' => 'خدمة خاسرة',
            'price' => 5, 'cycle' => 'شهري', 'cost' => 40, 'status' => 'نشطة',
            'created_at' => now(), 'updated_at' => now()]);

        $this->assertSame(0, Artisan::call('hub:digest'));

        // إشعار داخلي + رسالة صادرة للمالك، ولا شيء لغير المالكين
        $this->assertSame(1, HubNotification::where('kind', 'digest')->where('user_id', $this->owner->id)->count());
        $this->assertSame(1, OutboxMessage::where('kind', 'digest')->count());
        $this->assertSame(0, HubNotification::where('kind', 'digest')->where('user_id', $this->employee->id)->count());

        $text = HubNotification::where('kind', 'digest')->value('text');
        $this->assertStringContainsString('تقريرك التنفيذي', $text);
        $this->assertStringContainsString('خدمة خاسرة', $text);
    }

    public function test_dry_run_writes_nothing(): void
    {
        $this->seedCore();
        $this->assertSame(0, Artisan::call('hub:digest', ['--dry' => true]));
        $this->assertSame(0, HubNotification::where('kind', 'digest')->count());
        $this->assertSame(0, OutboxMessage::where('kind', 'digest')->count());
        $this->assertStringContainsString('تقريرك التنفيذي', Artisan::output());
    }

    public function test_no_owner_no_report(): void
    {
        $this->seedCore();
        $this->owner->update(['status' => 'موقوف']);
        // لا مالك نشط
        $this->assertSame(0, Artisan::call('hub:digest'));
        $this->assertSame(0, HubNotification::where('kind', 'digest')->count());
    }
}
