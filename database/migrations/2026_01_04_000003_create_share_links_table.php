<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** غرفة البيانات: روابط مشاركة مؤقتة لمستندات حساسة + سجل مشاهدات */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('share_links', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->string('token', 64)->unique();
            $t->string('title', 200);
            $t->string('path', 500);                    // مسار الملف في القرص المحلي (خاص)
            $t->string('mime', 120)->nullable();
            $t->string('password_hash')->nullable();    // فارغ = بلا كلمة مرور
            $t->timestamp('expires_at')->nullable();    // فارغ = بلا انتهاء
            $t->boolean('no_download')->default(false); // عرض فقط بعلامة مائية
            $t->boolean('revoked')->default(false);
            $t->unsignedInteger('views')->default(0);
            $t->uuid('created_by')->index();
            $t->timestamp('created_at');
        });

        Schema::create('share_views', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->uuid('share_id')->index();
            $t->string('ip', 60)->nullable();
            $t->string('device', 200)->nullable();
            $t->timestamp('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('share_views');
        Schema::dropIfExists('share_links');
    }
};
