<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * استرجاع الرسائل العالقة كان يقيس من **إنشاء** الرسالة لا من **حجزها**:
 * رسالة أُنشئت قبل ربع ساعة وبدأ تشغيلٌ إرسالها الآن، يخطفها التشغيل التالي
 * فوراً ويرسلها مرة ثانية. الختم الصحيح هو لحظة الحجز.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('webhook_deliveries', fn (Blueprint $t) => $t->dateTime('claimed_at')->nullable());
        Schema::table('outbox', fn (Blueprint $t) => $t->dateTime('claimed_at')->nullable());
    }

    public function down(): void
    {
        Schema::table('webhook_deliveries', fn (Blueprint $t) => $t->dropColumn('claimed_at'));
        Schema::table('outbox', fn (Blueprint $t) => $t->dropColumn('claimed_at'));
    }
};
