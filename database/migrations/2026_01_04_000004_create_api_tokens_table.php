<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** مفاتيح API — تُخزن مجزأة (sha256)، وتظهر للمستخدم مرة واحدة عند الإنشاء */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('api_tokens', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('user_id')->index();
            $t->string('name', 120);
            $t->string('token_hash', 64)->unique();
            $t->timestamp('expires_at')->nullable();
            $t->timestamp('last_used_at')->nullable();
            $t->timestamp('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('api_tokens');
    }
};
