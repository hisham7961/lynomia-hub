<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * **محرّكُ التنبيه (WP-6.3) — نافذة/شدّة/تبريد/حالة.**
 *
 * ١) توسيعُ `alert_rules` بأعمدةٍ nullable (إضافةً لا كسراً): الشدّةُ والمجالُ
 *    والمصدرُ المسمّى ونافذةُ التقييم والتبريدُ وفتحُ الحادثة الآليّ — قاعدةٌ
 *    قديمةٌ بلا هذه الأعمدة تعمل كما كانت حرفياً (المحرّكُ اليوميّ لا يقرؤها).
 *
 * ٢) جدولٌ جديد `alert_instances`: **ذاكرةُ التنبيه التي تنجو من التقليم** —
 *    ذاكرةُ التكرار اليومَ هي `notifications_hub.kind='rule:<id>'` وهي تُقلَّم
 *    بعد ٩٠/٣٦٥ يوماً في `pruneNotifications` ⇒ التكرارُ والتصعيدُ يفقدان
 *    ذاكرتَهما صامتَين. هنا صفٌّ واحد لكل شرطٍ حيّ (`dedup_key` فريد)، يكبر
 *    عدّادُه مع كل رصدٍ ويُقرّ ويُحلّ — ولا يُحذف أبداً (الحلُّ حالةٌ لا مسح).
 *
 * الكتلُ **حرفيّةٌ محروسة** (critic #35): `hub_col_widths()` يقرأ مصدرَ الهجرات
 * ويطابق `Schema::create/table('<table>'` — عمودٌ داخل حلقةٍ غيرُ مرئيٍّ لحرّاس
 * العرض (`ColumnWidthGuardTest`/`ColumnFitsItsWriterTest`). والأعمدةُ النصّية
 * كلُّها بعرضٍ صريح، والكاتبُ يقصّ بـ`mb_substr` قبل الكتابة (critic #36).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('alert_rules')) {
            Schema::table('alert_rules', function (Blueprint $t) {
                // الشدّةُ المعلنة للقاعدة (حرج/عالي/متوسط/منخفض) — تُطبَّع بسلّم Severity عند الكتابة
                if (! Schema::hasColumn('alert_rules', 'severity')) $t->string('severity', 12)->nullable();
                // المجال: module|security|system|error|quality|execution
                if (! Schema::hasColumn('alert_rules', 'domain')) $t->string('domain', 24)->nullable();
                // المصدرُ المسمّى للقواعد النافذية (مثل security.failed_logins) — فارغٌ للقواعد الحقلية القديمة
                if (! Schema::hasColumn('alert_rules', 'source')) $t->string('source', 80)->nullable();
                // نافذةُ التقييم بالدقائق («X خلال Y دقيقة») — فارغةٌ = قاعدةٌ يومية كما كانت
                if (! Schema::hasColumn('alert_rules', 'window_min')) $t->unsignedInteger('window_min')->nullable();
                // تبريدُ الإشعار بالدقائق — فارغٌ = security.alert_cooldown_min (افتراضاً ٦٠)
                if (! Schema::hasColumn('alert_rules', 'cooldown_min')) $t->unsignedInteger('cooldown_min')->nullable();
                // فتحُ حادثةٍ تشغيلية آلياً عند الإطلاق (يمرّ بـIncident::create/HubEvents)
                if (! Schema::hasColumn('alert_rules', 'auto_incident')) $t->boolean('auto_incident')->nullable();
            });
        }

        if (! Schema::hasTable('alert_instances')) {
            Schema::create('alert_instances', function (Blueprint $t) {
                $t->bigIncrements('id');
                $t->uuid('rule_id')->nullable()->index();          // قاعدةُ المصدر — قد تُحذف والذاكرةُ تبقى
                $t->string('dedup_key', 191);                      // مفتاحُ عدم التكرار — فريدٌ أدناه باسمٍ يحرسه MysqlPortabilityTest
                $t->string('domain', 24)->nullable();              // security|system|error|…
                $t->string('module', 60)->nullable();
                $t->uuid('record_id')->nullable();
                $t->string('subject', 120)->nullable();            // IP أو مستخدمٌ للنوافذ (bdi mono ltr في العرض)
                $t->string('severity', 12)->nullable();            // بسلّم Severity الواحد (critical|high|…)
                $t->string('title', 300);                          // mb_substr عند الكاتب (critic #36) — لا اعتماد على بتر SQLite
                $t->string('status', 16)->default('triggered');    // triggered → acknowledged → resolved
                $t->dateTime('first_at');
                $t->dateTime('last_at');
                $t->unsignedInteger('count')->default(1);
                $t->dateTime('notified_at')->nullable();           // آخرُ إشعارٍ فعليّ — عليه يُحسب التبريد
                $t->uuid('acknowledged_by')->nullable();           // **الإقرارُ الدائم هنا وحدَه** (ق٣ + critic #5) — لا صفَّ ثانٍ في signal_states
                $t->dateTime('acknowledged_at')->nullable();
                $t->dateTime('resolved_at')->nullable();
                $t->uuid('incident_id')->nullable();
                $t->uuid('company_id')->nullable();
                $t->string('request_id', 40)->nullable();
                $t->timestamps();

                $t->unique('dedup_key', 'ai_dedup_unique');
                $t->index(['status', 'severity', 'last_at'], 'ai_status_sev_last_idx');
                $t->index('incident_id', 'ai_incident_idx');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('alert_instances');
        if (Schema::hasTable('alert_rules')) {
            Schema::table('alert_rules', function (Blueprint $t) {
                foreach (['severity', 'domain', 'source', 'window_min', 'cooldown_min', 'auto_incident'] as $c) {
                    if (Schema::hasColumn('alert_rules', $c)) $t->dropColumn($c);
                }
            });
        }
    }
};
