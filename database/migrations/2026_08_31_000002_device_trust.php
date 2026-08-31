<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ثقة الأجهزة — أول هوية جهازٍ حقيقية في النظام (المرحلة ب من منصة الأمن).
 *
 * قبلها لم يكن للجهاز هويةٌ إلا سلسلة المتصفح (قابلة للانتحال برأس X-Device).
 * الآن كوكي جهازٍ عشوائيّ طويل العمر (httpOnly) يُربط بصفٍّ هنا لكل مستخدم:
 *  - أولُ ظهورٍ = «معلّق»، والمستخدم/المالك يرفعه إلى «موثوق» أو يبطله.
 *  - جلساتُ الدخول تُوسم بمعرّف الجهاز، فإبطالُ جهازٍ يبطل جلساتِه دفعةً.
 *  - حارسُ الدخول يكسب إشارةً جديدة: جهازٌ جديدٌ يرفع الخطر (المرحلة ج).
 *
 * بلا بصمةٍ غازية: كوكي مُوقَّعٌ ووسمُ متصفحٍ خفيف — لا Canvas ولا WebGL.
 * والعمود على sessions_log إضافيّ (nullable) فلا يكسر جلساتٍ قائمة.
 *
 * down() فارغة عمداً — لا هجرة مدمّرة.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('user_devices')) {
            Schema::create('user_devices', function (Blueprint $t) {
                $t->uuid('id')->primary();
                $t->uuid('user_id')->index();
                $t->string('cookie_hash', 64)->index();      // sha256 لقيمة الكوكي — لا الكوكي نفسه
                $t->string('label', 200)->nullable();         // وسمٌ يقرأه الإنسان (متصفح/نظام)
                $t->string('platform', 60)->nullable();
                $t->string('trust', 20)->default('معلّق');    // معلّق · موثوق · مبطَل
                $t->string('kind', 20)->nullable();           // شركة · شخصي (اختياري)
                $t->string('first_ip', 60)->nullable();
                $t->string('last_ip', 60)->nullable();
                $t->timestamp('first_seen_at')->nullable();
                $t->timestamp('last_seen_at')->nullable()->index();
                $t->timestamps();
                $t->softDeletes();
                $t->unique(['user_id', 'cookie_hash'], 'user_devices_uq');
            });
        }

        if (Schema::hasTable('sessions_log') && ! Schema::hasColumn('sessions_log', 'device_id')) {
            Schema::table('sessions_log', function (Blueprint $t) {
                $t->uuid('device_id')->nullable()->index();
            });
        }
    }

    public function down(): void
    {
        // لا هجرة مدمّرة
    }
};
