<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** المهارات والشهادات — سجل مهارة واحدة لموظف واحد مع فجوتها عن المستوى المطلوب وشهادتها وتاريخ انتهائها */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('skills', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->string('name', 200);                            // اسم المهارة
            $t->uuid('emp_id')->nullable()->index();            // الموظف صاحب المهارة
            $t->string('cat', 60)->nullable()->index();         // تصنيف المهارة
            $t->string('level', 40)->nullable()->index();       // المستوى الحالي المُقيَّم
            $t->string('req_level', 40)->nullable();            // المستوى الذي يتطلبه الدور الوظيفي
            $t->string('gap', 40)->nullable()->index();         // الفجوة بين الحالي والمطلوب
            $t->string('cert', 300)->nullable();                // اسم الشهادة أو رقمها
            $t->string('cert_body', 200)->nullable();           // الجهة المانحة للشهادة
            $t->date('cert_date')->nullable();                  // تاريخ إصدار الشهادة
            $t->date('cert_exp')->nullable()->index();          // انتهاء الشهادة — يغذّي تنبيهات التجديد
            $t->text('train_plan')->nullable();                 // خطة التدريب لسد الفجوة
            $t->date('review_at')->nullable()->index();         // موعد إعادة تقييم المستوى
            $t->uuid('att_id')->nullable();                     // مرفق الشهادة في attachments
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

        DB::statement('CREATE INDEX skills_emp_cat_idx ON skills (emp_id, cat)');
        DB::statement('CREATE INDEX skills_exp_status_idx ON skills (cert_exp, status)');
        DB::statement('CREATE INDEX skills_gap_emp_idx ON skills (gap, emp_id)');
    }

    public function down(): void
    {
        Schema::dropIfExists('skills');
    }
};
