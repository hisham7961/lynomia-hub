<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * المرحلة ٥ — صدق مقياس «أول رد»: كان يأخذ أول تعليقٍ أياً كان، فملاحظةٌ
 * داخلية بين موظفَين توقف عدّاد الاستجابة دون أن يصل العميلَ شيء — ومؤشرا
 * «متوسط أول رد» و«نسبة الالتزام» متفائلان بنيوياً. علمٌ منطقي على التعليق
 * يفصل الملاحظة الداخلية عن الرد المرئي للعميل.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('comments', 'internal')) return;
        Schema::table('comments', function (Blueprint $t) {
            $t->boolean('internal')->default(false)->index();
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('comments', 'internal')) return;
        Schema::table('comments', function (Blueprint $t) {
            $t->dropColumn('internal');
        });
    }
};
