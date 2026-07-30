<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** صلاحيات مستوى الحقل: {وحدة: {مفتاح_حقل: ro|hide}} لكل دور */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('roles', fn (Blueprint $t) => $t->text('field_rules')->nullable());
    }

    public function down(): void
    {
        Schema::table('roles', fn (Blueprint $t) => $t->dropColumn('field_rules'));
    }
};
