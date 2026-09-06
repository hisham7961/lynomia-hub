<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * **رأسُ الحادثة (WP-6.1) — إضافةً لا كسراً.**
 *
 * — `detected_at`: متى **عُلم** بالحادثة (غيرُ `started_at`: متى وقعت) — فجوةُ
 *   الكشف نفسُها مؤشّرٌ يُقرأ في الرأس.
 * — `kind` (20، مفهرس): مرآةُ `meta.kind` — كان عدُّ الحوادث الأمنيّة المفتوحة
 *   مسحَ `meta LIKE '%"kind":"security"%'` في كلّ فتحٍ لمركز الأمن ولفحص الصحة.
 *   التعبئةُ **كسولة**: الكاتبُ الجديد (`hub_security_incident`) يملأ العمود،
 *   والقارئ يسقط إلى meta للصفوف القديمة — لا هجرةَ تعبئةٍ فوق جدولٍ حيّ.
 *
 * ولا عمودَ `resolution` (critic #2): ثلاثيّةُ §8.5 مغطّاةٌ بالقائم —
 * `root_cause` + `steps` + `prevention` — وبوّابةُ الإغلاق تشترط `steps`.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('incidents')) return;

        Schema::table('incidents', function (Blueprint $t) {
            if (! Schema::hasColumn('incidents', 'detected_at')) $t->dateTime('detected_at')->nullable();
            if (! Schema::hasColumn('incidents', 'kind')) $t->string('kind', 20)->nullable()->index();  // security|… — مرآة meta.kind
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('incidents')) return;
        Schema::table('incidents', function (Blueprint $t) {
            foreach (['detected_at', 'kind'] as $c) {
                if (Schema::hasColumn('incidents', $c)) $t->dropColumn($c);
            }
        });
    }
};
