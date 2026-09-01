<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * عروض المشاريع — توسيعُ وحدة `quotes` القائمة (لا نظامٌ ثانٍ).
 *
 * تُضاف حقولٌ للعرض الاحترافي (عنوان، نطاق، ربحيةٌ داخلية مخفيّة، ربطُ ارتباط)،
 * وجدولا **بنودٍ مهيكلة** (`quote_lines`) و**جدول مدفوعات** (`quote_milestones`)
 * بدل بنود النصّ الحر — فالإجمالياتُ تُحسَب خادمياً لا تُكتَب باليد. وكلُّها
 * إضافيةٌ محضة: الأعمدةُ nullable والقائمُ يعمل كما هو.
 *
 * down() فارغة عمداً — لا هجرة مدمّرة.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('quotes')) {
            Schema::table('quotes', function (Blueprint $t) {
                foreach ([
                    'title' => fn () => $t->string('title', 300)->nullable(),
                    'engagement_id' => fn () => $t->uuid('engagement_id')->nullable()->index(),
                    'contact_id' => fn () => $t->uuid('contact_id')->nullable()->index(),
                    'am_id' => fn () => $t->uuid('am_id')->nullable()->index(),   // مدير الحساب
                    'pm_id' => fn () => $t->uuid('pm_id')->nullable()->index(),   // مدير التنفيذ
                    'billing' => fn () => $t->string('billing', 60)->nullable(),  // نموذج الفوترة → يُنقل للارتباط
                    'discount' => fn () => $t->decimal('discount', 16, 3)->nullable(),
                    'cost' => fn () => $t->decimal('cost', 16, 3)->nullable(),    // تكلفة تقديرية داخلية — تُخفى عن العميل
                    'exec_summary' => fn () => $t->text('exec_summary')->nullable(),
                    'objective' => fn () => $t->text('objective')->nullable(),
                    'scope' => fn () => $t->text('scope')->nullable(),           // نطاق يُشارك العميل
                    'assumptions' => fn () => $t->text('assumptions')->nullable(),
                    'exclusions' => fn () => $t->text('exclusions')->nullable(),
                    'accepted_at' => fn () => $t->timestamp('accepted_at')->nullable(),
                    'accepted_by' => fn () => $t->string('accepted_by', 200)->nullable(),
                    'sent_at' => fn () => $t->timestamp('sent_at')->nullable(),
                ] as $col => $add) {
                    if (! Schema::hasColumn('quotes', $col)) $add();
                }
            });
        }

        if (! Schema::hasTable('quote_lines')) {
            Schema::create('quote_lines', function (Blueprint $t) {
                $t->uuid('id')->primary();
                $t->uuid('quote_id')->index();
                $t->string('kind', 60)->nullable();            // خدمة/مرحلة/تسليم/رسوم ثابتة/بالساعة…
                $t->uuid('service_id')->nullable()->index();   // ref services (اختياري)
                $t->uuid('product_id')->nullable()->index();   // ref products (اختياري)
                $t->string('phase', 200)->nullable();          // تجميعٌ في مرحلة
                $t->string('title', 300);
                $t->text('description')->nullable();
                $t->decimal('qty', 16, 3)->default(1);
                $t->string('unit', 60)->nullable();
                $t->decimal('unit_price', 16, 3)->default(0);
                $t->decimal('discount_pct', 8, 3)->default(0);
                $t->decimal('tax_pct', 8, 3)->default(0);
                $t->decimal('line_total', 16, 3)->default(0);  // محسوبٌ خادمياً
                $t->decimal('unit_cost', 16, 3)->nullable();   // تكلفة داخلية — تُخفى عن العميل
                $t->unsignedInteger('sort')->default(0);
                $t->json('meta')->nullable();
                $t->timestamps();
                $t->softDeletes();
                $t->index(['quote_id', 'sort']);
            });
        }

        if (! Schema::hasTable('quote_milestones')) {
            Schema::create('quote_milestones', function (Blueprint $t) {
                $t->uuid('id')->primary();
                $t->uuid('quote_id')->index();
                $t->string('title', 300);
                $t->decimal('pct', 8, 3)->nullable();          // نسبة من الإجمالي
                $t->decimal('amount', 16, 3)->nullable();      // أو مبلغٌ صريح
                $t->string('trigger', 200)->nullable();        // عند القبول/بعد المرحلة٢/التسليم النهائي
                $t->string('phase', 200)->nullable();
                $t->string('due_note', 200)->nullable();
                $t->unsignedInteger('sort')->default(0);
                $t->json('meta')->nullable();
                $t->timestamps();
                $t->softDeletes();
                $t->index(['quote_id', 'sort']);
            });
        }
    }

    public function down(): void
    {
        // لا هجرة مدمّرة
    }
};
