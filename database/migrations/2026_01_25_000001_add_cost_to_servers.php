<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** تكلفة السيرفر ودورتها — بدونها لا يمكن حساب تكلفة البنية ضمن ربحية المشروع */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('servers', function (Blueprint $t) {
            $t->decimal('cost', 14, 2)->nullable();          // تكلفة الدورة الواحدة
            $t->string('cycle', 30)->nullable();             // شهري | سنوي | مرة واحدة
            $t->string('currency', 20)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('servers', fn (Blueprint $t) => $t->dropColumn(['cost', 'cycle', 'currency']));
    }
};
