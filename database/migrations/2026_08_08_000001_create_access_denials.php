<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * رادارُ الكشف الحيّ: سجلُّ محاولاتِ الوصول المرفوضة (٤٠٣) وتخمينِ الروابط العامّة.
 * جدولٌ خفيفٌ منفصلٌ عن سلسلة التدقيق — كتابةٌ سريعةٌ بلا ختمٍ متسلسل تحت هجوم.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('access_denials', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->string('kind', 40)->index();               // وصول مرفوض | تخمين رابط
            $t->uuid('user_id')->nullable()->index();      // null = زائرٌ غير مستخدم
            $t->string('ip', 60)->nullable()->index();
            $t->string('method', 10)->nullable();
            $t->string('path', 300)->nullable();
            $t->string('detail', 300)->nullable();
            $t->timestamp('created_at')->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('access_denials');
    }
};
