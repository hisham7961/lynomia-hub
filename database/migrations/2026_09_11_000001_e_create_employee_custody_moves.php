<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * (الطور E · WP-E.1 · §18–20) دفترُ حركاتِ العهدة المالية للموظف —
 * `employee_custody_moves`.
 *
 * **محفظةٌ مشتقّةُ الرصيد لا محرَّرة:** لا عمودَ رصيدٍ في هذا الجدول ولا في
 * `employees`. رصيدُ العهدة = مجموعُ (الإشارة × المبلغ) يُحسَب على القراءة
 * (`Employee::custody_balance`) — فلا قيمةَ تُحرَّر ولا تُزوَّر، على نمطِ
 * `Employee`/`StockItem` المشتقّين. كلُّ حركةٍ سطرٌ ثابتٌ يُضاف ولا يُعدَّل.
 *
 * **ثابتةٌ وتُعكس لا تُحذف:** الحركةُ المُرحَّلة (`posted_at`) مقفلةٌ في النموذج —
 * لا `UPDATE` ولا `DELETE` (نمطُ `StockMove`/`JournalEntry::booted`). الخطأُ
 * يُصحَّح بحركةِ عكسٍ (`reverses_id` + إشارةٌ معاكسة) لا بمسحِ الأثر؛ والعكسُ
 * المزدوجُ للحركةِ نفسِها محجوب.
 *
 * **لا دفترَ محاسبيٍّ ثانٍ:** الترحيلُ المحاسبيُّ يمرّ عبر `JournalEntry` الوحيد
 * (خدمةُ الترحيل المشترَكة — WP-E.2)؛ هذه الحركةُ تحمل `entry_id` للقيد المتوازن،
 * والقيدُ يُقفَل بـ`meta.custody_move_id`. ترحيلُ المصدرِ نفسِه مرّتين يُنتج
 * حركةً واحدةً — يفرضه `UNIQUE(source_module, source_id, kind)` (نمطُ حارسِ
 * الترحيل المزدوج 2026_09_02_000001).
 *
 * **allowlist في التطبيق لا DB enum (C10):** `kind` نصٌّ واسع (٢٠) تفرض قيمَه
 * العشرَ `EmployeeCustodyMove::KINDS` في `saving`؛ و`approval_state` كذلك. إضافةُ
 * قيمةِ enum على MySQL ALTER شبهُ مدمِّر، بينما إضافةُ قيمةٍ إلى allowlist سطرٌ في
 * الكود. والعرضُ معلَنٌ حرفيّاً هنا (`hub_col_widths` يقرأ المصدر) فيحرسه
 * `ColumnFitsItsWriterTest` على عرض MySQL الصارم.
 *
 * **ترتيبٌ حتميٌّ (C13):** `INDEX(employee_id, at, id)` — القراءةُ تُرتَّب
 * `orderBy(at)->orderBy(id)` فلا قرعةَ عند تساوي الأزمنة؛ ورصيدُ SUM يختار
 * مُجمَّعاً فقط (ONLY_FULL_GROUP_BY-safe).
 *
 * إضافيّةٌ محروسة (add-if-not-exists)، كتلةٌ حرفيّةٌ واحدة، بلا softDeletes وبلا
 * عمودِ رصيد.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('employee_custody_moves')) {
            Schema::create('employee_custody_moves', function (Blueprint $t) {
                $t->uuid('id')->primary();
                $t->uuid('employee_id');
                $t->uuid('company_id')->nullable();
                // ١٠ أنواع: advance|charge|expense|repayment|transfer_in|transfer_out|
                // deduction|settlement|correction|reversal — allowlist في النموذج (لا DB enum · C10)
                $t->string('kind', 20);
                // المبلغُ مقدارٌ موجب (١٦،٣) والاتجاهُ في الإشارة — الرصيدُ = SUM(sign × amount)
                $t->decimal('amount', 16, 3)->default(0);
                $t->tinyInteger('sign')->default(1);          // +1 تُزيد ما بذمّة الموظف · -1 تُنقصه
                // مصدرُ الحركة (وحدةٌ + سجل) — به يُمنع الترحيلُ المزدوج للمصدر نفسِه
                $t->string('source_module', 60)->nullable();
                $t->uuid('source_id')->nullable();
                $t->uuid('entry_id')->nullable();             // القيدُ المحاسبيُّ المتوازن (JournalEntry الوحيد)
                $t->uuid('reverses_id')->nullable();          // إن كانت حركةَ عكسٍ: الحركةُ التي تعكسها
                $t->uuid('project_id')->nullable();
                $t->uuid('cc_id')->nullable();                // مركزُ التكلفة
                // pending|approved|rejected — allowlist في النموذج (لا DB enum · C10)
                $t->string('approval_state', 12)->default('pending');
                $t->string('receipt_module', 60)->nullable(); // الإيصالُ عبر Attachment المُنطَّق (وحدة + سجل)
                $t->uuid('receipt_id')->nullable();
                $t->timestamp('at')->nullable();              // زمنُ الحركة الفعليّ (تاريخُ الدفتر)
                $t->timestamp('posted_at')->nullable();       // لحظةُ الترحيل — به تُقفَل الحركة
                $t->uuid('by_id')->nullable();                // مَن رحّلها
                $t->json('meta')->nullable();
                $t->timestamps();

                // ترتيبٌ حتميٌّ لقراءةِ كشفِ الموظف (C13)
                $t->index(['employee_id', 'at', 'id'], 'ecm_emp_at_id');
                $t->index('company_id', 'ecm_company');
                // حارسُ الترحيل المزدوج (نمط 2026_09_02_000001) — NULL للمصدر يسمح بتعدّدٍ في المحرّكين
                $t->unique(['source_module', 'source_id', 'kind'], 'ecm_source_kind_unique');
                $t->index('entry_id', 'ecm_entry');
                $t->index('reverses_id', 'ecm_reverses');
                $t->index('project_id', 'ecm_project');
                $t->index('cc_id', 'ecm_cc');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_custody_moves');
    }
};
