<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** مركز الابتكار: الأفكار بتقييم ICE (الأثر × الثقة × سهولة التنفيذ) وترقيتها لمشاريع */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ideas', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->string('title', 300);
            $t->text('problem')->nullable();          // المشكلة التي تحلها
            $t->text('idea')->nullable();             // وصف الفكرة
            $t->string('cat', 80)->nullable()->index();
            $t->unsignedTinyInteger('impact')->nullable();     // ١-١٠
            $t->unsignedTinyInteger('confidence')->nullable(); // ١-١٠
            $t->unsignedTinyInteger('ease')->nullable();       // ١-١٠
            $t->uuid('by_id')->nullable()->index();   // صاحب الفكرة
            $t->uuid('project_id')->nullable()->index(); // المشروع الناتج إن رُقّيت
            $t->string('status', 60)->nullable()->index();
            $t->text('notes')->nullable();
            $t->json('tags')->nullable();
            $t->json('custom')->nullable();
            $t->json('meta')->nullable();
            $t->integer('version')->default(1);
            $t->boolean('archived')->default(false)->index();
            $t->uuid('created_by')->nullable()->index();
            $t->uuid('updated_by')->nullable();
            $t->timestamps();
            $t->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ideas');
    }
};
