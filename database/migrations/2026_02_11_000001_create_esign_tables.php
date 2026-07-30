<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * التوقيع الإلكتروني للعقود:
 *  - sign_templates: قوالب عقود بمتغيرات {اسم_العميل} تُملأ عند الإنشاء.
 *  - sign_requests: طلب توقيع برابط خاص وكلمة سر — يفتح الوثيقة، يرسم التوقيع،
 *    ويُحفظ مع IP وبيانات الجهاز ووقت التوقيع، وتُنبَّه الإدارة عند الفتح والتوقيع.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sign_templates', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->string('name', 160);
            $t->string('kind', 80)->nullable();       // نوع العقد: خدمات، NDA، توريد…
            $t->longText('body');                     // نص العقد مع متغيرات {var}
            $t->timestamps();
        });

        Schema::create('sign_requests', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->string('title', 200);
            $t->uuid('template_id')->nullable();
            $t->uuid('contract_id')->nullable()->index();   // يرحّل لملف العقد في وحدة العقود
            $t->longText('body');                     // النص النهائي بعد ملء المتغيرات
            $t->string('pass');                       // كلمة سر الرابط (مجزأة)
            $t->string('token', 64)->unique();
            $t->string('status', 40)->default('بانتظار التوقيع');
            $t->string('signer_name', 160)->nullable();
            $t->longText('signature')->nullable();    // صورة التوقيع المرسومة (dataURL)
            $t->unsignedInteger('opens')->default(0);
            $t->timestamp('opened_at')->nullable();
            $t->timestamp('signed_at')->nullable();
            $t->string('signed_ip', 45)->nullable();
            $t->string('signed_agent', 250)->nullable();
            $t->string('signed_locale', 60)->nullable();
            $t->uuid('created_by')->nullable();
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sign_requests');
        Schema::dropIfExists('sign_templates');
    }
};
