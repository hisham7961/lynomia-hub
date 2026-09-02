<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * الصندوقُ الصادر يُعيد المحاولةَ آلياً (v2.399): عدّادُ محاولاتٍ وموعدُ الإعادة —
 * إضافيةٌ محروسة، والعاملُ يعمل قبلها كما كان (بلا إعادة).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('outbox')) return;
        Schema::table('outbox', function (Blueprint $t) {
            if (! Schema::hasColumn('outbox', 'attempts')) $t->unsignedTinyInteger('attempts')->default(0);
            if (! Schema::hasColumn('outbox', 'next_at')) $t->dateTime('next_at')->nullable()->index();
        });
    }

    public function down(): void
    {
        // إضافيةٌ فقط — لا تراجعَ مدمِّراً
    }
};
