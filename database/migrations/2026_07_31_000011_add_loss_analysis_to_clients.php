<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * المرحلة ٥ — تشريح الخسارة: مرحلة «خسارة» كانت بلا سبب ولا «لصالح مَن»،
 * ووحدة المنافسين كاملةً لا تُقرأ من أي شاشة. سببٌ ومنافسٌ يحوّلان الخسائر
 * من سجلٍ صامت إلى تقرير «لماذا نخسر ولمن».
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clients', function (Blueprint $t) {
            if (! Schema::hasColumn('clients', 'lost_reason')) $t->string('lost_reason', 80)->nullable()->index();
            if (! Schema::hasColumn('clients', 'competitor_id')) $t->uuid('competitor_id')->nullable()->index();
        });
    }

    public function down(): void
    {
        Schema::table('clients', function (Blueprint $t) {
            foreach (['lost_reason', 'competitor_id'] as $col) {
                if (Schema::hasColumn('clients', $col)) $t->dropColumn($col);
            }
        });
    }
};
