<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** العلامات التجارية — هوية كل علامة: شعارها وألوانها وخطوطها ونبرة صوتها ومسؤولها وإرشادات استخدامها */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('brands', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->string('name', 300);                            // اسم العلامة التجارية
            $t->uuid('company_id')->nullable()->index();        // الشركة المالكة للعلامة
            $t->text('description')->nullable();                // وصف العلامة وما تمثله
            $t->uuid('logo_id')->nullable();                    // الشعار — مرفق صورة في attachments
            $t->string('colors', 400)->nullable();              // ألوان الهوية (أكواد HEX)
            $t->string('fonts', 300)->nullable();               // خطوط الهوية العربية والإنجليزية
            $t->text('tone')->nullable();                       // نبرة الصوت وأسلوب المخاطبة
            $t->uuid('owner_id')->nullable()->index();          // المسؤول عن العلامة
            $t->string('website', 600)->nullable();             // الموقع الرسمي للعلامة
            $t->text('social')->nullable();                     // روابط الحسابات على منصات التواصل
            $t->json('services')->nullable();                   // المنتجات والخدمات المرتبطة بالعلامة
            $t->uuid('kit_id')->nullable();                     // ملفات الهوية (Brand Kit) في attachments
            $t->text('guidelines')->nullable();                 // إرشادات الاستخدام والممنوعات
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

        DB::statement('CREATE INDEX brands_company_status_idx ON brands (company_id, status)');
        DB::statement('CREATE INDEX brands_owner_status_idx ON brands (owner_id, status)');
    }

    public function down(): void
    {
        Schema::dropIfExists('brands');
    }
};
