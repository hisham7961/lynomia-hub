<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** مركز الإعلام — كل ظهور إعلامي لنا: خبر أو مقابلة أو بيان صحفي، مع منصته ووصوله وأثره */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('media_items', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->string('title', 300);                           // عنوان المادة الإعلامية
            $t->string('type', 60)->nullable()->index();        // نوع الظهور: خبر/مقابلة/مؤتمر/فيديو...
            $t->string('outlet', 200)->nullable()->index();     // الجهة الناشرة أو المنصة
            $t->date('date')->nullable()->index();              // تاريخ النشر أو الظهور
            $t->string('url', 600)->nullable();                 // رابط المادة المنشورة
            $t->uuid('brand_id')->nullable()->index();          // العلامة التجارية المعنية
            $t->uuid('company_id')->nullable()->index();        // الشركة المعنية
            $t->uuid('speaker_id')->nullable()->index();        // المتحدث باسمنا في هذا الظهور
            $t->text('summary')->nullable();                    // ملخص ما قيل أو نُشر
            $t->string('lang', 40)->nullable()->index();        // لغة المادة: عربي/إنجليزي/ثنائي
            $t->unsignedInteger('reach')->nullable();           // الوصول التقديري (عدد المشاهدات/القراء)
            $t->string('impact', 40)->nullable()->index();      // أثر الظهور علينا: إيجابي/محايد/سلبي
            $t->uuid('att_id')->nullable();                     // مرفق أو تسجيل في attachments
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

        DB::statement('CREATE INDEX media_items_date_status_idx ON media_items (date, status)');
        DB::statement('CREATE INDEX media_items_brand_date_idx ON media_items (brand_id, date)');
        DB::statement('CREATE INDEX media_items_type_impact_idx ON media_items (type, impact)');
    }

    public function down(): void
    {
        Schema::dropIfExists('media_items');
    }
};
