<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * (الطور B · WP-B.1 · §12) تفعيلُ حساب العميل الآمن — سجلُّ التفعيلِ الواحد.
 *
 * حسابُ العميل يُنشأ **بلا كلمةِ سرٍّ صالحةٍ للدخول**، ثم يُرسَل له رابطُ تفعيلٍ
 * حاملٌ رمزَ تحقّقٍ سداسيّاً (على نمطِ OTP التوقيع الإلكتروني: hash+expire+
 * single-use — EsignController). كلُّ ما يخصّ تفعيلاً واحداً في صفٍّ واحد:
 * الرمزُ الخام يُجزَّأ (`token_hash` = SHA-256 hex) فلا يُخزَّن صريحاً، والرمزُ
 * السداسيّ يُجزَّأ (`otp_hash`) وينتهي (`otp_expires_at`) ويُقيَّد بعدّاد
 * `attempts`، ثم يُستهلَك مرّةً (`consumed_at`) فلا يُعاد.
 *
 * **لا كلمةَ سرٍّ هنا البتّة**: لا عمودَ لها ولا نصَّ يحملها — العميلُ يضعها بنفسه
 * في خطوةِ الوضع، فتُكتب على `users.password` وحدَها. (قاعدةُ أمنِ الطور B.)
 *
 * إضافيّةٌ محروسة (hasTable): جدولٌ جديدٌ لا يمسّ قائماً. الأعرضُ معلَنةٌ حرفيّاً
 * — `token_hash` بعرضِ SHA-256 (٦٤)، والبريدُ ١٩٠، والعنوانُ ٤٥ (IPv6) —
 * فيسعُها كاتبُها على MySQL الصارم (درسُ ColumnFitsItsWriter).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('account_activations')) return;

        Schema::create('account_activations', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('user_id');
            $t->string('email', 190);
            // SHA-256 hex للرمزِ الخام في الرابط — لا يُخزَّن الرمزُ صريحاً
            $t->string('token_hash', 64);
            // تجزيءُ الرمز السداسيّ (bcrypt ~٦٠، والعرضُ ٢٥٥ احتياطاً لأيّ خوارزمية).
            // nullable: يُفرَّغ لحظةَ استهلاكِ الرمز مرّةً واحدة (single-use) فلا يُعاد التحقّقُ به.
            $t->string('otp_hash', 255)->nullable();
            $t->timestamp('otp_expires_at')->nullable();
            $t->timestamp('consumed_at')->nullable();
            $t->string('ip', 45)->nullable();     // IPv6 يبلغ ٤٥ محرفاً
            $t->integer('attempts')->default(0);
            $t->timestamps();

            $t->unique('token_hash');             // الرمزُ الخامُّ مفتاحٌ فريد — لا رمزين متطابقين
            $t->index('user_id');                 // «تفعيلاتُ هذا المستخدم»
            $t->index('otp_expires_at');           // كنسُ المنتهية
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('account_activations');
    }
};
