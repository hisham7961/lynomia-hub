<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * المرحلة ٢ — «سجّل دفعة»: المستند المالي كان بلا مربط بنك، فلا يُعرف من أي
 * حسابٍ خرجت الدفعة ولا إلى أيّه دخلت، ورصيد البنوك جامد لا تحركه دفعة.
 * عمود اختياري إضافي صرف.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('fin_documents', 'bank_id')) return;
        Schema::table('fin_documents', function (Blueprint $t) {
            $t->uuid('bank_id')->nullable()->index();
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('fin_documents', 'bank_id')) return;
        Schema::table('fin_documents', function (Blueprint $t) {
            $t->dropColumn('bank_id');
        });
    }
};
