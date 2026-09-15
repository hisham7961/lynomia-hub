<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * **أسعارُ الصرف — الجدولُ الذي لم يكن** (تعدّدُ العملات · v2.528.0).
 *
 * كان في النظامِ ٣٦ حقلَ عملةٍ في ٢٢ جدولاً، و`app.currency` **تسميةٌ لا
 * تحويل** — تقولها الشيفرةُ صراحةً في `hub_cur_label`. فبطاقةٌ تجمع دينارَين
 * ودولارَين كانت تُوسَم `mixed` وتُقرأ مؤشّراً لا رقماً، **وهو الصدقُ الصحيحُ
 * في غيابِ سعرِ صرف**. هذا الجدولُ يُنهي الغياب.
 *
 * **والإضافةُ لا الكسر:** بلا صفٍّ واحدٍ هنا يبقى كلُّ سلوكٍ قائمٍ كما هو —
 * `mixed` تبقى `mixed`، ولا رقمَ يتغيّر. التحويلُ يبدأ حين تُدخِل المنشأةُ
 * سعراً، لا قبلَه.
 *
 * **والسعرُ مؤرَّخٌ لا لحظيّ:** فاتورةُ يناير تُحوَّل بسعرِ يناير لا بسعرِ اليوم
 * — وإلّا تغيّر تقريرُ الربعِ الماضي كلَّ صباح. `as_of` هو تاريخُ سريانِ السعر،
 * ويُقرأ **أحدثُ سعرٍ لا يتجاوز تاريخَ المستند**.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('currency_rates')) return;

        Schema::create('currency_rates', function (Blueprint $t) {
            $t->uuid('id')->primary();
            // العملةُ المصدرُ ووحدةُ الأساس — نصٌّ كما تُكتب في `currency` بالوحدات
            $t->string('from_cur', 12);
            $t->string('to_cur', 12);
            // كم من `to_cur` تساوي وحدةً واحدةً من `from_cur` — عشرةُ خاناتٍ
            // عشريّةٍ لأنّ بعضَ الأزواجِ كسريّةٌ جدّاً (ين ← دينار)
            $t->decimal('rate', 20, 10);
            $t->date('as_of');
            $t->string('source', 60)->nullable();      // «يدويّ» أو اسمُ مزوّد
            $t->string('note', 300)->nullable();
            $t->uuid('created_by')->nullable();
            $t->timestamps();
            $t->softDeletes();

            // زوجٌ واحدٌ لتاريخٍ واحد — تصحيحُ السعرِ تحديثٌ لا صفٌّ ثانٍ يتنازعه
            $t->unique(['from_cur', 'to_cur', 'as_of'], 'currency_rates_pair_date_uniq');
            $t->index(['from_cur', 'to_cur', 'as_of'], 'currency_rates_lookup_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('currency_rates');
    }
};
