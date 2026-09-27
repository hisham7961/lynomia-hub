<?php

namespace App\Http\Controllers\Api;

use App\Http\Middleware\MobileContext;
use App\Support\Insights\CalendarFeed;
use App\Support\Platform\Api;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Symfony\Component\HttpFoundation\Response;

/**
 * **التقويمُ والتنبيهاتُ على الجوال** (خطّة التطبيق · 4.5) — ما تعرضه صفحتا الويب
 * `calendar` و`alerts` حرفاً، من القارئَين أنفسِهما:
 *  • `CalendarFeed::range` — كلُّ حقول التاريخ الجديرة عبر الوحدات بـ`hub_can` +
 *    `hub_scope` + `hub_field_mode`، ومخبأٌ بالمستخدم؛ وفوقه تضييقُ سياق الجوال
 *    (`X-Lynomia-Company/-Client` — تضييقٌ لا توسيع).
 *  • `hub_expiry` (رادارُ «ينتهي قريباً» — `ExpiryRadar::scan`) بصفوفِ صاحبِ الشأن
 *    ووجهتِها المحسوبةِ خادميّاً (`hub_expiry_target`).
 * كلاهما داخليّ: حسابُ العميل ٤٠٤ (الأسماءُ خارجَ قائمة `MobilePortalGuard`).
 */
class MobileCalendarController extends MobileWorkflowController
{
    /** أقصى مدى نافذةِ التقويم بالأيام — شهران (الويبُ شهرٌ واحد) */
    public const MAX_SPAN_DAYS = 62;

    /** `GET calendar?from=&to=` — عناصرُ التقويم في النافذة (الافتراضُ: اليوم + ٣٠ يوماً) */
    public function calendar(Request $r): Response
    {
        $this->tagMobile($r);
        if ($deny = $this->denyClient()) return $deny;

        $from = $this->day($r->query('from'));
        $to = $this->day($r->query('to'));
        if ($from === false || $to === false) {
            return Api::error(Api::VALIDATION_FAILED, 422, 'التاريخ بصيغة YYYY-MM-DD',
                ['from' => ['date_format:Y-m-d'], 'to' => ['date_format:Y-m-d']]);
        }
        $from = $from ?? now()->startOfDay();
        $to = $to ?? $from->copy()->addDays(30);
        if ($to->lt($from)) {
            return Api::error(Api::VALIDATION_FAILED, 422, 'نهايةُ النافذة قبل بدايتها', ['to' => ['after_or_equal:from']]);
        }
        if ($from->diffInDays($to) > self::MAX_SPAN_DAYS) {
            return Api::error(Api::VALIDATION_FAILED, 422,
                'نافذةُ التقويم أوسعُ من ' . self::MAX_SPAN_DAYS . ' يوماً — ضيّقها', ['to' => ['max_span:' . self::MAX_SPAN_DAYS]]);
        }

        $variant = 'm:' . (MobileContext::company($r) ?? '') . ':' . (MobileContext::client($r) ?? '');
        [$days, $overflow] = CalendarFeed::range($r->user(), $from, $to, $r->boolean('fresh'), $variant,
            fn ($q, string $mk) => MobileContext::apply($q, $mk, $r));

        $out = [];
        foreach ($days as $date => $items) {
            $out[] = ['date' => (string) $date, 'items' => array_map(fn (array $i) => [
                'module' => $i['module'],
                'module_label' => $i['mlabel'],
                'field' => $i['field'],
                'field_label' => $i['label'],
                'id' => $i['id'],
                'name' => $i['name'] !== '' ? $i['name'] : null,
                'target' => ['module' => $i['module'], 'id' => $i['id']],
            ], $items)];
        }

        return $this->ok([
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'days' => $out,
            'overflow' => (int) $overflow,
        ]);
    }

    /** `GET alerts` — «ينتهي قريباً»: متأخّر · خلال أسبوع · خلال النافذة (رادارُ الويب نفسُه) */
    public function alerts(Request $r): Response
    {
        $this->tagMobile($r);
        if ($deny = $this->denyClient()) return $deny;

        $items = collect(hub_expiry($r->boolean('fresh'), $r->user()))->values();
        $shape = fn (array $i) => [
            'module' => (string) $i['module'],
            'module_label' => (string) ($i['mlabel'] ?? ''),
            'field' => (string) ($i['fkey'] ?? ''),
            'field_label' => (string) ($i['flabel'] ?? ''),
            'id' => (string) $i['id'],
            'name' => (string) ($i['name'] ?? ''),
            'date' => $i['date'] ?? null,
            'days' => (int) ($i['days'] ?? 0),
            'document' => (bool) ($i['doc'] ?? false),
            'self' => (bool) ($i['self'] ?? false),
            'target' => hub_expiry_target($i),
        ];

        return $this->ok([
            'late' => $items->filter(fn ($i) => $i['days'] < 0)->map($shape)->values()->all(),
            'week' => $items->filter(fn ($i) => $i['days'] >= 0 && $i['days'] <= 7)->map($shape)->values()->all(),
            'month' => $items->filter(fn ($i) => $i['days'] > 7)->map($shape)->values()->all(),
            'total' => $items->count(),
            'window_days' => (int) hub_radar_window(),
        ]);
    }

    /** تاريخٌ صحيحٌ ⇒ Carbon، غائبٌ ⇒ null، مشوَّهٌ ⇒ false */
    private function day($v): Carbon|false|null
    {
        $v = trim(hub_str($v));
        if ($v === '') return null;
        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $v)) return false;
        try {
            $d = Carbon::createFromFormat('Y-m-d', $v);

            return $d && $d->format('Y-m-d') === $v ? $d->startOfDay() : false;
        } catch (\Throwable $e) {
            return false;
        }
    }
}
