<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * **تصرّفُ المستخدم بحالة الإشارة المحسوبة** — الجديدُ الوحيدُ المبرَّر في طبقة الذكاء.
 *
 * الإشاراتُ نفسُها **تُحسَب** من البيانات القائمة (`hub_recommendations`, `Inbox`,
 * `Custody::overdue`, `Workday`) — فوجودُها مصدرُ حقيقةٍ محسوبٌ لا مخزَّن: إن زال
 * الشرطُ لم تُبثّ الإشارة فتختفي (حلٌّ تلقائيّ). هذا الجدولُ لا يخزّن الإشارة، بل
 * **تصرّفَ المستخدم بها**: أقرَّها، أجّلها إلى تاريخ، أو رفضها — وهي الفجوةُ التي
 * سمّاها التدقيقُ MISSING صراحةً (لا تأجيلَ على الإشعارات القائمة).
 *
 * لا يكرّر `notifications_hub` (تيّارُ إشعارٍ لكل مستخدم) ولا `record_acks` (إقرارٌ
 * موثَّقٌ على سجلٍّ حقيقيّ): مفتاحُه `skey` هو معرّفُ الإشارة المحسوبة الثابت.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('signal_states')) return;

        Schema::create('signal_states', function (Blueprint $t) {
            $t->uuid('id')->primary();
            // المفتاحُ الثابتُ للإشارة: «<قاعدة>:<سجل>» — فريدٌ كي يُدمَج التصرّفُ لا يتكرّر
            $t->string('skey', 191)->unique();
            $t->string('module', 64)->nullable()->index();   // للربط والعزل
            $t->uuid('record_id')->nullable()->index();
            // مفتوح | مُقَرّ (ack) | مؤجَّل (snoozed) | مرفوض (dismissed)
            $t->string('state', 20)->default('open')->index();
            $t->timestamp('snoozed_until')->nullable()->index();
            $t->uuid('by')->nullable();          // من تصرّف
            $t->timestamp('at')->nullable();     // متى
            $t->string('note', 300)->nullable(); // سببٌ اختياريّ (يُطلَب للحرج)
            // نطاقُ التصرّف — يُملأ من الإشارة كي لا يُسرَّب عبر شركات
            $t->uuid('company_id')->nullable()->index();
            // للتشذيب: آخرُ مرّةٍ رُئيت فيها الإشارةُ حيّة (تصرّفٌ يتّم على إشارةٍ زالت = يتيم)
            $t->timestamp('last_seen_at')->nullable()->index();
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('signal_states');
    }
};
