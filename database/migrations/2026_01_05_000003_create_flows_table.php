<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** مسارات العمل بلا كود: حدث + شرط اختياري + إجراءات */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('flows', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->string('name', 200);
            $t->string('module', 60)->index();
            $t->string('event', 20)->index();          // created | updated | status
            $t->string('status_to', 120)->nullable();  // لحدث status: الحالة الهدف
            $t->string('cond_field', 80)->nullable();  // شرط اختياري
            $t->string('cond_op', 20)->nullable();     // eq | has | gt | lt
            $t->string('cond_value', 300)->nullable();
            $t->json('actions');                       // [{type: notify|tg|mail|task|set, ...}]
            $t->boolean('enabled')->default(true)->index();
            $t->unsignedInteger('runs')->default(0);
            $t->timestamp('last_run_at')->nullable();
            $t->uuid('created_by')->nullable();
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('flows');
    }
};
