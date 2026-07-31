<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * CLM المرحلة ٦ (v2.122) — حزمة الأدلة: عمود تجميد رأس سلسلة تجزئة الأحداث
 * عند اكتمال التوقيع. إضافيٌّ بحت — لا مساس بأي عمود قائم.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sign_requests', function (Blueprint $t) {
            if (! Schema::hasColumn('sign_requests', 'evidence_hash')) {
                $t->string('evidence_hash', 64)->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('sign_requests', function (Blueprint $t) {
            if (Schema::hasColumn('sign_requests', 'evidence_hash')) $t->dropColumn('evidence_hash');
        });
    }
};
