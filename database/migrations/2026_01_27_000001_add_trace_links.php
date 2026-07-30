<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * حلقات خيط التتبع من الفكرة إلى النشر:
 * طلب → متطلب → مهمة → تغيير → (إصدار) → نشر — الوحدات موجودة والروابط كانت مقطوعة.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('plan_items', fn (Blueprint $t) => $t->uuid('request_id')->nullable()->index());
        Schema::table('tasks', fn (Blueprint $t) => $t->uuid('feat_id')->nullable()->index());
        Schema::table('changes', fn (Blueprint $t) => $t->uuid('task_id')->nullable()->index());
        Schema::table('deployments', fn (Blueprint $t) => $t->uuid('release_id')->nullable()->index());
    }

    public function down(): void
    {
        Schema::table('plan_items', fn (Blueprint $t) => $t->dropColumn('request_id'));
        Schema::table('tasks', fn (Blueprint $t) => $t->dropColumn('feat_id'));
        Schema::table('changes', fn (Blueprint $t) => $t->dropColumn('task_id'));
        Schema::table('deployments', fn (Blueprint $t) => $t->dropColumn('release_id'));
    }
};
