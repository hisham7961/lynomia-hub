<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * (الطور F · WP-F.1 · §26) **تاريخُ إسناد المحطة** — صفٌّ لكلِّ حركةِ مقعدٍ لا
 * يُعاد كتابتُه، على نمط `asset_custody` تماماً (الأثرُ الذي لا يحمله عمودُ
 * `stations.current_employee_id` وحدَه).
 *
 * `current_employee_id` يقول من يجلس **الآن** وحسب؛ فإذا انتقل المقعدُ ضاع من
 * جلس قبله ومتى أُخلي — وهو ما يُسأل عنه عند تدقيقِ عهدةٍ أو مغادرةِ موظف. هذا
 * الجدولُ الأثر: `assign`/`vacate` بتاريخه ومن نفّذه، **يبقى بعد مغادرة الموظف**
 * (حتى لو حُذف حسابُه ناعماً) فلا يُعاد تفسيرُ التاريخ بأثرٍ رجعيّ.
 *
 * يُكتَب عبر مسارِ الإسناد/الإخلاء المقفل وحدَه (`StationController` على نمط
 * `Custody::move` DB::transaction) — لا CRUD عامٌّ عليه (ليس وحدةَ `hub.modules`).
 *
 * **allowlist في النموذج لا DB enum (C10):** `action` نصٌّ (١٢) تفرض قيمتَيه
 * `StationAssignment::ACTIONS` في `saving`.
 *
 * **ترتيبٌ حتميٌّ (C13):** `INDEX(station_id, at, id)` — القراءةُ تُرتَّب
 * `orderBy(at)->orderBy(id)` فلا قرعةَ عند تساوي الأزمنة.
 *
 * إضافيّةٌ محروسة (add-if-not-exists)، كتلةٌ حرفيّةٌ واحدة.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('station_assignments')) {
            Schema::create('station_assignments', function (Blueprint $t) {
                $t->uuid('id')->primary();
                $t->uuid('station_id');
                // من جلس/أُخلي — nullable: الإخلاءُ قد يُسجَّل بلا شخصٍ محدَّد
                $t->uuid('user_id')->nullable();
                // عمودُ الشركةِ من المحطة — لعزلِ القراءةِ والنسخِ الخام
                $t->uuid('company_id')->nullable();
                // assign|vacate — allowlist في النموذج (لا DB enum · C10)
                $t->string('action', 12);
                $t->timestamp('at')->nullable();             // زمنُ الحركة الفعليّ
                $t->string('note', 500)->nullable();
                $t->uuid('by_id')->nullable();               // مَن نفّذها
                $t->timestamps();
                $t->softDeletes();

                // ترتيبٌ حتميٌّ لقراءةِ تاريخِ المحطة (C13)
                $t->index(['station_id', 'at', 'id'], 'sta_station_at_id');
                $t->index('user_id', 'sta_user');
                $t->index('company_id', 'sta_company');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('station_assignments');
    }
};
