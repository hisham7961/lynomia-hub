<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * **معلمُ الدفع: حالةُ «بُلغ» ورابطُ الفاتورة** — البنيةُ التي كانت غائبةً بصدق.
 *
 * `quote_milestones` كان جدولَ سدادٍ في العرض فقط (نسبةٌ/مبلغٌ عند محفّز) بلا
 * حالةِ بلوغٍ ولا رابطِ فاتورة — فإشارةُ «معلمٌ بُلغ ولم يُفوتَر» بقيت مؤجَّلةً
 * لا مختلَقة. هذه الهجرةُ إضافيّةٌ بحتة (أعمدةٌ فارغةٌ للقائم، لا تغييرَ عقد):
 *
 *  - `reached_at`/`reached_by`: من أعلن بلوغَ المعلم ومتى (فعلٌ بشريٌّ مسجَّل).
 *  - `invoice_id`: الفاتورةُ المسكوكةُ لهذا المعلم (`fin_documents.id`) — مصدرُ
 *    الحقيقة للفوترة يبقى `fin_documents`؛ هذا مؤشّرٌ لا نسخة.
 *  - `due_date`: موعدٌ مخطَّطٌ اختياريّ (يُكمل `due_note` النصيّ ولا يبدله).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('quote_milestones')) return;

        Schema::table('quote_milestones', function (Blueprint $t) {
            if (! Schema::hasColumn('quote_milestones', 'due_date'))   $t->date('due_date')->nullable();
            if (! Schema::hasColumn('quote_milestones', 'reached_at')) $t->timestamp('reached_at')->nullable()->index();
            if (! Schema::hasColumn('quote_milestones', 'reached_by')) $t->uuid('reached_by')->nullable();
            if (! Schema::hasColumn('quote_milestones', 'invoice_id')) $t->uuid('invoice_id')->nullable()->index();
        });
    }

    public function down(): void
    {
        // لا هجرة مدمّرة
    }
};
