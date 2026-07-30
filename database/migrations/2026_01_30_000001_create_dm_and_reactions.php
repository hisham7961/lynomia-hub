<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** المراسلة الداخلية المباشرة + التفاعلات على التعليقات والمنشورات */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dm_messages', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->char('thread_key', 73)->index();     // uuid الطرفين مرتبَين ومفصولَين بشرطة — محادثة واحدة للثنائي
            $t->uuid('from_id')->index();
            $t->uuid('to_id')->index();
            $t->text('body');
            $t->string('att', 500)->nullable();
            $t->timestamp('read_at')->nullable();
            $t->timestamp('created_at')->index();
        });

        Schema::create('reactions', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('comment_id')->index();
            $t->uuid('user_id');
            $t->string('emoji', 16);
            $t->timestamp('created_at');
            $t->unique(['comment_id', 'user_id', 'emoji']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dm_messages');
        Schema::dropIfExists('reactions');
    }
};
