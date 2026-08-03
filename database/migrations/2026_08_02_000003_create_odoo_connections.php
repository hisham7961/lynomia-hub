<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * **اتصالات أودو المتعددة** — بعضُ المشاريع لها أودو خاص.
 *
 * كان الاتصالُ واحداً على مستوى النظام (مفاتيح `odoo.*` في الإعدادات)،
 * والطلبُ مزيج: مشاريعُ على خادم المنشأة، ومشاريعُ لكلٍّ منها خادمُها
 * وقاعدتُه. الإعداداتُ القديمة تبقى **الاتصالَ الافتراضي** — إضافةٌ لا كسر.
 *
 * المفتاح `key_cipher` يُخزَّن بغلاف EncryptedOrPlain كما في الخزنة —
 * تشفيرٌ عند الكتابة وفكٌّ عند القراءة.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('odoo_connections', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->string('name', 120);               // «أودو المصنع»، «أودو متجر كذا»…
            $t->string('url', 300);                // جذر الخادم — يُلحق به /jsonrpc
            $t->string('db', 120);
            $t->string('username', 200);           // بريد مستخدم القراءة
            $t->text('key_cipher')->nullable();    // مفتاح API — EncryptedOrPlain
            $t->boolean('active')->default(true);
            $t->text('notes')->nullable();
            $t->timestamp('last_ok_at')->nullable();     // آخر اختبار اتصالٍ ناجح
            $t->string('last_version', 60)->nullable();  // إصدار الخادم عند آخر نجاح
            $t->timestamps();
            $t->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('odoo_connections');
    }
};
