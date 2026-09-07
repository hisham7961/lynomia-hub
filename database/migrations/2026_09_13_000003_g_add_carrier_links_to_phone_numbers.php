<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * **روابطُ أصلِ الاتصالات: مزوّدٌ / موظفٌ / جهازٌ / محطةٌ / عميل**
 * (Work OS · الطور G · WP-G.2 · §22/§23/§28 · إضافةً لا كسراً).
 *
 * الخطُّ القائمُ يكتسب مراجعَه على السكّةِ نفسِها — لا جدولَ ربطٍ ثانٍ. كلُّ عمودٍ
 * `uuid` nullable محروسٌ بـ`hasColumn`، وكلُّ مرجعٍ منها (عدا `client_id`) يُصرَّح
 * حقلَ `ref` في سجل الوحدة فتُضيء علاقاتُ `hub_children`/`hub_related` تلقائياً:
 *
 *  • `carrier_id` (→carriers) — المزوّدُ الذي أصدر الخطّ، خياراتُه مُنطَّقةٌ بالشركة.
 *  • `employee_id` (→hr) — الموظفُ المُخصَّصُ له الخطّ؛ يُضيء **تبويبَ الاتصالات في
 *    الموظف 360** عبر `hub_related('hr', …)` بلا شيفرةٍ خاصّة.
 *  • `device_id` (→assets) — الجهازُ الذي تعيش فيه الشريحة.
 *  • `station_id` (→stations، من الطور F) — المقعدُ الذي يخدمه الخطّ.
 *  • `client_id` — العميلُ الذي يخدمه الخطّ. **عمودٌ فقط** (لا حقلَ `ref` في السجل):
 *    الاتصالاتُ داخليّةٌ (عميلٌ ممنوعٌ بالكامل عبر PortalGuard)، لكنّ العمودَ يُفعّل
 *    عزلَ العميل الصارم في `hub_scope` (`hub_client_col` عبر العمود الفعليّ) — فخطٌّ
 *    مُخصَّصٌ لعميلٍ لا يبلغ داخليّاً معزولاً على عملاءَ آخرين.
 *
 * **فهارس:** واحدٌ لكلّ عمودِ ربطٍ — قراءاتُ «خطوطُ هذا المزوّد/الموظف/الجهاز/
 * المحطة/العميل» وعكسُ `hub_related` (لا `whereDate`، والترتيبُ يُستكمَل بـ`id`).
 *
 * جدولُ `phone_numbers` مشمولٌ بالنسخةِ أصلاً (وحدةُ `phones`) فلا تغييرَ في
 * `HubBackup`. توليدُ OpenAPI يتغيّر بحقولِ الوحدة — يُعيده المُكامِلُ، فهو خارجُ الترحيل.
 *
 * إضافيّةٌ محروسة (add-if-not-exists لكلّ عمود/فهرس)، كتلةٌ حرفيّةٌ واحدة.
 */
return new class extends Migration
{
    /** أعمدةُ الربطِ وأسماءُ فهارسِها — مصدرٌ واحدٌ لـup/down */
    private const LINKS = [
        'carrier_id'  => 'phone_numbers_carrier_idx',
        'employee_id' => 'phone_numbers_employee_idx',
        'device_id'   => 'phone_numbers_device_idx',
        'station_id'  => 'phone_numbers_station_idx',
        'client_id'   => 'phone_numbers_client_idx',
    ];

    public function up(): void
    {
        if (! Schema::hasTable('phone_numbers')) return;

        Schema::table('phone_numbers', function (Blueprint $t) {
            foreach (array_keys(self::LINKS) as $col) {
                if (! Schema::hasColumn('phone_numbers', $col)) {
                    $t->uuid($col)->nullable();
                }
            }
        });

        foreach (self::LINKS as $col => $name) {
            $this->addIndex('phone_numbers', $col, $name);
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('phone_numbers')) return;

        foreach (self::LINKS as $col => $name) {
            try {
                if (Schema::hasIndex('phone_numbers', $name)) {
                    Schema::table('phone_numbers', fn (Blueprint $t) => $t->dropIndex($name));
                }
            } catch (\Throwable $e) {
            }
        }

        Schema::table('phone_numbers', function (Blueprint $t) {
            foreach (array_keys(self::LINKS) as $col) {
                if (Schema::hasColumn('phone_numbers', $col)) $t->dropColumn($col);
            }
        });
    }

    /** فهرسٌ عاديٌّ محروس — يُنشأ بعد وجودِ عموده فقط، ولا يكسر إن وُجد باسمٍ آخر */
    protected function addIndex(string $table, string $col, string $name): void
    {
        if (! Schema::hasColumn($table, $col)) return;
        try {
            if (Schema::hasIndex($table, $name)) return;
        } catch (\Throwable $e) {
        }
        try {
            Schema::table($table, fn (Blueprint $t) => $t->index($col, $name));
        } catch (\Throwable $e) {
        }
    }
};
