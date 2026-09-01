<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * CPQ المرحلة ج — **أوامر التغيير** (Change Orders).
 *
 * تغييرٌ تجاريٌّ بعد بدء المشروع يُمدّد **خطَّ الأساس** (نطاقاً/قيمةً/زمناً) بعد
 * اعتماده — **بلا مسّ العرض المقبول** (تاريخُه محفوظ). كلُّ أمرٍ يشير لمشروعه
 * وعميله وعرضه المصدر، ويحمل أثرَ القيمة (`value_delta`) والتكلفة الداخلية
 * (`cost_delta`) والزمن (`timeline_days`). عند التطبيق يُضاف للمشروع فتتطوّر
 * قيمتُه التعاقدية — والأصلُ يبقى في `project.meta.baseline` كما هو.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('change_orders')) {
            Schema::create('change_orders', function (Blueprint $t) {
                $t->uuid('id')->primary();
                $t->string('doc_no', 60)->unique();
                $t->uuid('project_id')->nullable()->index();
                $t->uuid('client_id')->nullable()->index();
                $t->uuid('engagement_id')->nullable()->index();
                $t->uuid('quote_id')->nullable()->index();      // العرضُ المصدر (مرجعٌ لا نسخ)
                $t->string('title', 300);
                $t->text('description')->nullable();            // النطاقُ المُضاف
                $t->string('reason', 300)->nullable();
                $t->decimal('value_delta', 16, 3)->default(0);  // تغيّرُ القيمة التعاقدية (±)
                $t->decimal('cost_delta', 16, 3)->nullable();   // أثرُ التكلفة الداخلية (يُخفى)
                $t->integer('timeline_days')->default(0);       // أثرُ الجدول الزمنيّ (±)
                $t->string('currency', 20)->nullable();
                $t->string('status', 40)->default('مسودة');
                $t->timestamp('approved_at')->nullable();
                $t->string('approved_by', 200)->nullable();
                $t->timestamp('applied_at')->nullable();
                $t->uuid('owner_id')->nullable()->index();
                $t->uuid('company_id')->nullable()->index();
                $t->json('meta')->nullable();
                $t->unsignedInteger('version')->default(1);   // HasVersions
                $t->uuid('created_by')->nullable();           // Auditable/HasVersions
                $t->json('custom')->nullable();
                $t->timestamps();
                $t->softDeletes();
            });
        }
    }

    public function down(): void
    {
        // لا هجرة مدمّرة
    }
};
