<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** إدارة الحوادث التقنية — انقطاع أو تدهور فعلي في الإنتاج (غير تذاكر العملاء) */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('incidents', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->string('title', 300);
            $t->string('severity', 40)->nullable()->index();      // مستوى الحادث: حرج/عالي/متوسط/منخفض
            $t->dateTime('started_at')->nullable()->index();      // وقت بداية الحادث
            $t->dateTime('resolved_at')->nullable()->index();     // وقت استعادة الخدمة
            $t->unsignedInteger('downtime_min')->nullable();      // مدة التعطل بالدقائق (يُدخلها المستخدم)
            $t->string('affected', 400)->nullable();              // الأنظمة والخدمات المتأثرة
            $t->uuid('app_id')->nullable()->index();
            $t->uuid('server_id')->nullable()->index();
            $t->uuid('project_id')->nullable()->index();
            $t->text('clients_hit')->nullable();                  // العملاء المتأثرون وحجم الأثر عليهم
            $t->uuid('lead_id')->nullable()->index();             // قائد الحادث (Incident Commander)
            $t->json('parts')->nullable();                        // المشاركون في المعالجة (عدة مستخدمين)
            $t->text('steps')->nullable();                        // خطوات المعالجة بالترتيب الزمني
            $t->text('root_cause')->nullable();                   // السبب الجذري النهائي
            $t->text('lessons')->nullable();                      // الدروس المستفادة
            $t->text('prevention')->nullable();                   // الإجراءات الوقائية لمنع التكرار
            $t->boolean('postmortem')->default(false)->index();   // هل كُتب تقرير Postmortem
            $t->date('review_date')->nullable()->index();         // تاريخ مراجعة تنفيذ الإجراءات الوقائية
            $t->string('status', 60)->nullable()->index();
            $t->uuid('att_id')->nullable();
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

        DB::statement('CREATE INDEX incidents_status_sev_idx ON incidents (status, severity)');
        DB::statement('CREATE INDEX incidents_started_desc_idx ON incidents (started_at DESC)');
        DB::statement('CREATE INDEX incidents_proj_created_idx ON incidents (project_id, created_at DESC)');
    }

    public function down(): void
    {
        Schema::dropIfExists('incidents');
    }
};
