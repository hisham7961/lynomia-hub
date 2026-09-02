<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * أسرارُ HMAC للويبهوك الصادر والوارد صارت تُخزَّن مشفَّرةً (v2.399): الغلافُ المشفَّر
 * (~٣٠٠ حرف) أطولُ من عرض العمود القديم — وMySQL يرفض بـ22001 حيث تمرّ SQLite صامتةً.
 * توسيعٌ إضافيّ إلى TEXT؛ القيمُ القديمة الصريحة تبقى مقروءةً (EncryptedOrPlain).
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['webhooks', 'inbound_hooks'] as $table) {
            if (! Schema::hasTable($table) || ! Schema::hasColumn($table, 'secret')) continue;
            try {
                Schema::table($table, fn (Blueprint $t) => $t->text('secret')->nullable()->change());
            } catch (\Throwable $e) {
                // محرّكٌ لا يقبل التغيير في المكان — لا نكسر الترحيل؛ يُقال في hub:schema-check
            }
        }
    }

    public function down(): void
    {
        // إضافيةٌ فقط
    }
};
