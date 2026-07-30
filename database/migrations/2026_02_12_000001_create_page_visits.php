<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * سجل تنقّل المستخدمين داخل النظام — لمركز نشاط الموظفين:
 * أي صفحة فُتحت ومتى. منه تُشتق ساعات العمل الفعلية (أول ظهور، آخر ظهور،
 * دقائق النشاط) ومسار التنقل. داخل النظام فقط — لا يرى شيئاً خارج النظام.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('page_visits', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('user_id');
            $t->string('path', 190);
            $t->string('route', 120)->nullable();
            $t->timestamp('at');
            $t->index(['user_id', 'at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('page_visits');
    }
};
