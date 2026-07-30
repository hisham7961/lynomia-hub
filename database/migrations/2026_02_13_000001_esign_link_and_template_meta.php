<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ربط طلب التوقيع بأي جهة (عميل، موظف، عميل محتمل، شركة، مورد، مشروع…)
 * بمفتاح متعدّد الأشكال (link_module, link_id) — نفس سكّة النظام. ومعلوماتٌ
 * أغنى للقالب: النوع والوصف والترتيب.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sign_requests', function (Blueprint $t) {
            if (! Schema::hasColumn('sign_requests', 'link_module')) {
                $t->string('link_module', 40)->nullable()->after('contract_id');
                $t->string('link_id', 64)->nullable()->after('link_module');
                $t->index(['link_module', 'link_id'], 'sign_link_idx');
            }
        });

        Schema::table('sign_templates', function (Blueprint $t) {
            if (! Schema::hasColumn('sign_templates', 'descr')) {
                $t->string('descr', 300)->nullable()->after('kind');
                $t->unsignedInteger('sort')->default(0)->after('descr');
            }
        });
    }

    public function down(): void
    {
        Schema::table('sign_requests', function (Blueprint $t) {
            $t->dropIndex('sign_link_idx');
            $t->dropColumn(['link_module', 'link_id']);
        });
        Schema::table('sign_templates', function (Blueprint $t) {
            $t->dropColumn(['descr', 'sort']);
        });
    }
};
