<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * **دفعة سلامة البيانات من فحص العشرين وكيلاً** — أعطالٌ يخفيها SQLite
 * ويُظهرها MySQL، وأعطالُ مقارناتٍ نوعية، وميزةٌ معطلة عبر ثلاث طبقات.
 */
class AuditRemediationDataTest extends TestCase
{
    /**
     * أولُ عمود TIMESTAMP في MySQL (مع explicit_defaults_for_timestamp مطفأً)
     * يُمنح ضمنياً ON UPDATE CURRENT_TIMESTAMP — فتحديثُ قيمة نقطة قياس
     * «يدهس» زمنَ القياس نفسَه، وأثمنُ ما في النقطة لحظتُها.
     * (الداءُ نفسه أُصلح لـerror_events في 2026_01_12 — وبقي هذان.)
     */
    public function test_metric_point_time_survives_value_update(): void
    {
        $this->seedCore();
        $past = now()->subDays(10)->startOfSecond();

        DB::table('metric_points')->insert([
            'id' => (string) \Illuminate\Support\Str::uuid(),
            'module' => 'social', 'record_id' => (string) \Illuminate\Support\Str::uuid(),
            'metric' => 'followers', 'value' => 100, 'at' => $past,
            'source' => 'manual', 'created_at' => now(), 'updated_at' => now(),
        ]);

        // إعادةُ دفع النقطة نفسها تُحدِّث القيمة — كما يفعل metricsIngest تماماً
        DB::table('metric_points')->update(['value' => 200, 'updated_at' => now()]);

        $at = (string) DB::table('metric_points')->value('at');
        $this->assertSame($past->format('Y-m-d H:i:s'), substr($at, 0, 19),
            'زمنُ القياس دُهس عند تحديث القيمة — عمود TIMESTAMP أول بسلوك MySQL الضمني');
    }

    /** الداء نفسه على sessions_log.started_at: النبضة تجعل بدءَ الجلسة آخرَ نشاط */
    public function test_session_start_survives_activity_heartbeat(): void
    {
        $this->seedCore();
        $start = now()->subHours(3)->startOfSecond();

        DB::table('sessions_log')->insert([
            'id' => (string) \Illuminate\Support\Str::uuid(),
            'user_id' => $this->owner->id, 'device' => 'اختبار',
            'ip' => '1.2.3.4', 'user_agent' => 'اختبار', 'started_at' => $start,
            'last_seen_at' => $start, 'revoked' => false,
        ]);

        // نبضة النشاط تحدّث آخرَ ظهور — يجب ألا تسحب بدءَ الجلسة معها
        DB::table('sessions_log')->update(['last_seen_at' => now()]);

        $at = (string) DB::table('sessions_log')->value('started_at');
        $this->assertSame($start->format('Y-m-d H:i:s'), substr($at, 0, 19),
            'بدءُ الجلسة صار آخرَ نشاط — شاشات الأمان تعرض أوقاتاً خاطئة');
    }

    /**
     * الانتهاء التلقائي للعقود كان معطلاً عملياً: `=== '1'` النوعية تفشل على
     * ما يكتبه `hub:set` (يخزّن العدد 1 فيُقرأ int لا string).
     */
    public function test_contract_auto_expire_works_when_enabled_via_hub_set(): void
    {
        $this->seedCore();
        // كما يفعل hub:set حرفياً: القيمة الرقمية تُخزَّن عدداً
        \App\Models\Setting::updateOrCreate(['key' => 'contracts.auto_expire'], ['value' => 1]);
        \Illuminate\Support\Facades\Cache::forget('settings:all');

        $c = \App\Models\Contract::create(['title' => 'عقد منتهٍ', 'type' => 'عقد عميل',
            'status' => 'ساري', 'date_end' => now()->subDay()->toDateString()]);

        \Illuminate\Support\Facades\Artisan::call('hub:automation');

        $this->assertSame('منتهي', (string) $c->fresh()->status,
            'العقد المنتهي بقي «سارياً» — مقارنةُ === النوعية تفشل على قيمة hub:set');
    }

    /** حقل رفع ملف الكود: النوع big على عمود uuid عطّل الميزة عبر ثلاث طبقات */
    public function test_code_module_file_field_is_a_real_file_field(): void
    {
        $def = hub_mod('code');
        $f = collect($def['fields'])->firstWhere('col', 'file_id');
        $this->assertNotNull($f, 'حقل file_id غاب عن وحدة code');
        $this->assertSame('file', $f['type'],
            'النوع «' . $f['type'] . '» على عمود uuid مرفقات: العرض يرسم مدخلاً رقمياً والتحقق يفرض numeric والتخزين يكتب رقماً في uuid — الميزة معطلة عبر الطبقات الثلاث');
    }
}
