<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * جولة تدقيق ٦ — توحيد مرادفات خطة العمل: الخيارات كانت ملوّثة بمرادفين
 * («نجح»/«ناجح»، «قيد العمل»/«قيد التطوير»، «مكتمل»/«منشورة») فبندٌ حالته
 * «نجح» لا يُحتسب ناجحاً في نسبة الإنجاز (hub_progress يطابق «ناجح») —
 * تسريبٌ صامت في تقدم كل مشروع. ترحيل قيمٍ صرف قابل للعكس.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('plan_items')) return;
        DB::table('plan_items')->where('status', 'قيد العمل')->update(['status' => 'قيد التطوير']);
        DB::table('plan_items')->where('status', 'مكتمل')->update(['status' => 'منشورة']);
        DB::table('plan_items')->where('test', 'نجح')->update(['test' => 'ناجح']);
    }

    public function down(): void
    {
        // المرادفات القديمة لا يُعاد إنتاجها — التوحيد باقٍ عمداً
    }
};
