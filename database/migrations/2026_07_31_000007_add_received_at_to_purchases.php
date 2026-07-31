<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * المرحلة ٣ — تاريخ استلام صريح: «الالتزام بالموعد» في درجة المورد كان يقارن
 * آخرَ تحديثٍ للأمر بموعده — تقديراً لا قياساً (أي تعديل لاحق يفسده).
 * عمود يُختم عند إجراء الاستلام نفسه.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('purchases', 'received_at')) return;
        Schema::table('purchases', function (Blueprint $t) {
            $t->date('received_at')->nullable()->index();
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('purchases', 'received_at')) return;
        Schema::table('purchases', function (Blueprint $t) {
            $t->dropColumn('received_at');
        });
    }
};
