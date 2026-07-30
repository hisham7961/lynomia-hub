<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ١) حقبة سلسلة التدقيق: قبلها السجل بلا بصمة «تاريخ قديم»، وبعدها «كتابة فشل ختمها» —
 *    بدون هذا التمييز كان hub:audit-verify يحسب كل فشلٍ تاريخاً وينجح.
 * ٢) عمودا idempotency_keys يقبلان NULL: الحجز يُكتب قبل التنفيذ لا بعده (يمنع التنفيذ
 *    المزدوج للطلبات المتزامنة) والرد يُملأ عند الإتمام.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('audit_chain', fn (Blueprint $t) => $t->dateTime('started_at')->nullable());

        // بداية السلسلة الفعلية: أول سجل مختوم — وإن لم يوجد فمن الآن
        $first = DB::table('audits')->whereNotNull('hash')->min('created_at');
        DB::table('audit_chain')->where('id', 1)->update(['started_at' => $first ?: now()]);

        Schema::table('idempotency_keys', function (Blueprint $t) {
            $t->unsignedSmallInteger('code')->nullable()->change();
            $t->mediumText('response')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('audit_chain', fn (Blueprint $t) => $t->dropColumn('started_at'));
    }
};
