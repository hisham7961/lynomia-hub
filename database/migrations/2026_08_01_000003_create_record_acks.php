<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * سكّة الإقرار الموحّدة — «قرأتُ ووافقت» بدليلٍ لا بذاكرة.
 *
 * كان المحضر يُكتب ويُغلق دون أن يوافق عليه من حضر؛ فإذا اختُلف بعد شهرٍ في
 * قرارٍ قيل «لم يُتّفق على هذا». والعهدة تُسلَّم بجدولٍ فيه اسمُ الحائز، ولا
 * توقيعَ منه: فإذا ضاع الجهاز فلا إثبات تسليم. والقرار يُسنَد لمنفّذٍ لم يقل
 * يوماً إنه علم به.
 *
 * المفتاح `(module, record_id)` نفسه الذي تمشي عليه المرفقات والتعليقات
 * والتدقيق — فتحصل أي وحدةٍ على إقرارٍ موثَّق بلا جدولٍ جديد لكلٍّ منها.
 * ومعه دليلُه: الوقت والعنوان والجهاز — كإثبات التوقيع الإلكتروني تماماً.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('record_acks', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->string('module', 60)->index();
            $t->uuid('record_id')->index();
            $t->uuid('user_id')->index();
            // نسخة السجل وقت الإقرار: محضرٌ عُدِّل بعد اعتماده يحتاج اعتماداً جديداً
            $t->integer('ver')->default(1);
            $t->timestamp('ack_at');
            $t->string('ip', 60)->nullable();
            $t->string('device', 200)->nullable();
            $t->text('note')->nullable();          // تحفّظٌ مكتوب مع الإقرار
            $t->timestamp('created_at')->nullable();

            $t->unique(['module', 'record_id', 'user_id', 'ver']);
            $t->index(['module', 'record_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('record_acks');
    }
};
