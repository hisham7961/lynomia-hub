<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** CLM م3: بنود مهيكلة للقوالب والطلبات — nullable فالنص الحر القديم يبقى كما هو للأبد */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sign_templates', function (Blueprint $t) {
            if (! Schema::hasColumn('sign_templates', 'blocks')) $t->longText('blocks')->nullable();
        });
        Schema::table('sign_requests', function (Blueprint $t) {
            if (! Schema::hasColumn('sign_requests', 'blocks')) $t->longText('blocks')->nullable();
        });
    }

    public function down(): void {}
};
