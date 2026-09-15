<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * **DB-02 · النوبةُ الليليّةُ غيرُ قابلةٍ للتسجيل** (مجلس الخبراء).
 *
 * المخطّطُ `date` + `time_in/time_out` نصّان = **تاريخٌ وساعتان من ساعاتِ
 * اليوم**، وهو تمثيلٌ لا يسع العبورَ فوق منتصفِ الليل. والحقيقةُ التجاريّةُ
 * **فترةٌ** لها بدايةٌ ونهايةٌ في الزمنِ المطلق.
 *
 * **إضافةٌ بحتة:** ثلاثةُ أعمدةٍ اختياريّة — `overnight` رايةٌ صريحة، و`in_at`
 * و`out_at` يحملان اللحظةَ الكاملة. و`date`/`time_in`/`time_out` تبقى كما هي
 * فلا يتغيّر عقدٌ ولا عرضٌ ولا تقرير.
 *
 * **ولماذا رايةٌ صريحةٌ لا استنتاج؟** لو رُوّل العبورُ تلقائيّاً كلَّما كان
 * الانصرافُ قبلَ الدخول، لصار **خطأٌ مطبعيٌّ في ورديةٍ نهاريّة** (٢٢:٠٠ بدل
 * ٠٨:٠٠) ورديةً ليليّةً صحيحةً بثماني ساعات — فيُبتلع الخطأُ بدل أن يُردّ.
 * الحارسُ يبقى يمنع «اليومَ السالب»، والرايةُ تُعلن النيّة.
 */
return new class extends Migration {
    public function up(): void
    {
        if (! Schema::hasTable('attendance')) return;

        Schema::table('attendance', function (Blueprint $t) {
            if (! Schema::hasColumn('attendance', 'overnight')) {
                $t->boolean('overnight')->default(false)->after('time_out');
            }
            if (! Schema::hasColumn('attendance', 'in_at')) {
                $t->timestamp('in_at')->nullable()->after('overnight');
            }
            if (! Schema::hasColumn('attendance', 'out_at')) {
                $t->timestamp('out_at')->nullable()->after('in_at');
            }
        });

        /*
         * **وفهرسان يخدمان ما يُستعلَم فعلاً** (DB-03): `attendance.date` كان بلا
         * فهرسٍ في جدولٍ عليه أربعةَ عشرَ فهرساً، و`EXPLAIN` على كشفِ الشهر يقول
         * `type: ALL · key: NULL`. المركّبُ يخدم نطاقَ الموظّفِ ويومَه، والمفردُ
         * يخدم تقاريرَ الشركةِ كلِّها. **ولا يُحذف فهرسٌ قائم.**
         */
        $idx = collect(Schema::getIndexes('attendance'))->pluck('name')->all();
        Schema::table('attendance', function (Blueprint $t) use ($idx) {
            if (! in_array('attendance_emp_date_idx', $idx, true)) {
                $t->index(['emp_id', 'date'], 'attendance_emp_date_idx');
            }
            if (! in_array('attendance_date_idx', $idx, true)) {
                $t->index('date', 'attendance_date_idx');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('attendance')) return;

        $idx = collect(Schema::getIndexes('attendance'))->pluck('name')->all();
        Schema::table('attendance', function (Blueprint $t) use ($idx) {
            if (in_array('attendance_emp_date_idx', $idx, true)) $t->dropIndex('attendance_emp_date_idx');
            if (in_array('attendance_date_idx', $idx, true)) $t->dropIndex('attendance_date_idx');
        });
        foreach (['out_at', 'in_at', 'overnight'] as $c) {
            if (Schema::hasColumn('attendance', $c)) {
                Schema::table('attendance', fn (Blueprint $t) => $t->dropColumn($c));
            }
        }
    }
};
