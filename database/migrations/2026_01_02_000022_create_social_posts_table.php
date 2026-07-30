<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** منشورات ومشاهدات — Posts & reach */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('social_posts', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->string('title', 300);
            $t->uuid('social_id')->nullable()->index();
            $t->uuid('project_id')->nullable()->index();
            $t->string('type', 80)->nullable()->index();
            $t->string('status', 80)->nullable()->index();
            $t->timestamp('pub_at')->nullable();
            $t->string('url', 600)->nullable();
            $t->uuid('author_id')->nullable()->index();
            $t->uuid('design_id')->nullable()->index();
            $t->decimal('views', 16, 3)->nullable();
            $t->decimal('reach', 16, 3)->nullable();
            $t->decimal('impr', 16, 3)->nullable();
            $t->decimal('likes', 16, 3)->nullable();
            $t->decimal('comments2', 16, 3)->nullable();
            $t->decimal('shares', 16, 3)->nullable();
            $t->decimal('saves', 16, 3)->nullable();
            $t->decimal('clicks', 16, 3)->nullable();
            $t->decimal('spend', 16, 3)->nullable();
            $t->text('notes')->nullable();
            $t->json('tags')->nullable();
            $t->uuid('company_id')->nullable()->index();
            $t->json('custom')->nullable();            // الحقول المخصصة
            $t->json('meta')->nullable();              // بيانات إضافية (تاريخ، تكاملات، تتبع)
            $t->integer('version')->default(1);         // القفل التفاؤلي
            $t->boolean('archived')->default(false)->index();
            $t->uuid('created_by')->nullable()->index();
            $t->uuid('updated_by')->nullable();
            $t->timestamps();
            $t->softDeletes();
        });

        DB::statement("CREATE INDEX social_posts_proj_created_idx ON social_posts (project_id, created_at DESC)");
        DB::statement("CREATE INDEX social_posts_status_proj_idx ON social_posts (status, project_id)");
    }

    public function down(): void
    {
        Schema::dropIfExists('social_posts');
    }
};
