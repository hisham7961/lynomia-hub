<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * بصمةُ طلبٍ لمفتاح Idempotency: كان المفتاح يُطابَق بـ(token_id, ikey) وحدهما،
 * فطلبٌ ثانٍ بمفتاحٍ مُعاد وجسمٍ/مسارٍ مختلف يعيد ردَّ الأول ولا يُنشئ الثاني (فقدٌ
 * صامت). العمود يخزّن hash(method|path|body) فتُرفض إعادةُ المفتاح بطلبٍ مختلف.
 * إضافةٌ غير مُدمِّرة: nullable، والصفوف القديمة بلا بصمة تسلك المسار القديم.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('idempotency_keys', function (Blueprint $t) {
            $t->string('fingerprint', 64)->nullable()->after('ikey');
        });
    }

    public function down(): void
    {
        Schema::table('idempotency_keys', fn (Blueprint $t) => $t->dropColumn('fingerprint'));
    }
};
