<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** اختبار استعادة النسخ الاحتياطية — إثبات عملي أن النسخة قابلة للاستعادة فعلاً */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('restore_tests', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->string('title', 300);
            $t->date('test_date')->nullable()->index();           // تاريخ تنفيذ الاختبار
            $t->uuid('by_id')->nullable()->index();               // من نفّذ الاختبار
            $t->string('backup_name', 300)->nullable();           // اسم/معرّف النسخة المختبَرة
            $t->date('backup_date')->nullable()->index();         // تاريخ أخذ النسخة نفسها
            $t->unsignedInteger('size_mb')->nullable();           // حجم النسخة بالميجابايت
            $t->unsignedInteger('duration_min')->nullable();      // مدة الاستعادة بالدقائق
            $t->string('status', 60)->nullable()->index();        // نتيجة الاختبار
            $t->text('missing')->nullable();                      // الملفات أو الجداول التي لم تُستعد
            $t->boolean('encrypted')->default(false);             // هل النسخة مشفّرة
            $t->boolean('offsite')->default(false)->index();      // هل توجد نسخة خارج الخادم
            $t->string('offsite_loc', 300)->nullable();           // مكان النسخة الخارجية
            $t->uuid('server_id')->nullable()->index();
            $t->text('fix')->nullable();                          // الإجراء التصحيحي المطلوب
            $t->uuid('att_id')->nullable();
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

        DB::statement('CREATE INDEX restore_tests_status_date_idx ON restore_tests (status, test_date DESC)');
        DB::statement('CREATE INDEX restore_tests_server_date_idx ON restore_tests (server_id, test_date DESC)');
    }

    public function down(): void
    {
        Schema::dropIfExists('restore_tests');
    }
};
