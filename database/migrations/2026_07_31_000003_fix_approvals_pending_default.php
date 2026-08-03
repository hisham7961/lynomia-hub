<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * جولة تدقيق ٦ — الموافقة المولودة ميتة: افتراضي العمود كان 'pending'
 * الإنجليزية بينما كل المنطق (pending()، الموجز الصباحي، بوابة /me،
 * حالات الإغلاق) يطابق «معلّق» — فسجلٌ يُنشأ من نموذج /m/approvals
 * لا يراه أي محرك. توحيدٌ للقيمة القائمة والافتراضي معاً.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('approvals')->where('status', 'pending')->update(['status' => 'معلّق']);
        Schema::table('approvals', function (Blueprint $t) {
            $t->string('status', 30)->default('معلّق')->change();
        });
    }

    public function down(): void
    {
        Schema::table('approvals', function (Blueprint $t) {
            $t->string('status', 30)->default('pending')->change();
        });
    }
};
