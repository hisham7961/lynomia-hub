<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** سلسلة تجزئة للتدقيق: كل سجل يحمل تجزئته وتجزئة سابقه — أي عبث يكسر السلسلة */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('audits', function (Blueprint $t) {
            $t->char('hash', 64)->nullable()->index();
            $t->char('prev_hash', 64)->nullable();
        });

        // رأس السلسلة — صف واحد يُحدَّث ذرياً مع كل سجل جديد
        Schema::create('audit_chain', function (Blueprint $t) {
            $t->unsignedTinyInteger('id')->primary();
            $t->char('head', 64);
        });
        DB::table('audit_chain')->insert(['id' => 1, 'head' => str_repeat('0', 64)]);
    }

    public function down(): void
    {
        Schema::table('audits', fn (Blueprint $t) => $t->dropColumn(['hash', 'prev_hash']));
        Schema::dropIfExists('audit_chain');
    }
};
