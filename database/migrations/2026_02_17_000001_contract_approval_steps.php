<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * CLM المرحلة ٥ (v2.121) — مراحل موافقات العقود قبل الإرسال للتوقيع.
 * جدولٌ خاص بالعقود معزول كلياً عن محرك الموافقات العام (hub_needs_approval)
 * فلا يمس مساره الحرج ولا الوحدات الإحدى والسبعين. الافتراضي بلا مراحل = لا أثر.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('contract_approval_steps')) return;

        Schema::create('contract_approval_steps', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('request_id')->index();
            $t->uuid('contract_id')->nullable()->index();
            $t->unsignedInteger('stage')->default(1);
            $t->string('kind', 40)->default('مخصصة');        // مالية / قانونية / مخصصة
            $t->string('label', 160)->nullable();
            $t->uuid('approver_id')->nullable()->index();     // فارغ = قرار المالكين
            $t->string('status', 30)->default('بانتظار');     // بانتظار / موافق / مرفوض
            $t->uuid('decided_by')->nullable();
            $t->timestamp('decided_at')->nullable();
            $t->string('note', 400)->nullable();
            $t->timestamps();
            $t->index(['request_id', 'stage']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contract_approval_steps');
    }
};
