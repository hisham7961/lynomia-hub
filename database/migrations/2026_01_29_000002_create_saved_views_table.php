<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** العروض المحفوظة: فلتر + بحث + فرز باسم واحد، لكل مستخدم، مع عرض افتراضي للوحدة */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('saved_views', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('user_id')->index();
            $t->string('module', 60);
            $t->string('name', 60);
            $t->text('query')->nullable();          // سلسلة الاستعلام كما هي: q, status, f[...], s, d
            $t->boolean('is_default')->default(false);
            $t->timestamps();
            $t->index(['user_id', 'module']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('saved_views');
    }
};
