<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ذاكرة عناوين المستخدم: كل IP دخل منه وكم مرة — منها يُعرف «مكان العمل المعتاد»،
 * والدخول من عنوانٍ غريب أو خارج الدوام يُنبَّه عليه (حارس الدخول LoginSentry).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_ips', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('user_id')->index();
            $t->string('ip', 45);
            $t->unsignedInteger('hits')->default(1);
            $t->timestamp('last_seen_at')->nullable();
            $t->unique(['user_id', 'ip']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_ips');
    }
};
