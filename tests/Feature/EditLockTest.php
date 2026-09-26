<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Company;
use App\Models\RecordLock;
use Tests\TestCase;

/**
 * **قفلُ التحريرِ اللّيّن** — `record_locks` يجد مستهلكَه (بندُ الدَّين #5 · DI-09).
 *
 * أخذٌ عند فتحِ نموذجِ التعديل · تنبيهٌ للثاني (لا منع) · انتهاءٌ بعد عشرِ دقائق ·
 * تحريرٌ عند الحفظ والإلغاء والحذف · واسمُ الحاملِ لا يتسرّب عبر عزلِ الشركات.
 */
class EditLockTest extends TestCase
{
    private function lockOf(Client $c): ?RecordLock
    {
        return RecordLock::where('module', 'clients')->where('record_id', $c->id)->orderBy('id')->first();
    }

    public function test_opening_edit_acquires_and_a_second_editor_is_warned_not_blocked(): void
    {
        $this->seedCore();
        $c = Client::create(['name' => 'عميلُ القفل']);

        $html = $this->actingAs($this->employee)->get('/m/clients/' . $c->id . '/edit')->assertOk()->getContent();
        $this->assertStringNotContainsString('data-edit-lock', $html, 'الحاملُ نفسُه لا يُنبَّه على قفلِه');

        $lock = $this->lockOf($c);
        $this->assertNotNull($lock, 'فتحُ التعديل لم يأخذ قفلاً');
        $this->assertSame((string) $this->employee->id, (string) $lock->user_id);
        $this->assertTrue($lock->expires_at->isAfter(now()->addMinutes(RecordLock::TTL_MINUTES - 1)));

        // المحرّرُ الثاني يفتح النموذجَ (لا منع) ويرى الاسم
        $html = $this->actingAs($this->owner)->get('/m/clients/' . $c->id . '/edit')->assertOk()->getContent();
        $this->assertStringContainsString('data-edit-lock', $html);
        $this->assertStringContainsString('يحرّره الآن موظفة', $html);
        $this->assertSame((string) $this->employee->id, (string) $this->lockOf($c)->user_id, 'القفلُ السّاري انتُزع من صاحبه');

        // وحفظُ الثاني يعمل — التنبيهُ لا يمنع، و`_version` هو الحارس
        $this->actingAs($this->owner)->put('/m/clients/' . $c->id, ['name' => 'عميلُ القفل ٢', '_version' => $c->fresh()->version])
            ->assertRedirect(route('m.index', 'clients'));
        $this->assertSame('عميلُ القفل ٢', $c->fresh()->name);
        $this->assertSame((string) $this->employee->id, (string) $this->lockOf($c)->user_id, 'حفظُ غيرِه حرّر قفلَ الحامل');
    }

    public function test_a_stale_lock_expires_and_is_taken_by_the_next_opener(): void
    {
        $this->seedCore();
        $c = Client::create(['name' => 'عميلٌ منتهي القفل']);

        $this->actingAs($this->employee)->get('/m/clients/' . $c->id . '/edit')->assertOk();
        $this->travel(RecordLock::TTL_MINUTES + 1)->minutes();

        $html = $this->actingAs($this->owner)->get('/m/clients/' . $c->id . '/edit')->assertOk()->getContent();
        $this->assertStringNotContainsString('data-edit-lock', $html, 'قفلٌ منتهٍ ما زال يُنبِّه');
        $this->assertSame((string) $this->owner->id, (string) $this->lockOf($c)->user_id, 'المنتهي لم يُؤخَذ');
        $this->assertSame(1, RecordLock::where('module', 'clients')->where('record_id', $c->id)->count());
    }

    public function test_reopening_refreshes_own_lock_and_keeps_since(): void
    {
        $this->seedCore();
        $c = Client::create(['name' => 'عميلُ التجديد']);

        $this->actingAs($this->employee)->get('/m/clients/' . $c->id . '/edit');
        $born = $this->lockOf($c)->created_at->timestamp;
        $this->travel(8)->minutes();
        $this->actingAs($this->employee)->get('/m/clients/' . $c->id . '/edit');

        $lock = $this->lockOf($c);
        $this->assertTrue($lock->expires_at->isAfter(now()->addMinutes(RecordLock::TTL_MINUTES - 1)), 'لم يتجدّد العمر');
        $this->assertSame($born, $lock->created_at->timestamp, '«منذ» تحرّك مع التجديد');

        $html = $this->actingAs($this->owner)->get('/m/clients/' . $c->id . '/edit')->getContent();
        $this->assertStringContainsString('منذ 8 دقائق', $html);
    }

    public function test_save_cancel_and_delete_release_only_the_own_lock(): void
    {
        $this->seedCore();
        $c = Client::create(['name' => 'عميلُ التحرير']);

        // الحفظ
        $this->actingAs($this->employee)->get('/m/clients/' . $c->id . '/edit');
        $this->actingAs($this->employee)->put('/m/clients/' . $c->id, ['name' => 'عميلُ التحرير', '_version' => $c->fresh()->version]);
        $this->assertNull($this->lockOf($c), 'الحفظُ لم يُحرِّر القفل');

        // الإلغاء: رابطُه في النموذج يحمل `_unlock`، ولا يُحرِّر قفلَ غيرِك
        $html = $this->actingAs($this->employee)->get('/m/clients/' . $c->id . '/edit')->getContent();
        $this->assertStringContainsString('_unlock=' . $c->id, $html);
        $this->actingAs($this->owner)->get('/m/clients?_unlock=' . $c->id)->assertRedirect(route('m.index', 'clients'));
        $this->assertNotNull($this->lockOf($c), '«إلغاء» غيرِ الحامل حرّر قفلَه');
        $this->actingAs($this->employee)->get('/m/clients?_unlock=' . $c->id)->assertRedirect(route('m.index', 'clients'));
        $this->assertNull($this->lockOf($c), 'الإلغاءُ لم يُحرِّر القفل');

        // الحذف
        $this->actingAs($this->owner)->get('/m/clients/' . $c->id . '/edit');
        $this->actingAs($this->owner)->delete('/m/clients/' . $c->id);
        $this->assertNull($this->lockOf($c), 'الحذفُ لم يُحرِّر القفل');
    }

    public function test_holder_name_does_not_leak_across_isolated_companies(): void
    {
        $this->seedCore();
        $a = Company::create(['name_ar' => 'شركة أ']);
        $b = Company::create(['name_ar' => 'شركة ب']);
        $c = Client::create(['name' => 'عميلٌ مشترك']);

        $this->viewer->forceFill(['companies' => [(string) $a->id]])->save();
        $this->employee->forceFill(['companies' => [(string) $b->id]])->save();
        RecordLock::acquire('clients', (string) $c->id, $this->employee->fresh());

        $msg = $this->lockOf($c)->warningFor($this->viewer->fresh());
        $this->assertStringContainsString('يحرّره الآن مستخدمٌ آخر', $msg);
        $this->assertStringNotContainsString('موظفة', $msg, 'اسمُ حاملٍ من شركةٍ أخرى تسرّب');

        // ومن يشاركه شركةً يرى الاسم
        $this->viewer->forceFill(['companies' => [(string) $a->id, (string) $b->id]])->save();
        $this->assertStringContainsString('موظفة', $this->lockOf($c)->warningFor($this->viewer->fresh()));
    }
}
