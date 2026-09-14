<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * **علمُ الكلمةِ المؤقّتة** (محاكاة الجولة 2 · G16).
 *
 * الواجهةُ تَعِد عند فتحِ حسابِ موظّفٍ جديد «سيُطلب منه تبديلُها عند أوّل دخول»،
 * والوعدُ لم يكن مُنفَّذاً: دخل الموظّفُ وعمل بكلمةٍ سلّمها له غيرُه بيده.
 *
 * ولمَ عمودٌ صريحٌ لا تخمينٌ من `password_changed_at`؟ لأنّ تلك فارغةٌ كذلك
 * لعضوِ بوّابةِ عميلٍ لم يُفعَّل بعد ولصفوفٍ تاريخيّةٍ سبقت العمود — فالبناءُ
 * عليها يحبس من لم يُخطئ ويكسر دخولاً قائماً. عمودٌ **مُضافٌ لا مُدمِّر**،
 * افتراضُه `false`: كلُّ حسابٍ قائمٍ يبقى على حاله حرفياً، والوسمُ يقع على
 * الحساباتِ التي تُفتح بكلمةٍ مؤقّتة بعد اليوم وحدَها.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('users') || Schema::hasColumn('users', 'must_change_password')) return;

        Schema::table('users', function (Blueprint $t) {
            $t->boolean('must_change_password')->default(false);
        });
    }

    public function down(): void
    {
        if (Schema::hasTable('users') && Schema::hasColumn('users', 'must_change_password')) {
            Schema::table('users', fn (Blueprint $t) => $t->dropColumn('must_change_password'));
        }
    }
};
