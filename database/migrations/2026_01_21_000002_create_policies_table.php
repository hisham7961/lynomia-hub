<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** السياسات والإقرارات — نص السياسة ونسختها؛ كل إقرار موظف مربوط بنسخة محددة في policy_acks */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('policies', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->string('title', 300);
            $t->string('cat', 80)->nullable()->index();         // تصنيف السياسة
            $t->string('ver', 40)->nullable()->index();         // رقم النسخة — الإقرارات مرتبطة بها
            $t->text('body')->nullable();                       // النص الكامل للسياسة
            $t->uuid('owner_id')->nullable()->index();          // مالك السياسة المسؤول عن تحديثها
            $t->date('eff_date')->nullable()->index();          // تاريخ سريان النسخة الحالية
            $t->date('review_date')->nullable()->index();       // المراجعة القادمة — يغذّي تنبيهات المراجعة
            $t->boolean('ack_required')->default(false)->index(); // هل الإقرار إلزامي على الموظفين
            $t->string('roles', 400)->nullable();               // الأدوار/الأقسام المشمولة بالسياسة
            $t->uuid('att_id')->nullable();                     // مرفق (PDF موقّع مثلاً)
            $t->string('status', 60)->nullable()->index();
            $t->text('notes')->nullable();
            $t->json('tags')->nullable();
            $t->json('custom')->nullable();
            $t->json('meta')->nullable();
            $t->integer('version')->default(1);
            $t->boolean('archived')->default(false)->index();
            $t->uuid('created_by')->nullable()->index();
            $t->uuid('updated_by')->nullable();
            $t->timestamps();
            $t->softDeletes();
        });

        DB::statement('CREATE INDEX policies_status_cat_idx ON policies (status, cat)');
        DB::statement('CREATE INDEX policies_review_status_idx ON policies (review_date, status)');
        DB::statement('CREATE INDEX policies_ack_status_idx ON policies (ack_required, status)');
    }

    public function down(): void
    {
        Schema::dropIfExists('policies');
    }
};
