<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * عروض المشاريع — **القوالب القابلة للاستنساخ**.
 *
 * عمودٌ إضافيٌّ واحد `is_template`: عرضٌ مُعلَّمٌ قالباً يُستنسخ عرضاً جديداً
 * (نطاقاً وبنوداً ومراحل) بلا إعادة إدخال. لا جدولٌ ثانٍ — القالبُ عرضٌ
 * كسائر العروض، يُقصى فقط من خطّ المبيعات بالراية. إضافيٌّ محضٌ، nullable،
 * والقائمُ يعمل كما هو.
 *
 * down() فارغة عمداً — لا هجرة مدمّرة.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('quotes') && ! Schema::hasColumn('quotes', 'is_template')) {
            Schema::table('quotes', function (Blueprint $t) {
                $t->boolean('is_template')->default(false)->index();
            });
        }
    }

    public function down(): void
    {
        // لا هجرة مدمّرة
    }
};
