<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * **أثرُ رموز API + ختمُ تدوير الأسرار** (الطور ٤ · WP-4.5 · spec §2.9/§2.10 · إضافةً لا كسراً).
 *
 * `api_tokens` كان بلا ذاكرةِ مكان (من أين استُعمل المفتاح آخرَ مرة؟) وبلا
 * إبطالٍ ناعم: إبطالُ صاحب الرمز **يحذف الصفَّ حذفاً صلباً** فيختفي من كل
 * مراجعة، والمالكُ لا يملك سكّةَ إبطالٍ أصلاً. الأعمدةُ الجديدة تفكّ الاثنين:
 * `last_ip` يكتبه `ApiAuth` بخنق الدقيقة القائم لـ`last_used_at` نفسِه (لا
 * كتابةَ لكل طلب)، و`revoked_at/by` يختمهما الإبطالُ الإداريّ فيبقى الصفُّ
 * شاهداً («مُبطَل، متى، ومَن») — و`ApiAuth` يرفض المُبطَل برمز 401.
 *
 * و`vault_secrets.rotated_at` يفكّ كذبةَ `updated_at`: تعديلُ **ملاحظةٍ** كان
 * «يجدّد» السرَّ في فحص التدوير وهو بائت. يُختم في `VaultSecret::booted` حين
 * يتغيّر `secret_cipher` **فقط** — والقديمُ يبقى null بلا اختلاقِ تاريخٍ رجعيّ
 * (عمرُه يُقرأ من `created_at` عندئذٍ، وهو أصدقُ من آخرِ تعديلِ ملاحظة).
 *
 * الأعمدةُ كلُّها اختيارية والكتّابُ يمرّون بحارس `hub_has_col` — نشرُ الكود
 * قبل الهجرة يُنقص ميزةً ولا يكسر المصادقة. كتلُ Schema حرفيّةٌ لا حلقة
 * (critic #35) — فيرى `ColumnWidthGuardTest` عرضَ كلِّ عمودٍ نصّي.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('api_tokens')) {
            Schema::table('api_tokens', function (Blueprint $t) {
                // آخرُ عنوانٍ استُعمل منه المفتاح — بعرض عمود audits.ip نفسِه (٦٠)
                if (! Schema::hasColumn('api_tokens', 'last_ip')) $t->string('last_ip', 60)->nullable();
                // متى أُبطل إدارياً؟ null = سارٍ — ApiAuth يرفض غيرَ الفارغ بـ401
                if (! Schema::hasColumn('api_tokens', 'revoked_at')) $t->timestamp('revoked_at')->nullable();
                // مَن أبطله؟ (المالكُ المنفّذ) — أثرُ المساءلة مع قيد التدقيق
                if (! Schema::hasColumn('api_tokens', 'revoked_by')) $t->uuid('revoked_by')->nullable();
            });
        }

        if (Schema::hasTable('vault_secrets')) {
            Schema::table('vault_secrets', function (Blueprint $t) {
                // آخرُ تدويرٍ فعليّ للسرّ — يُختم عند تغيّر secret_cipher فقط
                if (! Schema::hasColumn('vault_secrets', 'rotated_at')) $t->timestamp('rotated_at')->nullable();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('api_tokens')) {
            Schema::table('api_tokens', function (Blueprint $t) {
                foreach (['last_ip', 'revoked_at', 'revoked_by'] as $c) {
                    if (Schema::hasColumn('api_tokens', $c)) $t->dropColumn($c);
                }
            });
        }
        if (Schema::hasTable('vault_secrets')) {
            Schema::table('vault_secrets', function (Blueprint $t) {
                if (Schema::hasColumn('vault_secrets', 'rotated_at')) $t->dropColumn('rotated_at');
            });
        }
    }
};
