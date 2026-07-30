<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** إضافة «المسؤول عن التنفيذ» (exec_id) للقرارات — يفصل الدور عن «صاحب القرار» (owner_id) */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('decisions', 'exec_id')) {
            return; // آمنة للتكرار
        }

        Schema::table('decisions', function (Blueprint $t) {
            $t->uuid('exec_id')->nullable()->index();
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('decisions', 'exec_id')) {
            return;
        }

        Schema::table('decisions', function (Blueprint $t) {
            $t->dropColumn('exec_id');
        });
    }
};
