<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * المرحلة ٢ — سطور القيود المزدوجة: كان القيد رأساً بلا جسم — لا مدين ولا
 * دائن ولا حساب، فلا يوازن قيدٌ ولا يُشتق ميزان مراجعة. هذه الحلقة المفقودة
 * بين «الفوترة» و«المحاسبة»، وبوابة القوائم المالية المعلن غيابها في README.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('journal_lines', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('entry_id')->index();
            $t->uuid('acc_id')->nullable()->index();     // دليل الحسابات ledger_accounts
            $t->uuid('cc_id')->nullable()->index();      // مركز التكلفة
            $t->decimal('debit', 16, 3)->default(0);
            $t->decimal('credit', 16, 3)->default(0);
            $t->string('memo', 300)->nullable();
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('journal_lines');
    }
};
