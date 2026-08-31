<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * **سقفُ الرفع غيغابايت — على التثبيت القائم لا الجديد وحده.**
 *
 * رفعُ الافتراضي في `CoreSeeder` إلى ١٠٤٨٥٧٦ ك.ب لا يصل إلا تثبيتاً جديداً:
 * الخادمُ القائم زُرعت قيمتُه يومَ التثبيت (٢٠٤٨٠٠ ك.ب = ٢٠٠ م.ب) وبقيت في
 * جدول `settings`، والنشرُ لا يعيد الزرع — فالحدُّ المطلوبُ «غيغابايت للمشروع
 * كامل» كان سيبقى وعداً في الشيفرة لا يراه الخادمُ الحيّ أبداً.
 *
 * فتُرفَع القيمةُ المخزونة إلى ١ غ.ب حيث كانت دونه — والطلبُ صريحٌ أن يعمّ
 * المشروعَ كلَّه، ومركزُ الإعدادات يبقى بابَ من أراد خفضَه بعدها. قيمةٌ أعلى
 * من غيغابايت (إن وُجدت) لا تُمَسّ: الهجرةُ ترفع سقفاً لا تخفضه.
 */
return new class extends Migration
{
    /** الحدّ المطلوب بالكيلوبايت — ١ غيغابايت */
    public const GB_KB = 1048576;

    public function up(): void
    {
        if (! Schema::hasTable('settings')) {
            return;
        }

        // المقارنة في PHP لا في SQL: CAST يختلف لفظُه بين المحرّكين، والصفُّ
        // واحدٌ أصلاً فلا كلفةَ لقراءته. والعمودُ الخام قد يحمل الرقمَ عارياً
        // (زرعُ الأعداد) أو داخل اقتباسَي JSON (كتابةُ النموذج بكاست array
        // لقيمةٍ نصّية) — فيُفكّ JSON أولاً وإلا صار `(int)` على `"204800"` صفراً.
        $raw = DB::table('settings')->where('key', 'files.max_kb')->value('value');
        if ($raw === null) {
            return;
        }
        $decoded = json_decode((string) $raw, true);
        $kb = (int) (is_scalar($decoded) ? $decoded : $raw);
        if ($kb < self::GB_KB) {
            DB::table('settings')->where('key', 'files.max_kb')
                ->update(['value' => (string) self::GB_KB]);
        }

        // الإعداداتُ تُقرأ من كاشٍ عمرُه عشرُ دقائق — بلا مسحِه يبقى الحدُّ
        // القديمُ معروضاً ومفروضاً حتى بعد الهجرة.
        Cache::forget('settings:all');
    }

    public function down(): void
    {
        // رفعُ سقفٍ لا يُرتجَع آلياً: لا نعرف القيمةَ التي كانت، وخفضٌ أعمى
        // قد يكسر رفعاً جارياً. الخفضُ — إن أُريد — من مركز الإعدادات.
    }
};
