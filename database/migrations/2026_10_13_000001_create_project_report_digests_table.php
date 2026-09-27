<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * **ملخّصُ تقارير المشروع بالذكاء** (`App\Support\Ai\Reports\ProjectReportDigest`).
 *
 * صفٌّ واحدٌ لكلِّ مشروع يُحدَّث تزايديّاً: الملخّصُ المُهيكَل (`sections`) ومؤشّرُ ما شمله
 * (`covered_until` + `last_report_id` — مفتاحُ ترتيبٍ مركّبٌ لا `created_at` وحدَها، فدقّةُ الثانية
 * تتساوى) والنسخةُ السابقة (`prev_sections`) وخريطةُ رموز الأعضاء (`members` — الاسمُ لا يغادر الخادم:
 * النموذجُ يرى `[P1]` والعرضُ يستبدلها). والإخفاقُ يُسجَّل حالةً ورمزاً **ولا يمسّ الملخّصَ السابق**.
 *
 * إضافيّةٌ فقط (CLAUDE.md) — لا تمسّ جدولاً قائماً.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('project_report_digests')) return;

        Schema::create('project_report_digests', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('project_id');
            $t->uuid('company_id')->nullable()->index();
            $t->json('sections')->nullable();                    // {overview, achievements, blockers, risks, next, team}
            $t->json('prev_sections')->nullable();               // النسخةُ السابقة — تاريخٌ بخطوةٍ واحدة
            $t->json('members')->nullable();                     // {"P1": user_id, ...} — رموزٌ ثابتةٌ عبر الجولات
            $t->timestamp('covered_until')->nullable();          // created_at لأحدث تقريرٍ شمله الملخّص
            $t->uuid('last_report_id')->nullable();              // كاسرُ التعادل في الثانية نفسِها
            $t->unsignedInteger('reports_count')->default(0);    // تراكميّ
            $t->string('model', 190)->nullable();                // النموذجُ الذي خدم فعلاً
            $t->string('status', 10)->default('ok');             // ok | failed | stale
            $t->string('error_code', 40)->nullable();
            $t->uuid('usage_event_id')->nullable();              // آخرُ نداءٍ ناجح — لربط الملخّص بكلفته
            $t->timestamp('generated_at')->nullable();           // آخرُ توليدٍ ناجح
            $t->timestamp('attempted_at')->nullable();           // آخرُ محاولة (ناجحةً أو لا)
            $t->timestamps();

            $t->unique('project_id', 'project_report_digests_project_uq');
            $t->index(['status', 'generated_at'], 'project_report_digests_status_idx');
        });
    }

    public function down(): void
    {
        // إضافيّةٌ فقط (CLAUDE.md) — لا هدمَ في الرجوع
    }
};
