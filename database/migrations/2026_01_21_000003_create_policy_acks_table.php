<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** إقرارات السياسات — سطر واحد لكل (سياسة، موظف، نسخة)؛ تحديث نسخة السياسة يُبطل الإقرار القديم */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('policy_acks', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->string('title', 300);                           // وصف مختصر: «اسم السياسة — اسم الموظف»
            $t->uuid('policy_id')->nullable()->index();         // السياسة المُقرّة
            $t->uuid('user_id')->nullable()->index();           // الموظف المُقِر
            $t->string('ver', 40)->nullable()->index();         // النسخة التي أقرّ بها فعلياً
            $t->dateTime('ack_at')->nullable()->index();        // وقت الإقرار — دليل الامتثال
            $t->string('ip', 60)->nullable();                   // عنوان IP وقت الإقرار
            $t->string('device', 200)->nullable();              // الجهاز/المتصفح وقت الإقرار
            $t->string('status', 60)->nullable()->index();
            $t->text('notes')->nullable();
            $t->json('custom')->nullable();
            $t->json('meta')->nullable();
            $t->integer('version')->default(1);
            $t->boolean('archived')->default(false)->index();
            $t->uuid('created_by')->nullable()->index();
            $t->uuid('updated_by')->nullable();
            $t->timestamps();
            $t->softDeletes();
        });

        // سطر واحد منطقياً لكل (سياسة، موظف، نسخة) — فهرس مركّب يمنع التكرار عملياً ويخدم الاستعلام
        DB::statement('CREATE INDEX policy_acks_pol_user_ver_idx ON policy_acks (policy_id, user_id, ver)');
        DB::statement('CREATE INDEX policy_acks_user_status_idx ON policy_acks (user_id, status)');
        DB::statement('CREATE INDEX policy_acks_status_ack_idx ON policy_acks (status, ack_at)');
    }

    public function down(): void
    {
        Schema::dropIfExists('policy_acks');
    }
};
