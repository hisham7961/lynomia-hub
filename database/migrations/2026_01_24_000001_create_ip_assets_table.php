<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** الملكية الفكرية — براءات الاختراع والعلامات التجارية وحقوق النشر والأكواد والتصاميم والتراخيص */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('ip_assets', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->string('title', 300);                          // عنوان الأصل الفكري
            $t->string('type', 80)->nullable()->index();       // نوع الأصل (براءة/علامة/حق نشر…)
            $t->uuid('company_id')->nullable()->index();       // الشركة المالكة
            $t->string('reg_no', 120)->nullable()->index();    // رقم التسجيل الرسمي
            $t->string('authority', 200)->nullable();          // جهة التسجيل (وزارة التجارة، WIPO…)
            $t->string('country', 120)->nullable()->index();   // دولة التسجيل
            $t->date('reg_date')->nullable()->index();         // تاريخ التسجيل
            $t->date('expiry')->nullable()->index();           // تاريخ الانتهاء — يغذّي تنبيهات التجديد
            $t->boolean('auto_renew')->default(false)->index(); // التجديد التلقائي
            $t->uuid('author_id')->nullable()->index();        // المخترع أو المؤلف
            $t->uuid('project_id')->nullable()->index();       // المشروع المرتبط
            $t->text('descr')->nullable();                     // وصف الأصل ونطاق الحماية
            $t->decimal('cost', 16, 3)->nullable();            // تكلفة التسجيل
            $t->string('currency', 80)->nullable()->index();
            $t->uuid('att_id')->nullable();                    // المستندات (شهادة التسجيل وغيرها)
            $t->string('status', 80)->nullable()->index();
            $t->text('notes')->nullable();
            $t->json('custom')->nullable();
            $t->json('meta')->nullable();
            $t->integer('version')->default(1);
            $t->boolean('archived')->default(false)->index();
            $t->uuid('created_by')->nullable()->index();
            $t->uuid('updated_by')->nullable();
            $t->timestamps();
            $t->softDeletes();
        });

        DB::statement('CREATE INDEX ip_assets_status_expiry_idx ON ip_assets (status, expiry)');
        DB::statement('CREATE INDEX ip_assets_company_expiry_idx ON ip_assets (company_id, expiry)');
        DB::statement('CREATE INDEX ip_assets_proj_created_idx ON ip_assets (project_id, created_at DESC)');
    }

    public function down(): void
    {
        Schema::dropIfExists('ip_assets');
    }
};
