<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** صندوق الوثائق الوارد: ارفع أولاً وصنّف لاحقاً */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inbox_documents', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->string('path', 300);
            $t->string('orig', 200);
            $t->unsignedBigInteger('size')->default(0);
            $t->string('note', 400)->nullable();          // ملاحظة الرافع: «فاتورة وصلت بالبريد»
            $t->uuid('uploaded_by')->nullable();
            $t->string('status', 10)->default('وارد');    // وارد | مصنف
            // حقول التصنيف — تُملأ لاحقاً
            $t->string('module', 40)->nullable()->index();
            $t->uuid('record_id')->nullable();
            $t->uuid('company_id')->nullable();
            $t->string('party', 160)->nullable();         // الجهة: مورد/عميل/حكومة…
            $t->string('kind', 60)->nullable();           // النوع: فاتورة/عقد/رخصة/مراسلة…
            $t->date('doc_date')->nullable();
            $t->date('expiry')->nullable()->index();
            $t->uuid('classified_by')->nullable();
            $t->dateTime('classified_at')->nullable();
            $t->dateTime('created_at');
            $t->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inbox_documents');
    }
};
