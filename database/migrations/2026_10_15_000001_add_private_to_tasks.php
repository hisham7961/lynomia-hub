<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * **المهمّةُ الخاصّة** — علَمٌ يحصر المهمّةَ في أهلها (المُسنَد إليه والمشاركين ومُنشئها
 * ومدير مشروعها والمالك). إضافةٌ لا كسر: الافتراضُ `false` فكلُّ مهمّةٍ قائمةٍ تبقى كما كانت.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('tasks') && ! Schema::hasColumn('tasks', 'private')) {
            Schema::table('tasks', function (Blueprint $t) {
                $t->boolean('private')->default(false)->index();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('tasks', 'private')) {
            Schema::table('tasks', fn (Blueprint $t) => $t->dropColumn('private'));
        }
    }
};
