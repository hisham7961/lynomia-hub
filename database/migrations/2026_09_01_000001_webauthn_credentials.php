<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * مفاتيحُ المرور (Passkeys / WebAuthn) — مصادقةٌ قويّةٌ حقيقية بلا كلمة سر.
 *
 * لا مكتبةَ خارجية: التحقّقُ من التوقيع بـ`openssl` (ES256/P-256)، والتحليلُ
 * البنيويّ (CBOR/COSE) بمحلّلٍ مُصغَّرٍ مُختبَر. `attestation:none` — لا قرارَ
 * ثقةٍ بصانع المفتاح (المعيارُ لمفاتيح المنشأة الداخلية) فتنتفي أخطرُ خطوة.
 *
 * كلُّ صفٍّ = مفتاحُ مرورٍ واحدٌ لمستخدم: مُعرِّفُه، ومفتاحُه العامّ (PEM)،
 * وعدّادُ توقيعٍ لكشف الاستنساخ. الخاصُّ لا يغادر جهازَ المستخدم أبداً.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('webauthn_credentials')) {
            Schema::create('webauthn_credentials', function (Blueprint $t) {
                $t->uuid('id')->primary();
                $t->uuid('user_id')->index();
                $t->string('credential_id', 512)->unique();   // base64url لمُعرِّف المفتاح
                $t->text('public_key');                        // PEM (SPKI) للمفتاح العامّ
                $t->unsignedBigInteger('sign_count')->default(0);   // كشفُ الاستنساخ
                $t->string('label', 160)->nullable();          // وسمٌ يقرأه الإنسان
                $t->string('transports', 120)->nullable();     // usb/nfc/ble/internal
                $t->timestamp('last_used_at')->nullable();
                $t->timestamps();
                $t->softDeletes();
            });
        }
    }

    public function down(): void
    {
        // لا هجرة مدمّرة
    }
};
