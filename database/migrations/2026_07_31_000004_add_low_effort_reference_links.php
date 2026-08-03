<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * المرحلة ١ — الربط منخفض الجهد: أعمدة مرجعية كانت غيابها يقطع أوصال النظام.
 *
 *  - tickets.client_id: الحلقة المفقودة الوحيدة في رؤية العميل ٣٦٠° — كانت
 *    التذاكر معلّقة على نص «customer» الحر. الترحيل محافظ كنمط الفواتير:
 *    يُملأ فقط حين يطابق النصُ عميلاً واحداً لا لبس فيه، والعمود النصي يبقى.
 *  - websites.domain_id/server_id: الموقع المعطّل لم يكن يقود لدومينه ولا خادمه.
 *  - emails.domain_id/vault_id: انتهاء الدومين يُسقط بريده، وكلمة سرّه كانت
 *    تُكتب في notes غير المشفّر لغياب مربطها.
 *  - servers.vault_id وaccounts.vault_id: اعتماد SSH وحسابات المنصات بلا مربط.
 *  - accounts.two_fa: حسابات المنصات أكثر عرضة والحقل كان للبريد وحده.
 *  - restores.db_id وnext_test: اختبار الاستعادة لم يكن يُنسب لقاعدة بعينها،
 *    والوحدة الدورية بطبيعتها كانت بلا أي موعد قادم يذكّر.
 * كلها أعمدة اختيارية إضافية صرفة — لا حذف ولا إعادة تسمية.
 */
return new class extends Migration
{
    private const COLS = [
        'tickets' => [['client_id', 'uuid']],
        'websites' => [['domain_id', 'uuid'], ['server_id', 'uuid']],
        'email_accounts' => [['domain_id', 'uuid'], ['vault_id', 'uuid']],
        'servers' => [['vault_id', 'uuid']],
        'platform_accounts' => [['vault_id', 'uuid'], ['two_fa', 'bool']],
        'restore_tests' => [['db_id', 'uuid'], ['next_test', 'date']],
    ];

    public function up(): void
    {
        foreach (self::COLS as $table => $cols) {
            if (! Schema::hasTable($table)) continue;
            Schema::table($table, function (Blueprint $t) use ($table, $cols) {
                foreach ($cols as [$col, $type]) {
                    if (Schema::hasColumn($table, $col)) continue;
                    match ($type) {
                        'uuid' => $t->uuid($col)->nullable()->index(),
                        'bool' => $t->boolean($col)->default(false),
                        'date' => $t->date($col)->nullable()->index(),
                    };
                }
            });
        }

        // ترحيل تذاكر العملاء محافظاً: الاسم المطابق لعميل واحد بالضبط يُرحَّل، وما دونه يُترك
        if (Schema::hasTable('tickets') && Schema::hasTable('clients')) {
            $unique = DB::table('clients')->whereNull('deleted_at')
                ->whereNotNull('name')->where('name', '!=', '')
                ->select('name', DB::raw('COUNT(*) as n'), DB::raw('MIN(id) as id'))
                ->groupBy('name')->havingRaw('COUNT(*) = 1')->get();
            foreach ($unique as $c) {
                DB::table('tickets')
                    ->whereNull('client_id')->where('customer', $c->name)
                    ->update(['client_id' => $c->id]);
            }
        }
    }

    public function down(): void
    {
        foreach (self::COLS as $table => $cols) {
            if (! Schema::hasTable($table)) continue;
            Schema::table($table, function (Blueprint $t) use ($table, $cols) {
                foreach ($cols as [$col]) {
                    if (Schema::hasColumn($table, $col)) $t->dropColumn($col);
                }
            });
        }
    }
};
