<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * **مخزنُ النتائج الأمنية** (الطور ٤ · WP-4.1 · spec §2.3/§31/§34/§42.2 · قرار ق٤).
 *
 * صفٌّ واحد لكل (رمز فحص × كيان): يُفتح حين يُرصَد الشرط، ويتحدّث `last_seen_at`
 * مع كل تشغيل، ويُغلَق تلقائياً حين يزول الشرط — **بلا حذف**، فالعمرُ والتاريخ
 * (first_seen/resolved) هما جوهرُ السؤال «منذ متى ونحن مكشوفون؟».
 *
 * **قيمُ الكيان الحارسة لا NULL** (critic #6): نتيجةُ المنظّمة تُخزَّن بـ
 * `entity_type='org'` و`entity_id=''` — لأنّ NULL **متمايزٌ** في الفهرس الفريد
 * على MySQL وSQLite معاً، فلولا الحارستين لأدرج كلُّ تشغيلٍ صفّاً جديداً
 * لكل فحصٍ بلا كيان (تكرارٌ يوميّ صامت وأعمارٌ تتجدّد وإقراراتٌ تضيع).
 *
 * حالةُ حَوكمةٍ لا تليمتري: مُعلَنٌ في `HubBackup::RAW_TABLES`، والفريدُ
 * `sf_code_entity_unique` محروسٌ بالاسم في `MysqlPortabilityTest` (critic #39).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('security_findings')) return;

        Schema::create('security_findings', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->string('code', 60);                          // مفتاحُ الفحص (key في SecurityPosture::row) أو رمزُ كيانٍ لاحق
            $t->string('entity_type', 40)->default('org');   // org | user | token | secret… — الحارسة 'org' للمنظّمة
            $t->uuid('entity_id')->default('');              // معرّفُ الكيان — الحارسة '' للمنظّمة (critic #6)
            $t->string('severity', 12);                      // مفرداتُ Severity الخمس — من SEVERITY_BY_CODE لا من النبرة
            $t->string('title', 200);                        // label القائم في الفحص — لا نصَّ مُخترَعاً
            $t->text('description')->nullable();             // why القائم
            $t->json('evidence')->nullable();                // {n, value, tone, url…} — يمرّ بـRedactor فلا سرَّ فيه
            $t->text('remediation')->nullable();             // fix القائم — التوصيةُ الحتميّة
            $t->uuid('owner_id')->nullable();                // مالكُ المعالجة (يُسنَد لاحقاً — لا إسنادَ في هذه الحزمة)
            $t->string('status', 20)->default('open');       // open | acknowledged | resolved | ignored
            $t->timestamp('first_seen_at');                  // أولُ رصدٍ — يُحفَظ عبر كل التشغيلات
            $t->timestamp('last_seen_at');                   // آخرُ رصدٍ — يتحدّث كلَّ reconcile
            $t->timestamp('acknowledged_at')->nullable();    // الإقرارُ يعيش هنا (ق٤ — لا إقرارَ ثانياً في signal_states)
            $t->uuid('acknowledged_by')->nullable();
            $t->timestamp('resolved_at')->nullable();
            $t->uuid('company_id')->nullable();              // تنطيقُ الشركة لنتائج الكيانات — null = مستوى المنظّمة
            $t->string('request_id', 40)->nullable();        // ارتباطُ الطلب حين يكتب مسارٌ ويبيّ نتيجة
            $t->timestamps();

            $t->unique(['code', 'entity_type', 'entity_id'], 'sf_code_entity_unique');
            $t->index(['status', 'severity'], 'sf_status_severity_idx');
            $t->index('last_seen_at', 'sf_last_seen_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('security_findings');
    }
};
