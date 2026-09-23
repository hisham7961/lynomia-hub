<?php

namespace Tests\Feature;

use App\Models\InboundHook;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * **WEBHOOK-1/2 (تدقيق أمنيّ v2.600) — منعُ الإعادةِ لا يتّكئ على ترويسةِ العميل.**
 *
 * كان منعُ إعادةِ الويبهوكِ الموقَّع يعمل فقط إن أرسل المصدرُ `X-Hub-Event-Id`
 * (يتحكّم به المُعيدُ فيغيّره) وفقط إن أُشعلت رافعةُ الطابع الزمنيّ (مطفأةٌ
 * افتراضاً) — فالطلبُ الملتقَطُ يُعاد بلا حدٍّ من سطحٍ عامّ. الآن مفتاحُ التفرّد
 * يُشتقّ خادميّاً من التوقيع (`sha256`) الذي لا يُزوَّر بلا السرّ — فالإعادةُ
 * الحرفيّةُ (توقيعٌ مطابق) تُرفَض دائماً بلا اعتمادٍ على ترويسةٍ يملكها المُعيد.
 * CWE-294.
 */
class InboundHookReplayRejectedTest extends TestCase
{
    public function test_a_signed_request_replayed_verbatim_is_rejected_as_duplicate(): void
    {
        $this->seedCore();
        $secret = 'whsec_'.Str::random(24);
        $hook = InboundHook::create([
            'name' => 'نقطة', 'token' => Str::random(20), 'secret' => $secret,
            'event' => 'push', 'enabled' => true, 'created_by' => $this->owner->id,
        ]);

        $body = '{"id":123,"kind":"order.created"}';
        $sig = 'sha256='.hash_hmac('sha256', $body, $secret);
        $headers = ['X-Hub-Signature' => $sig, 'CONTENT_TYPE' => 'application/json'];

        // الطلبُ الأوّل — جديد
        $first = $this->call('POST', '/hook/'.$hook->token, [], [], [], $this->server($headers), $body);
        $first->assertOk();
        $this->assertNotTrue($first->json('duplicate'), 'الطلبُ الأوّل يجب ألا يكون تكراراً');

        // نفسُ البايتاتِ ونفسُ التوقيع — إعادةٌ حرفيّة، بلا event-id ولا طابعٍ زمنيّ
        $replay = $this->call('POST', '/hook/'.$hook->token, [], [], [], $this->server($headers), $body);
        $replay->assertOk();
        $this->assertTrue($replay->json('duplicate'),
            'الإعادةُ الحرفيّةُ للطلبِ الموقَّع يجب أن تُرفَض تكراراً — مفتاحُ التوقيع الخادميّ');
    }

    /** ترويساتٌ بصيغة الخادم ($_SERVER) لطلبِ call() */
    private function server(array $headers): array
    {
        $out = [];
        foreach ($headers as $k => $v) {
            $out[str_starts_with($k, 'CONTENT_') ? $k : 'HTTP_'.strtoupper(str_replace('-', '_', $k))] = $v;
        }

        return $out;
    }
}
