<?php

namespace Tests\Feature;

use App\Models\Flow;
use App\Models\HubNotification;
use App\Models\Task;
use App\Models\VaultSecret;
use App\Support\FlowRunner;
use Tests\TestCase;

/**
 * أمن قوالب المسارات — عيوب الموجة الثانية:
 * (١) tpl() كانت تستبدل {_display}/{_by} أولاً ثم تمرّر الناتج كله على حلّال
 *     رموز الحقول — فسجلٌّ اسمه «{secret}» يُحلّ رمزُه المحقون إلى قيمة الحقل
 *     الفعلية ويخرج في إشعار/بريد: تسريبٌ لا يحتاج صلاحية مالك؛
 * (٢) رموز الحقول كانت تحلّ حقول sec خامّةً رغم أن بقية النظام يحجبها؛
 * (٣) شرطٌ على حقلٍ لم يعد موجوداً كان يمرّ مفتوحاً فيطلق المسار على كل شيء؛
 * (٤) أعطال الإجراءات تُبتلع بلا report() وعدّاد التشغيل يرتفع ولو فشل الكل.
 */
class FlowTemplateSecurityTest extends TestCase
{
    protected function vaultFlow(string $notifyText): Flow
    {
        return Flow::create([
            'name' => 'مسار الخزنة', 'module' => 'vault', 'event' => 'updated',
            'actions' => [['type' => 'notify', 'to' => 'owners', 'text' => $notifyText]],
            'enabled' => true, 'created_by' => $this->owner->id,
        ]);
    }

    /** اسمُ سجلٍ يحمل رمز قالبٍ لا يُحلّ إلى قيمة الحقل — لا حقن ثنائي التمرير */
    public function test_injected_token_in_display_value_is_not_resolved(): void
    {
        $this->seedCore();
        $this->actingAs($this->owner);
        $this->vaultFlow('تحديث: {_display}');

        $v = VaultSecret::create(['title' => '{secret}', 'type' => 'خادم',
            'secret_cipher' => 'TOP-SECRET-XYZ']);
        FlowRunner::run('updated', 'vault', $v);

        $texts = HubNotification::where('kind', 'flow')->pluck('text')->implode(' | ');
        $this->assertStringNotContainsString('TOP-SECRET-XYZ', $texts,
            'قيمة العرض المحقونة «{secret}» حُلّت في التمريرة الثانية إلى السرّ الخام');
        $this->assertStringContainsString('{secret}', $texts,
            'الاسم الحرفي للسجل يجب أن يظهر كما هو');
    }

    /** رمز حقل sec في نص الإجراء لا يُحلّ — السرّ لا يغادر الخزنة قالباً */
    public function test_sec_field_tokens_stay_literal(): void
    {
        $this->seedCore();
        $this->actingAs($this->owner);
        $this->vaultFlow('السر: {secret} للمستخدم {username}');

        $v = VaultSecret::create(['title' => 'سر الخادم', 'type' => 'خادم',
            'username' => 'admin', 'secret_cipher' => 'TOP-SECRET-XYZ']);
        FlowRunner::run('updated', 'vault', $v);

        $texts = HubNotification::where('kind', 'flow')->pluck('text')->implode(' | ');
        $this->assertStringNotContainsString('TOP-SECRET-XYZ', $texts,
            'رمز {secret} حلّ قيمة الحقل السري خامّةً في إشعار داخلي');
        $this->assertStringContainsString('admin', $texts,
            'الحقول العادية تُحلّ كما كانت');
    }

    /** شرطٌ على حقلٍ غير معروف لا يمرّ مفتوحاً */
    public function test_condition_on_unknown_field_fails_closed(): void
    {
        $this->seedCore();
        $this->actingAs($this->owner);
        Flow::create([
            'name' => 'مسار بشرط ميت', 'module' => 'tasks', 'event' => 'updated',
            'cond_field' => 'field_that_was_renamed', 'cond_op' => 'eq', 'cond_value' => 'x',
            'actions' => [['type' => 'notify', 'to' => 'owners', 'text' => 'يجب ألا أصل']],
            'enabled' => true, 'created_by' => $this->owner->id,
        ]);

        $t = Task::create(['title' => 'مهمة', 'status' => 'جديدة']);
        FlowRunner::run('updated', 'tasks', $t);

        $this->assertSame(0, HubNotification::where('kind', 'flow')->count(),
            'شرطٌ على حقلٍ اختفى من التعريف مرّ مفتوحاً فأطلق المسار بلا شرط');
    }

    /** بناء المسار يرفض شرطاً أو حقل تعيينٍ من غير وحدة المسار */
    public function test_flow_form_rejects_foreign_condition_and_set_fields(): void
    {
        $this->seedCore();

        // حقل شرطٍ من وحدة أخرى (salary ليس من tasks)
        $this->actingAs($this->owner)->post('/admin/flows', [
            'name' => 'مسار', 'm' => 'tasks', 'event' => 'updated',
            'cond_field' => 'salary', 'cond_op' => 'eq', 'cond_value' => '1',
            'a_notify' => 1, 'a_notify_to' => 'owners', 'a_notify_text' => 'x',
        ])->assertSessionHasErrors('cond_field');

        // حقل تعيينٍ غريب يُرفض كذلك
        $this->actingAs($this->owner)->post('/admin/flows', [
            'name' => 'مسار', 'm' => 'tasks', 'event' => 'updated',
            'a_set' => 1, 'a_set_field' => 'iban', 'a_set_value' => 'x',
        ])->assertSessionHasErrors('a_set_field');

        $this->assertSame(0, Flow::count());
    }

    /** أعطال الإجراءات تُبلَّغ ولا يرتفع العدّاد إلا بنجاحٍ فعلي — حارس مصدر */
    public function test_action_failures_are_reported_not_swallowed(): void
    {
        $src = file_get_contents(app_path('Support/FlowRunner.php'));
        $this->assertStringContainsString('report($e)', $src,
            'أعطال إجراءات المسارات تُبتلع بلا أي تسجيل — مسارٌ مكسور لا يكتشفه أحد');
        $this->assertMatchesRegularExpression('/if\s*\(\s*\$ok/u', $src,
            'عدّاد التشغيل يرتفع ولو فشلت كل الإجراءات — «آخر تشغيل: قبل دقيقة» والمخرج صفر');
    }
}
