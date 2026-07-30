<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * أعمدة كان سجل الوحدات يعلنها لوحدة المستخدمين وهي غير موجودة في الجدول:
 * المسمى الوظيفي، وانتهاء صلاحية الحساب، وقائمة العناوين المسموحة —
 * فكان أي حفظ يمسّها ينهار، وأي عرض لها يخرج فارغاً.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $t) {
            if (! Schema::hasColumn('users', 'title')) $t->string('title', 160)->nullable();
            if (! Schema::hasColumn('users', 'expiry')) $t->date('expiry')->nullable()->index();
            if (! Schema::hasColumn('users', 'allowed_ip')) $t->string('allowed_ip', 400)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('users', fn (Blueprint $t) => $t->dropColumn(['title', 'expiry', 'allowed_ip']));
    }
};
