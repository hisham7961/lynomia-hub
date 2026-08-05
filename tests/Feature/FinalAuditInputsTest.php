<?php

namespace Tests\Feature;

use App\Models\InboundHook;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * الفحص النهائيّ (بند ٨) — تصليبُ المدخلات على صرامة MySQL:
 *  (٧) حدُّ العمود العشريّ كان مشتقّاً من float عبر number_format فيسمح بـ10^13
 *      على decimal(16,3) — يمرّ على SQLite ويرفضه MySQL بـ22003.
 *  (٨) قصُّ حمولة الويبهوك إلى 65536 بايت يفيض عمود TEXT (أقصاه 65535) ببايتٍ.
 */
class FinalAuditInputsTest extends TestCase
{
    public function test_decimal_column_limit_is_exact_string_not_float_rounded(): void
    {
        // decimal(16,3): ١٣ خانةً صحيحة و٣ عشرية → الحدُّ الدقيق نصّاً
        $this->assertSame('9999999999999.999', hub_col_num_max('bank_accounts', 'balance'),
            'حدُّ decimal(16,3) يجب أن يكون نصّاً دقيقاً لا floatًا مقرَّباً يسمح بالفيض');
    }

    public function test_inbound_hook_payload_never_exceeds_text_column(): void
    {
        $this->seedCore();
        $h = InboundHook::create(['name' => 'استقبال', 'token' => Str::random(48),
            'secret' => Str::random(64), 'event' => 'x', 'enabled' => true, 'created_by' => $this->owner->id]);

        $body = str_repeat('x', 70000);   // أكبرُ من حدّ TEXT (65535)
        $sig = 'sha256=' . hash_hmac('sha256', $body, $h->secret);
        $this->call('POST', route('hook.receive', $h->token), [], [], [],
            ['CONTENT_TYPE' => 'application/json', 'HTTP_X_HUB_SIGNATURE' => $sig], $body)->assertOk();

        $stored = (string) DB::table('inbound_hook_events')->where('hook_id', $h->id)->value('payload');
        $this->assertLessThanOrEqual(65535, strlen($stored), 'الحمولةُ المخزَّنة تفيض عمود TEXT');
    }
}
