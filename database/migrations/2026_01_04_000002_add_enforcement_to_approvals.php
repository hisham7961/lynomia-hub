<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** أعمدة تنفيذ الموافقات المُلزِمة: العملية المؤجلة وحمولتها ومن طلب ومن حسم */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('approvals', function (Blueprint $t) {
            $t->string('mod', 60)->nullable()->index();      // وحدة العملية المحمية
            $t->uuid('record_id')->nullable()->index();      // السجل الهدف
            $t->string('op', 4)->nullable();                 // e=تعديل · d=حذف
            $t->json('payload')->nullable();                 // مدخلات التعديل المؤجلة
            $t->uuid('requested_by')->nullable()->index();
            $t->uuid('decided_by')->nullable();
            $t->timestamp('decided_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('approvals', function (Blueprint $t) {
            $t->dropColumn(['mod', 'record_id', 'op', 'payload', 'requested_by', 'decided_by', 'decided_at']);
        });
    }
};
