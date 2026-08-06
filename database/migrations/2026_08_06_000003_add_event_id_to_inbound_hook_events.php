<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * حمايةُ إعادة التشغيل للويبهوك الوارد — **إضافةً لا كسراً**.
 *
 * نقطةُ الاستقبال سطحٌ عامٌّ يُوثَّق بتوقيع HMAC على الجسم. والتوقيعُ يُثبت أن
 * الجسمَ **صدر** من مالك السرّ، ولا يُثبت أنّه **لم يُعَد إرساله**: من التقط
 * الطلب (وسيطٌ، سجلُّ خادم، شبكةٌ مشتركة) يُعيده كما هو بلا معرفةِ السرّ،
 * فيتكرّر الحدثُ مرةً بعد مرة.
 *
 * العمودُ الجديد يحمل `X-Hub-Event-Id` حين يُرسله المُرسِل — وقيدٌ فريدٌ على
 * `(hook_id, event_id)` يجعل الإعادةَ بلا أثر عبر `insertOrIgnore`. والعقدُ
 * المنشور لا يتغيّر: مُرسِلٌ لا يُرسل الترويسةَ يبقى يعمل كما كان (‏NULL لا
 * يدخل الفهرسَ الفريد)، فلا يُكسر أحد.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('inbound_hook_events')) return;

        Schema::table('inbound_hook_events', function (Blueprint $t) {
            if (! Schema::hasColumn('inbound_hook_events', 'event_id')) {
                $t->string('event_id', 190)->nullable();
                $t->unique(['hook_id', 'event_id'], 'ihe_hook_event_unique');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('inbound_hook_events')) return;

        Schema::table('inbound_hook_events', function (Blueprint $t) {
            if (Schema::hasColumn('inbound_hook_events', 'event_id')) {
                $t->dropUnique('ihe_hook_event_unique');
                $t->dropColumn('event_id');
            }
        });
    }
};
