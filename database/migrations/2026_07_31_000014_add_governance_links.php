<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * المرحلة ٦ — إغلاق حلقات الحوكمة:
 *  - meetings.status: لم تكن للاجتماع حالة، فلا يُعرف أي اجتماعٍ مضى بلا محضر.
 *  - tasks.decision_id: القرار كان أرشيفاً — لا يُقاس بإنجاز مهامه.
 *  - issues.likelihood: سجل مخاطر بلا احتمال ليس سجل مخاطر — `severity` وحدها
 *    لا تصنع درجةً ولا خريطة حرارية.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('meetings', 'status')) {
            Schema::table('meetings', function (Blueprint $t) {
                $t->string('status', 80)->nullable()->index();
            });
        }
        if (! Schema::hasColumn('tasks', 'decision_id')) {
            Schema::table('tasks', function (Blueprint $t) {
                $t->uuid('decision_id')->nullable()->index();
            });
        }
        if (! Schema::hasColumn('issues', 'likelihood')) {
            Schema::table('issues', function (Blueprint $t) {
                $t->string('likelihood', 80)->nullable()->index();
            });
        }
    }

    public function down(): void
    {
        Schema::table('meetings', fn (Blueprint $t) => Schema::hasColumn('meetings', 'status') ? $t->dropColumn('status') : null);
        Schema::table('tasks', fn (Blueprint $t) => Schema::hasColumn('tasks', 'decision_id') ? $t->dropColumn('decision_id') : null);
        Schema::table('issues', fn (Blueprint $t) => Schema::hasColumn('issues', 'likelihood') ? $t->dropColumn('likelihood') : null);
    }
};
