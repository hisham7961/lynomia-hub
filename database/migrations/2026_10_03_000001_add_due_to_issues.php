<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * **موعدٌ للمعوّق — وإلّا فالمقبِضُ ثلثان** (قرارُ المالك · v2.558).
 *
 * بطاقةُ «حواجبُ مبلَّغة» كانت موعظةً لا مقبِضاً: نصُّها «راجع المعوّقات مع
 * الفريق» وفعلُها الوحيد «افتح المشروع». والقياس: **٤٧٣ تقريرَ عملٍ يذكر
 * معوّقاً، مقابل ٦ بلاغاتٍ متتبَّعةٍ في القاعدة كلِّها**.
 *
 * وقرارُ المالكِ أن يصير للمعوّق **مالكٌ وموعدٌ وحالةٌ تُغلَق** عبر زرِّ «حوّله
 * إلى بلاغ». والمالكُ والحالةُ موجودان في `issues` (`assignee_id` و`status`)،
 * **والموعدُ لم يكن**: الجدولُ يحمل `found` و`closed` لا تاريخَ استحقاق. فبلا
 * هذا العمودِ يبقى الوعدُ ثلثَيه.
 *
 * **ولمَ هي غيرُ هادمة:** عمودٌ **جديدٌ يقبل العدم** — لا يمسّ صفّاً قائماً ولا
 * يغيّر عقداً. وكلُّ بلاغٍ سابقٍ يبقى بلا موعدٍ كما كُتب، ولا يصير متأخّراً
 * بأثرٍ رجعيّ.
 *
 * والتراجعُ متاحٌ هنا (بخلاف هجرةِ الردم): إسقاطُ عمودٍ أُضيف للتوّ يعيد الحالَ
 * كما كان بلا فقدِ معنى — فالمواعيدُ المكتوبةُ فيه بعد الترقية هي وحدَها ما
 * يضيع، وذلك ما يعنيه التراجعُ عن ميزة.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('issues') || Schema::hasColumn('issues', 'due')) return;

        Schema::table('issues', function (Blueprint $t) {
            // بعد `found` مباشرةً: اكتشافٌ ثمّ استحقاقٌ ثمّ إغلاق — ترتيبُ الحياة
            $t->date('due')->nullable()->after('found');
        });
    }

    public function down(): void
    {
        if (Schema::hasTable('issues') && Schema::hasColumn('issues', 'due')) {
            Schema::table('issues', fn (Blueprint $t) => $t->dropColumn('due'));
        }
    }
};
