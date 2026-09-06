<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * **حدُّ الحساب الصلب: internal | client** (Work OS · الطور A · WP-A.1 · SF-1 · إضافةً لا كسراً).
 *
 * قبل هذا العمود كان «العميل» يُستنتج من `users.clients` غير الفارغة وحدها —
 * استنتاجٌ يكذب في الطرفين (عميلٌ جديدٌ بلا عضوياتٍ بعد يبدو داخليّاً، وموظفٌ
 * داخليٌّ مخصَّصٌ لعملاءَ بأعيانهم يبدو عميلاً). فـ`account_type` يفصل التصنيفَ
 * البنيويَّ (من هو) عن العزل (ماذا يرى، عبر `hub_client_ids`) — ولا يُستنتج.
 *
 * إضافيّةٌ محروسة: عمودٌ NOT NULL DEFAULT 'internal' (على MySQL 8 إضافةٌ فوريّةٌ
 * بلا إعادةِ بناءٍ، والصفوفُ القائمةُ تُملأ بالافتراض) + تعبئةٌ صريحةٌ تحسّباً
 * لعمودٍ سابقٍ nullable من تشغيلٍ جزئيّ + فهرسٌ على (account_type).
 *
 * العرضُ ١٢ حرفاً معلَنٌ حرفيّاً في مصدر هذه الكتلة — `hub_col_widths` يقرأه من
 * المصدر لا من القاعدة (SQLite لا تفرض varchar)، فيحرسه ColumnFitsItsWriter
 * على عرض MySQL الصارم. القيَمُ الشرعية (`internal`/`client`) allowlist في
 * التطبيق لا ENUM في القاعدة (تجنّبُ ALTER شبهِ المدمِّر على MySQL — درسُ C10).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('users')) return;

        Schema::table('users', function (Blueprint $t) {
            // NOT NULL DEFAULT 'internal' — الصفوفُ القائمةُ تُملأ بالافتراض عند الإضافة
            if (! Schema::hasColumn('users', 'account_type')) {
                $t->string('account_type', 12)->default('internal')->index();
            }
        });

        // تعبئةٌ صريحةٌ لكل صفٍّ قائم — لا صفَّ يبقى بلا تصنيف (يشمل عموداً nullable
        // سابقاً من تشغيلٍ جزئيّ؛ مع NOT NULL DEFAULT هي حارسٌ لا عمليّة)
        DB::table('users')->whereNull('account_type')->update(['account_type' => 'internal']);
    }

    public function down(): void
    {
        if (Schema::hasTable('users') && Schema::hasColumn('users', 'account_type')) {
            Schema::table('users', function (Blueprint $t) {
                $t->dropIndex(['account_type']);
                $t->dropColumn('account_type');
            });
        }
    }
};
