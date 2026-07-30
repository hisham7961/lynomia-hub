<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** الطلبات الواردة — أي طلب داخلي يمر بالتقييم ثم الاعتماد ثم يتحول لمشروع أو مهمة */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('internal_requests', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->string('title', 300);
            $t->string('req_type', 60)->nullable()->index();      // نوع الطلب
            $t->uuid('requester_id')->nullable()->index();        // مقدّم الطلب
            $t->string('dept', 120)->nullable()->index();         // القسم الطالب
            $t->text('description')->nullable();                  // الوصف التفصيلي
            $t->text('justification')->nullable();                // المبرر وأثر عدم التنفيذ
            $t->string('prio_req', 40)->nullable()->index();      // الأولوية كما اقترحها مقدّم الطلب
            $t->string('prio_final', 40)->nullable()->index();    // الأولوية بعد الاعتماد
            $t->decimal('est_cost', 16, 3)->nullable();           // التكلفة التقديرية
            $t->decimal('est_days', 16, 3)->nullable();           // الجهد التقديري بالأيام
            $t->uuid('reviewer_id')->nullable()->index();         // المقيّم
            $t->text('review_notes')->nullable();                 // ملاحظات التقييم
            $t->string('decision', 40)->nullable()->index();      // القرار النهائي
            $t->text('reject_reason')->nullable();                // سبب الرفض أو التأجيل
            $t->uuid('project_id')->nullable()->index();          // المشروع الناتج عن الطلب
            $t->uuid('task_id')->nullable()->index();             // المهمة الناتجة عن الطلب
            $t->date('req_date')->nullable()->index();            // تاريخ رفع الطلب
            $t->date('need_by')->nullable()->index();             // موعد الحاجة
            $t->string('status', 60)->nullable()->index();
            $t->uuid('att_id')->nullable();
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

        DB::statement('CREATE INDEX ireq_status_prio_idx ON internal_requests (status, prio_final)');
        DB::statement('CREATE INDEX ireq_requester_created_idx ON internal_requests (requester_id, created_at DESC)');
        DB::statement('CREATE INDEX ireq_status_need_idx ON internal_requests (status, need_by)');
    }

    public function down(): void
    {
        Schema::dropIfExists('internal_requests');
    }
};
