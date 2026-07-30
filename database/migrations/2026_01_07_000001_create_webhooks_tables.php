<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // الاشتراكات: وجهة + سر توقيع + قائمة أحداث
        Schema::create('webhooks', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->string('name', 120);
            $t->string('url', 500);
            $t->string('secret', 100);
            $t->text('events');                          // «*» أو قائمة مثل: projects.created، tickets.*
            $t->boolean('active')->default(true);
            $t->unsignedInteger('fail_streak')->default(0);
            $t->dateTime('paused_until')->nullable();    // تعطيل مؤقت بعد فشل متكرر
            $t->unsignedInteger('runs')->default(0);
            $t->dateTime('last_at')->nullable();
            $t->boolean('last_ok')->nullable();
            $t->timestamps();
        });

        // سجل المحاولات — هو نفسه صف التسليم (queued → sent/failed مع إعادات مجدولة)
        Schema::create('webhook_deliveries', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('webhook_id')->index();
            $t->string('event', 80);                     // projects.created …
            $t->uuid('event_id');                        // يُرسل كترويسة ليتمكن المستقبل من منع التكرار
            $t->mediumText('payload');
            $t->string('state', 10)->default('queued');  // queued|sent|failed
            $t->unsignedTinyInteger('tries')->default(0);
            $t->dateTime('next_at')->nullable()->index();
            $t->unsignedSmallInteger('code')->nullable();
            $t->unsignedInteger('ms')->nullable();
            $t->string('error', 400)->nullable();
            $t->dateTime('created_at');
            $t->dateTime('delivered_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('webhook_deliveries');
        Schema::dropIfExists('webhooks');
    }
};
