<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * الويبهوك الوارد — نقاطُ استقبالٍ أصلية: نظامٌ خارجيّ (n8n، نموذج، خدمة) يستدعي
 * رابطاً موقّعاً فيُخزَّن ما أرسله حدثاً، ويُحصى، ويظهر في مركز التكامل. أصليّ في
 * PHP بلا خدمةٍ خارجية — يعمل على أي استضافة، وهو الجسر الذي يُدخِل البيانات للنظام.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inbound_hooks', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->string('name', 190);
            $t->string('token', 64)->unique();           // في الرابط: /hook/{token}
            $t->string('secret', 128)->nullable();        // توقيع HMAC — فارغٌ = بلا توقيع
            $t->string('event', 120)->nullable();         // اسمُ حدثٍ اختياريّ يُسنَد للحمولة
            $t->boolean('enabled')->default(true);
            $t->unsignedInteger('hits')->default(0);
            $t->timestamp('last_hit_at')->nullable();
            $t->uuid('company_id')->nullable()->index();  // نطاقٌ اختياريّ
            $t->uuid('created_by')->index();
            $t->timestamps();
        });

        Schema::create('inbound_hook_events', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->uuid('hook_id')->index();
            $t->text('payload')->nullable();              // JSON الوارد (مسقوفٌ حجماً)
            $t->string('ip', 60)->nullable();
            $t->unsignedSmallInteger('status')->default(200);
            $t->timestamp('created_at')->nullable()->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inbound_hook_events');
        Schema::dropIfExists('inbound_hooks');
    }
};
