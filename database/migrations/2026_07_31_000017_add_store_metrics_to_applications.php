<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * أرقام المتاجر على التطبيق. كان في الوحدة ~٤٠ حقلاً عن الروابط والشهادات
 * وأرقام البناء، ولا حقلَ واحداً عن أهمّ ثلاثة: التحميلات والتقييم وعدد
 * المراجعات — فمركز التطبيق يخبرك برقم Build ولا يخبرك أَحيٌّ هو أم ميت.
 * إضافةٌ محضة: أعمدة nullable، ولا حقل قائم يُمَسّ.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('applications', function (Blueprint $t) {
            $t->decimal('downloads', 16, 0)->nullable();     // إجمالي التحميلات/التثبيتات
            $t->decimal('rating', 3, 2)->nullable();         // متوسط التقييم (٠–٥)
            $t->decimal('reviews', 12, 0)->nullable();       // عدد المراجعات
        });
    }

    public function down(): void
    {
        Schema::table('applications', function (Blueprint $t) {
            $t->dropColumn(['downloads', 'rating', 'reviews']);
        });
    }
};
