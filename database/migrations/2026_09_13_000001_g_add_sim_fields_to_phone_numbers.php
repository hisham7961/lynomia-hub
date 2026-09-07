<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * **تطويرُ `PhoneNumber` إلى أصل الاتصالات — حقولُ SIM/eSIM والخطة ودورةُ الحياة**
 * (Work OS · الطور G · WP-G.1 · §22/§24 · إضافةً لا كسراً).
 *
 * لا جدولَ SIM ثانٍ: الخطُّ الهاتفيّ القائمُ يكتسب هويّةَ الشريحةِ وباقتَها ودورةَ
 * حياتِها **على الجدول نفسِه** (`phone_numbers`) فيخدمها المحرّكُ العامّ بلا متحكّمٍ
 * خاصّ. كلُّ عمودٍ إضافيٌّ nullable ومحروسٌ بـ`hasColumn` — فالشيفرةُ المنشورةُ قبل
 * الترحيلِ لا تُسقط حفظاً، والترحيلُ المُعادُ لا يكسر.
 *
 *  • **هويّةُ الشريحة:** `line_type` (SIM/eSIM/…)، `iccid` (رقمُ الشريحةِ العالميّ —
 *    **فريدٌ**)، `imsi`، `msisdn` (رقمُ الخدمة)، `apn`. عرضُ كلٍّ معلَنٌ حرفيّاً هنا
 *    فيحرسه `ColumnFitsItsWriterTest` على صرامةِ MySQL، والكاتبُ (قواعدُ الوحدة)
 *    يرفض الفائضَ من عرض العمود نفسِه قبل بلوغِ القاعدة.
 *  • **الأسرار (مراجعُ خزنة):** `pin` و`puk` عمودا `text` مشفّران عبر
 *    `EncryptedOrPlain` (كـ`vault_secrets.secret_cipher`) — لا يُطبعان في HTML/CSV
 *    (يُقنَّعان ••••)، ويُكشفان عبر مسار `revealSecret` وحده بأثرِ «عرض حساس».
 *  • **الباقة:** `plan_name/plan_data/plan_voice/plan_sms/roaming/billing_cycle`.
 *  • **دورةُ الحياة:** `activated_at`/`deactivated_at` — لحظتا التفعيلِ والإيقاف؛
 *    والحالاتُ الأغنى تُضاف إلى قائمة `status` في `config/hub.php` (الخمسُ القديمةُ
 *    تبقى aliases — توافقٌ رجعيّ).
 *
 * **فهارس:** `UNIQUE(iccid)` (تفرّدُ الشريحةِ على المحرّكين)، `INDEX(line_type)`،
 * و`INDEX(status, expiry)` لقواعدِ التنبيهِ على انتهاءِ الخطوط (لا `whereDate` على
 * عمودٍ مُفهرَس، والترتيبُ يُستكمَل بـ`id` في القارئ).
 *
 * جدولُ `phone_numbers` مشمولٌ بالنسخةِ الاحتياطية أصلاً (وحدةُ `phones` في السجل)
 * فلا تغييرَ في `HubBackup`. توليدُ OpenAPI يتغيّر بحقولِ الوحدةِ الجديدة —
 * يُعيده المُكامِلُ (`php artisan hub:openapi`)، فهو خارجُ هذا الترحيل.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('phone_numbers')) return;

        Schema::table('phone_numbers', function (Blueprint $t) {
            // ── هويّةُ الشريحة ──
            if (! Schema::hasColumn('phone_numbers', 'line_type')) {
                $t->string('line_type', 16)->nullable();     // SIM فعليّة / eSIM / رقم ثابت …
            }
            // عرضُ الحقولِ النصّيّةِ الحرّةِ ≥ 60 (حارسُ `ColumnWidthGuardTest`: نصٌّ حرٌّ
            // فوق عمودٍ أضيقَ من ٦٠ يرفضه MySQL على مدخلٍ عاديّ). ICCID/IMSI/MSISDN
            // أقصرُ دلاليّاً بكثير، فالعرضُ سخيٌّ يتّسع لأيّ صيغةٍ ولا يقصُّ مشروعاً.
            if (! Schema::hasColumn('phone_numbers', 'iccid')) {
                $t->string('iccid', 60)->nullable();         // رقمُ الشريحةِ العالميّ (فريد)
            }
            if (! Schema::hasColumn('phone_numbers', 'imsi')) {
                $t->string('imsi', 60)->nullable();
            }
            if (! Schema::hasColumn('phone_numbers', 'msisdn')) {
                $t->string('msisdn', 60)->nullable();        // رقمُ الخدمة (E.164)
            }
            if (! Schema::hasColumn('phone_numbers', 'apn')) {
                $t->string('apn', 60)->nullable();
            }
            // ── الأسرار: مشفّرةٌ عبر EncryptedOrPlain (نمطُ vault_secrets.secret_cipher) ──
            if (! Schema::hasColumn('phone_numbers', 'pin')) {
                $t->text('pin')->nullable();                 // مشفّر — لا يُطبع، يُكشف عبر revealSecret
            }
            if (! Schema::hasColumn('phone_numbers', 'puk')) {
                $t->text('puk')->nullable();                 // مشفّر — كشفُه يتطلب تصعيدَ المصادقة
            }
            // ── الباقة ──
            if (! Schema::hasColumn('phone_numbers', 'plan_name')) {
                $t->string('plan_name', 80)->nullable();
            }
            if (! Schema::hasColumn('phone_numbers', 'plan_data')) {
                $t->string('plan_data', 60)->nullable();
            }
            if (! Schema::hasColumn('phone_numbers', 'plan_voice')) {
                $t->string('plan_voice', 60)->nullable();
            }
            if (! Schema::hasColumn('phone_numbers', 'plan_sms')) {
                $t->string('plan_sms', 60)->nullable();
            }
            if (! Schema::hasColumn('phone_numbers', 'roaming')) {
                $t->boolean('roaming')->default(false);
            }
            if (! Schema::hasColumn('phone_numbers', 'billing_cycle')) {
                $t->string('billing_cycle', 20)->nullable();
            }
            // ── دورةُ الحياة ──
            if (! Schema::hasColumn('phone_numbers', 'activated_at')) {
                $t->timestamp('activated_at')->nullable();
            }
            if (! Schema::hasColumn('phone_numbers', 'deactivated_at')) {
                $t->timestamp('deactivated_at')->nullable();
            }
        });

        // ── الفهارسُ ── محروسةٌ بـhasIndex ومغلَّفةٌ بـtry كي لا يكسر الترحيلُ إن
        // وُجد فهرسٌ باسمٍ آخر، ولا يُنشأ إلا بعد وجودِ أعمدتِه.
        $this->addUnique('phone_numbers', ['iccid'], 'phone_numbers_iccid_unique');
        $this->addIndex('phone_numbers', ['line_type'], 'phone_numbers_line_type_index');
        // (status, expiry) لقواعدِ التنبيهِ على انتهاءِ الخطوط — عمودان قائمان
        $this->addIndex('phone_numbers', ['status', 'expiry'], 'phone_numbers_status_expiry_index');
    }

    public function down(): void
    {
        if (! Schema::hasTable('phone_numbers')) return;

        foreach (['phone_numbers_iccid_unique', 'phone_numbers_line_type_index',
            'phone_numbers_status_expiry_index'] as $ix) {
            try {
                if (Schema::hasIndex('phone_numbers', $ix)) {
                    Schema::table('phone_numbers', fn (Blueprint $t) => $t->dropIndex($ix));
                }
            } catch (\Throwable $e) {
            }
        }

        Schema::table('phone_numbers', function (Blueprint $t) {
            foreach (['line_type', 'iccid', 'imsi', 'msisdn', 'apn', 'pin', 'puk',
                'plan_name', 'plan_data', 'plan_voice', 'plan_sms', 'roaming',
                'billing_cycle', 'activated_at', 'deactivated_at'] as $col) {
                if (Schema::hasColumn('phone_numbers', $col)) $t->dropColumn($col);
            }
        });
    }

    /** فهرسٌ عاديٌّ محروس — يُنشأ بعد وجودِ كلِّ أعمدتِه فقط */
    protected function addIndex(string $table, array $cols, string $name): void
    {
        foreach ($cols as $c) if (! Schema::hasColumn($table, $c)) return;
        try {
            if (Schema::hasIndex($table, $name)) return;
        } catch (\Throwable $e) {
        }
        try {
            Schema::table($table, fn (Blueprint $t) => $t->index($cols, $name));
        } catch (\Throwable $e) {
        }
    }

    /** فهرسٌ فريدٌ محروس — تفرّدُ ICCID على المحرّكين */
    protected function addUnique(string $table, array $cols, string $name): void
    {
        foreach ($cols as $c) if (! Schema::hasColumn($table, $c)) return;
        try {
            if (Schema::hasIndex($table, $name)) return;
        } catch (\Throwable $e) {
        }
        try {
            Schema::table($table, fn (Blueprint $t) => $t->unique($cols, $name));
        } catch (\Throwable $e) {
        }
    }
};
