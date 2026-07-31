<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * الإقرار يصلح للسياسة **ولمقال قاعدة المعرفة** — «قراءة إلزامية» كانت
 * مربّعاً بلا أثر. وتُضاف للسياسة جهتُها: مشروعٌ أو شركة، فتُخاطِب من يعنيها
 * لا كل الناس.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('policy_acks', function (Blueprint $t) {
            $t->string('src_module', 20)->nullable()->index();   // policies | kb
            $t->uuid('record_id')->nullable()->index();          // السجل المُقَر (يعمّم policy_id)
            $t->uuid('sign_request_id')->nullable()->index();    // إن أُقرّ بتوقيعٍ إلكتروني
        });

        // الإقرارات القائمة تنتقل للعمود العام بلا فقدٍ ولا حذف
        try {
            DB::table('policy_acks')->whereNull('record_id')->whereNotNull('policy_id')
                ->update(['record_id' => DB::raw('policy_id'), 'src_module' => 'policies']);
        } catch (\Throwable $e) {
        }

        // بعض الأعمدة موجودةٌ سلفاً في جداول أخرى — نضيف الناقص وحده
        $add = function (string $table, array $cols) {
            $missing = array_filter($cols, fn ($c) => ! Schema::hasColumn($table, $c));
            if (! $missing) return;
            Schema::table($table, function (Blueprint $t) use ($missing) {
                foreach ($missing as $c) {
                    match ($c) {
                        'project_id', 'company_id' => $t->uuid($c)->nullable()->index(),
                        'audience' => $t->string($c, 30)->nullable(),   // الجميع | مشروع | شركة
                        'announced_at' => $t->timestamp($c)->nullable(),
                    };
                }
            });
        };
        $add('policies', ['project_id', 'company_id', 'audience', 'announced_at']);
        $add('kb_articles', ['project_id', 'audience', 'announced_at']);
    }

    public function down(): void
    {
        Schema::table('policy_acks', function (Blueprint $t) {
            $t->dropColumn(['src_module', 'record_id', 'sign_request_id']);
        });
        Schema::table('policies', function (Blueprint $t) {
            $t->dropColumn(['project_id', 'company_id', 'audience', 'announced_at']);
        });
        Schema::table('kb_articles', function (Blueprint $t) {
            $t->dropColumn(['project_id', 'audience', 'announced_at']);
        });
    }
};
