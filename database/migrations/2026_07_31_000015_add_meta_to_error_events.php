<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ربط الخطأ بمهمة إصلاحه: عمود meta يحفظ معرّف المهمة المولّدة فلا تتكرر
 * عند الضغط مرتين — نفس نمط meta في المشتريات وعروض الأسعار.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('error_events', 'meta')) return;
        Schema::table('error_events', function (Blueprint $t) {
            $t->json('meta')->nullable();
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('error_events', 'meta')) return;
        Schema::table('error_events', function (Blueprint $t) {
            $t->dropColumn('meta');
        });
    }
};
