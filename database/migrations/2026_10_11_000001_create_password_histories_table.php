<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * **سجلُّ كلمات المرور السابقة** (بندُ الدَّين #15 · AUTH-08 · `App\Support\Security\PasswordHistory`).
 *
 * تجزيءٌ فقط — هو نفسُه ما كان في `users.password` — لا نصَّ صريحاً. يُقصّ لكلِّ
 * مستخدمٍ إلى آخر `auth.pw_history` صفّاً عند كلِّ تدوين، والترتيبُ الدلاليُّ من
 * المعرّف التزايديّ لا من `created_at` (دقّةُ الثانية تتساوى).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('password_histories')) {
            Schema::create('password_histories', function (Blueprint $t) {
                $t->id();
                $t->uuid('user_id')->index();
                $t->string('password_hash', 255);
                $t->timestamp('created_at')->useCurrent();
            });
        }
    }

    public function down(): void
    {
        // إضافيّةٌ فقط (CLAUDE.md) — لا هدمَ في الرجوع
    }
};
