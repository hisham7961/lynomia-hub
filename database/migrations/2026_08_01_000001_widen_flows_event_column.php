<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `flows.event` وُلد `varchar(20)` ليحمل ثلاث كلمات (created · updated · status).
 * ثم أُضيفت **الأحداث الدلالية** — ٢٨ حدثاً أطولها ٢٦ خانة — وسُمح بها في
 * تحقّق النموذج وبُذرت في `hub:flows-starter`، والعمود على حاله.
 *
 * فعلى MySQL: `SQLSTATE[22001] Data too long for column 'event'` — لا تُحفَظ
 * أتمتةٌ على `contract.approval_rejected` ولا `project.status_changed` ولا
 * `backup.restore_failed`، **وأمر بذر الأتمتة يسقط في منتصفه** فينهار الوضع
 * التجريبي. وعلى sqlite تمرّ القيمة كاملةً، فالحزمة خضراء والإنتاج مكسور.
 *
 * ١٢٠ خانة: ضِعفُ أطول حدثٍ اليوم وأكثر — فلا نعود لهذه الهجرة مع كل حدثٍ جديد.
 * (تعديلٌ توسيعيّ بحت: لا بيانات تُفقد، ولا فهرس يُسقط.)
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('flows')) return;

        Schema::table('flows', function (Blueprint $t) {
            $t->string('event', 120)->change();
        });
    }

    public function down(): void
    {
        // لا تضييق: تضييقُ عمودٍ يحمل قيماً أطول يقصّها صمتاً
    }
};
