<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** التعليقات والمنشن — على أي سجل من أي وحدة، أو منشور عام في قناة الفريق (record_id فارغ) */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('comments', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->string('module', 60);                       // مفتاح الوحدة أو 'feed' للقناة العامة
            $t->uuid('record_id')->nullable();
            $t->uuid('parent_id')->nullable()->index();     // رد على تعليق
            $t->uuid('user_id')->index();
            $t->text('body');
            $t->string('att', 500)->nullable();             // مسار مرفق
            $t->boolean('pinned')->default(false);
            $t->json('mentions')->nullable();               // معرفات من ذُكروا
            $t->json('read_by')->nullable();                // معرفات من قرأ
            $t->uuid('task_id')->nullable();                // إن حُوّل التعليق لمهمة
            $t->timestamp('created_at')->index();
            $t->timestamp('updated_at')->nullable();
            $t->softDeletes();

            $t->index(['module', 'record_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('comments');
    }
};
