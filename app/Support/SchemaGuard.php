<?php

namespace App\Support;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * **حارسُ القاعدة: التحديثُ لا يمسّ البيانات.**
 *
 * فحصُ الهجرات كلِّها يقول إنّ لا واحدةً منها تحذف بياناتٍ في `up()`. لكنّ
 * الخطر لا يأتي من الهجرات وحدها:
 *
 *   · **أمرٌ هادمٌ يُكتب سهواً**: `migrate:fresh` بدل `migrate` — حرفان يفرقان
 *     بين تحديثٍ ومحوٍ كامل. و`migrate:rollback` يُشغّل `down()` وهي حيث تُحذف
 *     الأعمدة فعلاً: «تراجعٌ» في الاسم وحذفُ بياناتٍ في الأثر.
 *   · **وفروقاتٌ صامتة**: كودٌ يتوقّع عموداً وقاعدةٌ لا تملكه — فيسقط النظام به
 *     بدل أن يُنذر عنه.
 *
 * فهنا: **ما يمحو لا يعمل إلا بإذنٍ صريح مكتوب**، والفروقاتُ تُكشف وتُسدّ
 * **بالإضافة وحدها** — لا إسقاطَ عمودٍ ولا تغييرَ نوعٍ ولا مساسَ ببيانة.
 */
class SchemaGuard
{
    /** ما يمحو أو يهدم — وسببُ منعه بالعربية */
    public const DESTRUCTIVE = [
        'migrate:fresh'    => 'يُسقط كل الجداول ثم يُعيد بناءها — **تُمحى البيانات كلُّها**',
        'migrate:refresh'  => 'يتراجع عن كل الهجرات ثم يُعيدها — تُمحى البيانات',
        'migrate:reset'    => 'يتراجع عن كل الهجرات — تُسقط الجداول وما فيها',
        'migrate:rollback' => 'يُشغّل down() وهي حيث تُحذف الأعمدة — تراجعٌ في الاسم وحذفٌ في الأثر',
        'db:wipe'          => 'يمحو القاعدة كلَّها',
    ];

    /**
     * هل يُمنع هذا الأمر؟ يعيد سببَ المنع، أو `null` إن كان مسموحاً.
     * الإذنُ الصريح `HUB_ALLOW_DESTRUCTIVE=1` يفتح الباب — قفلٌ لا سجن.
     */
    public static function blocks(string $command): ?string
    {
        $why = self::DESTRUCTIVE[$command] ?? null;
        if ($why === null) return null;
        if (config('hub.allow_destructive')) return null;

        return "🛑 «{$command}» ممنوع: {$why}.\n"
            . "   للتحديث العادي استعمل: php artisan migrate\n"
            . "   ولو أردتَه فعلاً — وتأكّدتَ من وجود نسخةٍ احتياطية — اضبط "
            . "HUB_ALLOW_DESTRUCTIVE=1 في ملف .env ثم أعد الأمر.";
    }

    /**
     * **حجبُ الأوامر الهادمة**: يُسجَّل أمرٌ يحمل اسمَ الأمر الهادم نفسه فيحلّ
     * محلَّه في السجل — فلا تُحمَّل شيفرتُه ولا تُنفَّذ خطوةٌ منها. وبالإذن
     * الصريح لا يُسجَّل شيءٌ فيبقى الأصلُ كما هو: **قفلٌ لا سجن**.
     */
    public static function shield(): void
    {
        if (config('hub.allow_destructive')) return;

        \Illuminate\Console\Application::starting(function ($artisan) {
            foreach (array_keys(self::DESTRUCTIVE) as $name) {
                $artisan->add(new \App\Console\BlockedCommand($name, (string) self::blocks($name)));
            }
        });
    }

    /* ────────── كشفُ الفروقات ────────── */

    /**
     * ما يتوقّعه الكود ولا تملكه القاعدة.
     *
     * سجلُّ الوحدات `config/hub.php` هو **الإعلانُ الرسميّ** لما يقرؤه النظام:
     * جدولُ كل وحدة وعمودُ كل حقل. فمقارنتُه بالقاعدة الحيّة تكشف بالضبط الصنفَ
     * الذي أطفأ النظام: عمودٌ يقرؤه الكود ولم تُشغَّل هجرتُه.
     *
     * @return array<int, array{what: string, table: string, col: string, type: string, label: string}>
     */
    public static function gaps(): array
    {
        $out = [];
        foreach (hub_modules() as $mk => $def) {
            $table = (string) ($def['table'] ?? '');
            if ($table === '') continue;

            if (! Schema::hasTable($table)) {
                $out[] = ['what' => $table, 'table' => $table, 'col' => '',
                          'type' => 'table', 'label' => (string) ($def['label'] ?? $mk)];
                continue;
            }

            foreach ($def['fields'] ?? [] as $f) {
                $col = (string) ($f['col'] ?? '');
                if ($col === '' || Schema::hasColumn($table, $col)) continue;
                $out[] = ['what' => $table . '.' . $col, 'table' => $table, 'col' => $col,
                          'type' => (string) ($f['type'] ?? 'text'),
                          'label' => (string) ($f['label'] ?? $col)];
            }
        }

        return $out;
    }

    /**
     * سدُّ الفروقات **بالإضافة وحدها**.
     *
     * تُضاف الأعمدة الناقصة قابلةً للفراغ دائماً — فلا صفٌّ قائمٌ يُرفض، ولا
     * قيمةٌ تُخترع لبياناتٍ لا نعرفها. ولا تُسقَط أعمدة ولا تُغيَّر أنواع ولا
     * تُنشأ جداول كاملة: الجدولُ الغائب يعني هجرةً لم تُشغَّل، وذاك شأنُ
     * `php artisan migrate` لا شأنُ ترقيعٍ من هنا.
     *
     * @return string[] ما أُضيف فعلاً
     */
    public static function fix(): array
    {
        $added = [];
        foreach (self::gaps() as $g) {
            if ($g['type'] === 'table') continue;      // جدولٌ كامل: مكانُه الهجرة

            try {
                Schema::table($g['table'], function (Blueprint $t) use ($g) {
                    match ($g['type']) {
                        'num', 'money' => $t->decimal($g['col'], 18, 3)->nullable(),
                        'date'         => $t->date($g['col'])->nullable(),
                        'dt'           => $t->dateTime($g['col'])->nullable(),
                        'bool'         => $t->boolean($g['col'])->nullable(),
                        'ref'          => $t->string($g['col'], 36)->nullable(),
                        // ta/tags/sec كانت تسقط إلى string(300) — أضيقُ ممّا تُصرّح
                        // به الهجرات، و`gaps()` شرطُه hasColumn وحده فيُبلّغ «سليم»
                        // بينما العمودُ الجديد يرفض ما يُكتب فيه على MySQL الصارمة
                        'ta', 'tags', 'sec', 'txt', 'json' => $t->text($g['col'])->nullable(),
                        default        => $t->string($g['col'], 300)->nullable(),
                    };
                });
                $added[] = $g['what'];
            } catch (\Throwable $e) {
                // عمودٌ يأبى الإضافة يُترك للهجرة — ولا يُوقف سدَّ البقية
            }
        }

        if ($added) {
            \Illuminate\Support\Facades\Cache::flush();      // ذاكرةُ hub_has_col تعرف الجديد
            rescue(fn () => hub_audit('سدّ فروقات المخطّط', null, null, implode('، ', $added)), null, false);
        }

        return $added;
    }
}
