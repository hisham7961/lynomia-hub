<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * المرحلة ٤ — بنود المسيّر: كانت وحدة الرواتب عنواناً وإجمالياً يُكتب باليد،
 * بلا راتب موظفٍ واحد داخل المسيّر رغم جاهزية كل المدخلات (الرواتب والبدلات
 * في ملفات الموظفين). سطرٌ لكل موظف: أساسي، بدلات، إضافي، خصومات، سلف، صافٍ.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payroll_lines', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('run_id')->index();
            $t->uuid('emp_id')->index();
            $t->decimal('base', 16, 3)->default(0);
            $t->decimal('allow', 16, 3)->default(0);
            $t->decimal('overtime', 16, 3)->default(0);
            $t->decimal('deduct', 16, 3)->default(0);
            $t->decimal('advance', 16, 3)->default(0);
            $t->decimal('net', 16, 3)->default(0);
            $t->string('notes', 300)->nullable();
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payroll_lines');
    }
};
