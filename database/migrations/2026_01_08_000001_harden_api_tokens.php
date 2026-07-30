<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** تصليب API: نطاقات لكل مفتاح + قائمة IP + مفاتيح Idempotency */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('api_tokens', function (Blueprint $t) {
            $t->text('scopes')->nullable();              // null أو «*» = كامل صلاحيات المستخدم · وإلا: tickets:va، projects:v
            $t->string('allowed_ips', 400)->nullable();  // قائمة IP/CIDR مفصولة بفواصل — فارغ = من أي مكان
        });

        Schema::create('idempotency_keys', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('token_id');
            $t->string('ikey', 120);
            $t->unsignedSmallInteger('code');
            $t->mediumText('response');
            $t->dateTime('created_at');
            $t->unique(['token_id', 'ikey']);
        });
    }

    public function down(): void
    {
        Schema::table('api_tokens', function (Blueprint $t) {
            $t->dropColumn(['scopes', 'allowed_ips']);
        });
        Schema::dropIfExists('idempotency_keys');
    }
};
