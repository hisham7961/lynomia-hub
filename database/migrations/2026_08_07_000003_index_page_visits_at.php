<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `page_visits` بلا فهرسٍ على `at`: كلُّ تشذيبٍ (‏`where('at','<',…)->delete()`)
 * وكلُّ قراءةِ نبضٍ زمنيّ مسحٌ كاملٌ للجدول — والجدولُ ينمو بصفٍّ لكل طلبِ صفحةٍ
 * لكل مستخدم. الفهرسُ يجعل التشذيبَ والقراءةَ مدىً لا مسحاً.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('page_visits')) return;
        Schema::table('page_visits', function (Blueprint $t) {
            if (! $this->hasIndex('page_visits', 'page_visits_at_index')) {
                $t->index('at', 'page_visits_at_index');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('page_visits')) return;
        Schema::table('page_visits', function (Blueprint $t) {
            if ($this->hasIndex('page_visits', 'page_visits_at_index')) {
                $t->dropIndex('page_visits_at_index');
            }
        });
    }

    /** فحصُ وجود فهرسٍ على المحرّكين بلا doctrine/dbal */
    protected function hasIndex(string $table, string $index): bool
    {
        try {
            return in_array($index, Schema::getConnection()
                ->getSchemaBuilder()->getIndexListing($table), true);
        } catch (\Throwable $e) {
            return false;
        }
    }
};
