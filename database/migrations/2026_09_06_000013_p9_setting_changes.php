<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * (WP-9.2 · ق٨ · spec §7.6 · §31) **تاريخُ الإعداد** — صفٌّ لكل مفتاحٍ تغيّر.
 *
 * سؤالُ §48 «من غيّر هذا المفتاح آخرَ مرة؟» كان بلا جواب. و`audits` وحدَها لا
 * تسعه: القيدُ **واحدٌ لدفعةٍ كاملة** (المفاتيحُ مجموعةً في `after._keys`)،
 * والاسمُ مقصوصٌ بعرض عموده، و`audits.record_id` من نوع `uuid` فلا يسع مفتاحاً
 * نصّياً مثل `security.freeze_exports`. فلا استعلامَ يقول «تاريخُ هذا المفتاح»
 * بلا مسحِ جدولِ التدقيق كلِّه وفكِّ JSON لكل صفّ.
 *
 * فهذا **جدولُ إسقاطٍ** (projection) بجانب التدقيق لا بديلاً عنه: `audits`
 * تبقى الحقيقةَ المختومة، وكلُّ صفٍّ هنا يحمل `audit_id` يعود إليها.
 *
 * **والأسرارُ مبصومةٌ لا مخزَّنة:** `before`/`after` لمفتاحٍ حسّاس بصمةُ
 * `Redactor::fingerprint` (‏`sha256:…`) — فتاريخُ التغيير يقول «تبدّلت» ولا
 * يقول «إلى ماذا». الكاتبُ الوحيد `App\Support\Settings::put`.
 *
 * الأعمدةُ النصّية بعرضٍ صريح وكتلة `Schema::create` حرفيةٌ لا حلقة — فيراها
 * `hub_col_widths()` وحرّاسُ العرض. ويُعلَن الجدولُ في `HubBackup::RAW_TABLES`.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('setting_changes')) return;

        Schema::create('setting_changes', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->string('key', 120);                        // مفتاحُ الإعداد — أطولُ مفتاحٍ حيّ ٣٠ حرفاً، والعرضُ يتّسع للنمو
            $t->json('before')->nullable();                // القيمةُ قبلَه (null = لا صفَّ له أصلاً) — مبصومةٌ إن كان سرّاً
            $t->json('after')->nullable();                 // القيمةُ بعدَه (null = حُذف الصفُّ فعاد للافتراضي)
            $t->uuid('user_id')->nullable();               // من غيّر — null لأمر طرفيةٍ أو مجدولٍ لا مستخدمَ له
            $t->string('source', 20);                      // screen · messaging · odoo · n8n · security · ops · cli · import · restore · demo
            $t->string('request_id', 40)->nullable();      // للربط بأثر الطلب (system.trace) وبقيد التدقيق نفسِه
            $t->unsignedBigInteger('audit_id')->nullable(); // القيدُ المختوم الذي يحمل هذه الدفعة
            $t->string('reason', 300)->nullable();          // لماذا — حين يقولها الكاتب (استعادة/استيراد/طوارئ)
            $t->dateTime('created_at');

            // «تاريخُ هذا المفتاح» و«آخرُ ما تغيّر في النظام» — القارئان الوحيدان
            $t->index(['key', 'created_at'], 'setting_changes_key_at_idx');
            $t->index(['created_at'], 'setting_changes_at_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('setting_changes');
    }
};
