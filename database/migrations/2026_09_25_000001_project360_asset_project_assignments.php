<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * (Project 360 · §15) **تاريخُ تخصيصِ الأصلِ لمشروع** — علاقةٌ زمنيّةٌ متعدّدةٌ إلى متعدّد،
 * على نمطِ `asset_custody`/`station_assignments` (الأثرُ الذي لا يُعاد كتابتُه).
 *
 * **التخصيصُ للمشروعِ ليس عهدةً (custody).** الأصلُ قد يُحمَل بيدِ موظّفٍ أو يقع في محطّةٍ
 * (`assets.holder_id`/`station_id` + `asset_custody`/`station_assignments`) **بينما** يُخصَّص
 * تشغيليّاً لمشروع. الدومينان مستقلّان: هذا الجدولُ لا يمسّ الحائزَ ولا المحطّةَ ولا العهدةَ
 * ولا النقطةَ الطرفيّة، وإنهاؤه لا يفكّها. أصلٌ واحدٌ قد يدعم أكثرَ من مشروعٍ في آنٍ.
 *
 * **النموذجُ صفٌّ زمنيٌّ واحد** (لا سجلُّ أحداثٍ assign/vacate): `assigned_at` + `ended_at`
 * (فارغٌ = نشط). الحاليُّ مشتقٌّ (`ended_at IS NULL`)، والإنهاءُ يحفظ التاريخَ (لا حذف).
 *
 * **منعُ التكرارِ النشطِ عبرَ المحرّكين (§45):** عمودُ `active_flag` = 1 حين النشاط، و`NULL`
 * حين الإنهاء، وفهرسٌ فريدٌ `(asset_id, project_id, active_flag)`. NULL «مختلفٌ عن كلِّ NULL»
 * في SQLite وMySQL/MariaDB معاً — فالمُنهاةُ لا تتصادم، والنشطُ فريدٌ لكلِّ (أصل، مشروع). لا
 * فهرسٌ جزئيٌّ خاصٌّ بمحرّك. والخدمةُ تحيط الإنشاءَ بمعاملةٍ + قفلٍ (§46) دفاعاً في العمق.
 *
 * **ترتيبٌ حتميٌّ (C13):** فهارسُ `(…, id)` — القراءةُ تُرتَّب `orderBy(...)->orderBy(id)`.
 * إضافيّةٌ محروسةٌ، عكوسةٌ، غيرُ مُدمِّرة.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('asset_project_assignments')) return;

        Schema::create('asset_project_assignments', function (Blueprint $t) {
            $t->uuid('id')->primary();
            // شركةُ الأصلِ — لعزلِ القراءةِ والنسخِ الخام (نظيرُ station_assignments)
            $t->uuid('company_id')->nullable();
            $t->uuid('asset_id');
            $t->uuid('project_id');
            // الغرضُ/الدورُ الخفيف (خادمُ مشروع · جهازُ اختبار · وحدةُ عرض…) — اختياريّ
            $t->string('purpose', 120)->nullable();
            $t->string('note', 500)->nullable();
            // زمنُ التخصيصِ الفعليّ ومن نفّذه
            $t->timestamp('assigned_at')->nullable();
            $t->uuid('assigned_by')->nullable();
            // الإنهاءُ يحفظ التاريخ (لا حذف): متى ومن ولماذا
            $t->timestamp('ended_at')->nullable();
            $t->uuid('ended_by')->nullable();
            // رايةُ النشاط — 1 حين النشاط، NULL حين الإنهاء (منعُ التكرارِ النشطِ عبر المحرّكين)
            $t->unsignedTinyInteger('active_flag')->nullable();
            $t->timestamps();
            $t->softDeletes();

            // فريدٌ نشطٌ لكلِّ (أصل، مشروع) — NULL مختلفٌ في المحرّكين فلا تتصادم المُنهاة
            $t->unique(['asset_id', 'project_id', 'active_flag'], 'apa_active_unique');
            // قراءاتٌ حتميّةٌ مفهرسة: نشطُ المشروع، نشطُ الأصل، وتاريخُ كلٍّ
            $t->index(['project_id', 'active_flag', 'id'], 'apa_project_active');
            $t->index(['asset_id', 'active_flag', 'id'], 'apa_asset_active');
            $t->index(['company_id'], 'apa_company');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('asset_project_assignments');
    }
};
