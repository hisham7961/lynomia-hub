<?php

namespace App\Support;

use Illuminate\Database\Eloquent\Model;

/**
 * ناقل أحداث الأعمال.
 *
 * كانت الأحداث كلها خاماً (`created` / `updated` / `status`)، فكل مشترك يعيد اشتقاق
 * «فاتورة سُدّدت» من مطابقة نص الحالة بنفسه — بمفردات تخصّه، فتتفرّق الدلالة.
 *
 * هنا يُبثّ الحدث الخام **والدلالي** معاً على قائمة مشتركين مرتّبة:
 * الخام يبقى كما كان حرفياً (فلا يتأثر مسارٌ ولا ويبهوك قائم)، والدلالي إضافةٌ فوقه
 * تُشتق من خريطة `config('hub.events')` مرةً واحدة لكل النظام.
 *
 * المشترك لا يكسر أخاه ولا العملية الأصلية: كل نداء معزول في try.
 */
class HubEvents
{
    /** مشتركون إضافيون يُسجَّلون وقت التشغيل (تستعملهم الاختبارات والمراحل التالية) */
    protected static array $extra = [];

    /** أسماء الأحداث الدلالية المبثوثة في الدورة الحالية — لمنع الارتداد */
    protected static bool $deriving = false;

    /**
     * المشتركون بالترتيب. الويبهوكس أولاً (إخطار خارجي لا يعتمد على شيء)،
     * ثم المسارات (قد تُعدّل السجل)، ثم ما يُسجَّل لاحقاً.
     */
    protected static function subscribers(): array
    {
        return array_merge([
            fn (string $e, string $mod, Model $m, ?string $to) => WebhookDispatcher::fire($e, $mod, $m, $to),
            fn (string $e, string $mod, Model $m, ?string $to) => FlowRunner::run($e, $mod, $m, $to),
        ], static::$extra);
    }

    /** تسجيل مشترك إضافي — يُنادى بعد المشتركين الأساسيين */
    public static function listen(callable $fn): void
    {
        static::$extra[] = $fn;
    }

    /** إفراغ المشتركين الإضافيين (للاختبارات) */
    public static function forgetListeners(): void
    {
        static::$extra = [];
    }

    /**
     * بثّ حدث خام ثم ما يشتقّ منه دلالياً.
     * هذه هي النقطة الوحيدة التي تُنادى من المتحكمات عبر `FlowRunner::fire`.
     */
    public static function dispatch(string $event, string $module, Model $m, ?string $statusTo = null): void
    {
        static::emit($event, $module, $m, $statusTo);

        // الاشتقاق لا يشتقّ من نفسه — حدثٌ دلالي لا يولّد دلالياً آخر
        if (static::$deriving) return;

        static::$deriving = true;
        try {
            foreach (static::derive($event, $module, $statusTo) as $name) {
                static::emit($name, $module, $m, $statusTo);
            }
        } finally {
            static::$deriving = false;
        }
    }

    /** تمرير حدث على المشتركين — بلا اشتقاق */
    public static function emit(string $event, string $module, Model $m, ?string $statusTo = null): void
    {
        foreach (static::subscribers() as $sub) {
            try {
                $sub($event, $module, $m, $statusTo);
            } catch (\Throwable $e) {
                // مشتركٌ متعثّر لا يمنع البقية ولا يكسر العملية الأصلية
            }
        }
    }

    /**
     * الأحداث الدلالية المشتقّة من حدث خام، حسب خريطة السجل.
     * قاعدة بلا `to` تُطابق أي تحوّل حالة؛ وبـ`to` تُطابق قيمةً بعينها بمقارنة مُطبَّعة.
     */
    public static function derive(string $event, string $module, ?string $statusTo): array
    {
        $out = [];
        foreach ((array) config("hub.events.$module", []) as $rule) {
            if (($rule['on'] ?? null) !== $event) continue;

            if (! empty($rule['to'])) {
                $want = array_map('hub_ar_norm', (array) $rule['to']);
                if (! in_array(hub_ar_norm($statusTo), $want, true)) continue;
            }

            if (! empty($rule['emit'])) $out[] = $rule['emit'];
        }

        return array_values(array_unique($out));
    }

    /** كل أسماء الأحداث الدلالية المعروفة — تغذّي قوائم باني المسارات والويبهوكس */
    public static function semanticNames(): array
    {
        $out = [];
        foreach ((array) config('hub.events', []) as $rules) {
            foreach ((array) $rules as $r) if (! empty($r['emit'])) $out[] = $r['emit'];
        }
        sort($out);

        return array_values(array_unique($out));
    }

    /** الأحداث الدلالية المتاحة لوحدة بعينها: `اسم الحدث => وصفه` — لباني المسارات */
    public static function namesFor(string $module): array
    {
        $out = [];
        foreach ((array) config("hub.events.$module", []) as $r) {
            if (! empty($r['emit'])) $out[$r['emit']] = $r['label'] ?? $r['emit'];
        }

        return $out;
    }

    /** وصف عربي لأي حدث — خاماً كان أو دلالياً */
    public static function label(string $event): string
    {
        $raw = ['created' => 'عند الإنشاء', 'updated' => 'عند التعديل', 'status' => 'عند تحول الحالة'];
        if (isset($raw[$event])) return $raw[$event];

        foreach ((array) config('hub.events', []) as $rules) {
            foreach ((array) $rules as $r) {
                if (($r['emit'] ?? null) === $event) return $r['label'] ?? $event;
            }
        }

        return $event;
    }
}
