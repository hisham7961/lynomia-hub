<?php

namespace Tests\Feature;

use App\Models\ApiToken;
use App\Models\PhoneNumber;
use Tests\TestCase;

/**
 * **SEC-1 (تدقيق أمنيّ v2.600) — حقلٌ يعلن step-up لا يُكشَف عبر الـAPI بلا تصعيد.**
 *
 * حقلُ PUK في وحدة `phones` يعلن `'stepup' => true` لأنّه **يفكّ قفلَ الشريحة
 * نهائياً**. الويب يفرض التصعيدَ لكشفه: `ModuleController::revealSecret` يفحص
 * `!empty($f['stepup'])` ويستدعي `hub_require_stepup()` — حتّى للمالك. لكنّ
 * مُشكِّلَ الـAPI `V1Controller::shape()` كان يُسقط الحقولَ السرّية بناءً على
 * `hub_copy_secrets` + `allowed_ids` **وحدَها، ولا يقرأ راية `stepup` قطّ** —
 * فحاملُ رمزٍ بصلاحيّة نسخِ الأسرار (والمالكُ منهم) يستردّ PUK خاماً عبر الـAPI
 * دون أيِّ تصعيد. وسطحُ التكامل لا يملك آليّةَ تصعيدٍ تفاعليّةً أصلاً — فالحقلُ
 * المُعلَنُ «يتطلّب تصعيداً دائماً» يُسقَط على هذا المسار. CWE-306/CWE-620.
 *
 * وPIN (سرٌّ بلا stepup) يبقى ظاهراً لحاملِ صلاحيّةِ الأسرار — فالتضييقُ محصورٌ
 * بحقولِ `stepup` وحدَها، لا كسرٌ لعقدِ كشفِ الأسرار العاديّ.
 */
class PukStepUpNotBypassedViaApiTest extends TestCase
{
    public function test_puk_is_not_returned_over_the_api_even_to_an_owner_token(): void
    {
        $this->seedCore();
        $phone = PhoneNumber::create([
            'number' => '96550000001', 'country' => 'KW', 'carrier' => 'STC',
            'pin' => '778812', 'puk' => '99887766', 'status' => 'نشط',
        ]);

        ApiToken::create(['name' => 'tk', 'user_id' => $this->owner->id,
            'token_hash' => hash('sha256', 'tk_phones_1'), 'scopes' => 'phones:v', 'created_at' => now()]);

        $res = $this->getJson('/api/v1/phones/' . $phone->id, ['Authorization' => 'Bearer tk_phones_1'])
            ->assertOk();

        // PIN (سرٌّ عاديّ) يظهر لحاملِ صلاحيّة الأسرار — العقدُ لم يُكسَر
        $this->assertSame('778812', $res->json('data.pin'), 'PIN يجب أن يبقى ظاهراً لحاملِ صلاحيّةِ الأسرار');
        // PUK (سرٌّ بـstepup) لا يُكشَف عبر الـAPI — لا آليّةَ تصعيدٍ على سطح التكامل
        $this->assertArrayNotHasKey('puk', (array) $res->json('data'),
            'PUK لا يُكشَف عبر الـAPI بلا تصعيدٍ طازج — نظيرُ ما يفرضه الويب في revealSecret');
        $this->assertMaskedValueAbsent('99887766', $res->getContent(), 'قيمةُ PUK تسرّبت في ردّ الـAPI');
    }
}
