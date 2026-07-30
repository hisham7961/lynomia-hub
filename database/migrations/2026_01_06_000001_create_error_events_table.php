<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** مركز الأخطاء: كل استثناء/خطأ JS/طلب بطيء — مجمّعاً بالتكرار وبحالة معالجة */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('error_events', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->string('hash', 64)->unique();          // بصمة التجميع (نوع+رسالة+ملف+سطر)
            $t->string('kind', 12)->index();           // php | js | api | slow
            $t->string('message', 500);
            $t->string('file', 300)->nullable();
            $t->unsignedInteger('line')->nullable();
            $t->string('url', 400)->nullable();
            $t->string('method', 10)->nullable();
            $t->uuid('user_id')->nullable()->index();
            $t->string('request_id', 40)->nullable();
            $t->text('trace')->nullable();             // للمالك فقط في الواجهة
            $t->unsignedInteger('count')->default(1);
            $t->string('status', 20)->default('جديد')->index();   // جديد | قيد المعالجة | محلول
            $t->timestamp('first_seen');
            $t->timestamp('last_seen')->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('error_events');
    }
};
