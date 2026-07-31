<?php

namespace Tests\Feature;

use App\Models\Comment;
use App\Models\Ticket;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * المرحلة ٥ — تحصين SLA: أعقد منطقٍ مخصص في مجموعته وكان **بلا أي تغطية
 * اختبارية**، ومقياس «أول رد» متفائلاً بنيوياً لأن أي تعليق — حتى ملاحظة
 * داخلية بين موظفَين — كان يوقف عدّاد الاستجابة دون أن يصل العميل شيء.
 */
class SlaHardeningRound6Test extends TestCase
{
    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    protected function ticket(string $subject, string $prio = 'عالية', ?Carbon $at = null): Ticket
    {
        $t = Ticket::create(['subject' => $subject, 'priority' => $prio, 'status' => 'جديدة']);
        if ($at) $t->forceFill(['created_at' => $at])->saveQuietly();

        return $t->fresh();
    }

    public function test_internal_note_does_not_stop_the_response_clock(): void
    {
        $this->seedCore();
        // «عالية» = استجابة خلال ٤ ساعات (القاعدة الافتراضية)
        $t = $this->ticket('تذكرة الملاحظة', 'عالية', now()->subHours(6));

        Comment::create(['module' => 'tickets', 'record_id' => $t->id, 'user_id' => $this->owner->id,
            'body' => 'ملاحظة بين الفريق: راجعوا سجل الخادم', 'internal' => true,
            'created_at' => now()->subHours(5)]);

        $sla = hub_sla($t->fresh());
        $this->assertTrue($sla['respPending'], 'الملاحظة الداخلية ليست رداً — العدّاد ما زال يعمل');
        $this->assertTrue($sla['respLate'], 'ومضى وقت الاستجابة فعلاً — المؤشر يقول الحقيقة');

        // ردٌّ عام يوقفه
        Comment::create(['module' => 'tickets', 'record_id' => $t->id, 'user_id' => $this->owner->id,
            'body' => 'رد على العميل: جارٍ العمل', 'internal' => false,
            'created_at' => now()->subHours(1)]);

        $sla2 = hub_sla($t->fresh());
        $this->assertFalse($sla2['respPending'], 'الرد العام هو الذي يوقف العدّاد');
        $this->assertNotNull($sla2['respAt']);
    }

    public function test_priority_policy_matching_and_resolution_stamp(): void
    {
        $this->seedCore();
        $urgent = $this->ticket('عاجلة جداً', 'عاجلة');
        $this->assertStringContainsString('عاجلة', hub_sla($urgent)['policy'], 'الأولوية تختار سياستها');

        $unknown = $this->ticket('بأولوية غريبة', 'س بلا سياسة');
        $this->assertStringContainsString('افتراضي', hub_sla($unknown)['policy'], 'وما لا سياسة له يقع على الافتراضي');

        // الإغلاق يختم زمن الحل، وإعادة الفتح تمسحه
        $t = $this->ticket('تذكرة الإغلاق');
        $this->actingAs($this->owner)->post('/m/tickets/' . $t->id . '/status', ['status' => 'تم الحل']);
        $this->assertNotNull(hub_sla($t->fresh())['resAt'], 'الإغلاق يختم زمن الحل');

        $this->actingAs($this->owner)->post('/m/tickets/' . $t->id . '/status', ['status' => 'قيد المعالجة']);
        $this->assertNull(hub_sla($t->fresh())['resAt'], 'وإعادة الفتح تمسح الختم');
    }

    public function test_support_board_metrics_ignore_internal_notes(): void
    {
        $this->seedCore();
        $t = $this->ticket('تذكرة اللوحة', 'عالية', now()->subHours(10));
        Comment::create(['module' => 'tickets', 'record_id' => $t->id, 'user_id' => $this->owner->id,
            'body' => 'داخلية فقط', 'internal' => true, 'created_at' => now()->subHours(9)]);

        // اللوحة تعدّها متجاوزة استجابةً لأن العميل لم يصله رد
        $this->actingAs($this->owner)->get('/support')
            ->assertOk()->assertSee('تذكرة اللوحة');

        $late = collect(hub_sla($t->fresh()));
        $this->assertTrue($late['respPending'] && $late['respLate']);
    }

    public function test_comment_form_marks_internal_only_for_tickets(): void
    {
        $this->seedCore();
        $t = $this->ticket('تذكرة النموذج');

        $this->actingAs($this->owner)->post('/comments', [
            'module' => 'tickets', 'record_id' => $t->id,
            'body' => 'ملاحظة سرية', 'internal' => 1,
        ]);
        $this->assertTrue((bool) Comment::where('body', 'ملاحظة سرية')->first()->internal);

        // وحدة أخرى: العلم يُتجاهل (لا معنى لملاحظة داخلية خارج التذاكر)
        $client = \App\Models\Client::create(['name' => 'عميل التعليق', 'stage' => 'عميل حالي']);
        $this->actingAs($this->owner)->post('/comments', [
            'module' => 'clients', 'record_id' => $client->id,
            'body' => 'تعليق عادي', 'internal' => 1,
        ]);
        $this->assertFalse((bool) Comment::where('body', 'تعليق عادي')->first()->internal);
    }
}
