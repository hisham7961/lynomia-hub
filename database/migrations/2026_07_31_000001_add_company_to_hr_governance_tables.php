<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * جولة تدقيق ٦ — سدّ فجوة العزل: المهارات والسياسات وإقراراتها كانت بلا عمود
 * شركة أصلاً، فمستخدمٌ معزول على شركةٍ يرى بيانات كل الشركات. عمود اختياري
 * إضافي صرف — السجلات القائمة تبقى بلا شركة (على مستوى المنشأة) كما كانت.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['skills', 'policies', 'policy_acks'] as $table) {
            if (! Schema::hasColumn($table, 'company_id')) {
                Schema::table($table, function (Blueprint $t) {
                    $t->uuid('company_id')->nullable()->index();
                });
            }
        }
    }

    public function down(): void
    {
        foreach (['skills', 'policies', 'policy_acks'] as $table) {
            if (Schema::hasColumn($table, 'company_id')) {
                Schema::table($table, function (Blueprint $t) {
                    $t->dropColumn('company_id');
                });
            }
        }
    }
};
