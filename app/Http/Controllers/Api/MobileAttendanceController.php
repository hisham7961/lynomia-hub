<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Concerns\MobileEndpoint;
use App\Models\Attendance;
use App\Support\Platform\Api;
use App\Support\Workforce\Workday;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;

/**
 * **الحضورُ والانصرافُ على الجوال** (خطّةُ التطبيق · المرحلة ٣ · 3.1).
 *
 * لا محرّكَ ثانٍ: `Workday::checkIn/checkOut/openRow` نفسُها التي يسلكها زرُّ الويب
 * (`WorkdayController`) — للموظّف عن نفسه حصراً (صفُّه هو، كبوابته الذاتية)، فلا يحتاج
 * صلاحيةَ وحدة الحضور، ولا يُقبل موظّفٌ/صفٌّ يرسله العميل: الهويّةُ من الجلسة وحدَها.
 *
 * **الموقعُ اختياريٌّ وبموافقةٍ صريحة:** `lat`/`lng`/`accuracy` لا تُقبل إلا مع
 * `location_consent=true`، وتُمرَّر إلى حقل `geo` القائم (نصٌّ ≤80) — و`Workday` نفسُه لا
 * يحفظه إلا حين يُفعَّل `work.geo` (القاعدةُ نفسُها للويب). لا تتبّعَ مستمرّ: لحظةُ الضغطِ فقط.
 *
 * **عدمُ الأثر:** `Idempotency-Key` (مالكُ الجوال) — إعادةُ الضغطِ من شبكةٍ متقطّعة تُعيد
 * الردَّ المخزَّن. وبلا مفتاحٍ يحسم `Workday` نفسُه: الحضورُ الثاني يُردّ «مسجَّلٌ منذ…».
 */
class MobileAttendanceController extends V1Controller
{
    use MobileEndpoint;

    /** أوضاعُ العمل — القائمةُ نفسُها التي يقبلها زرُّ الويب */
    public const MODES = ['مكتب', 'عن بعد', 'موقع عميل', 'عمل ميداني', 'مهمة خارجية'];

    /** `GET attendance/today` — صفُّ ورديّتي المفتوحة/اليوم + ما يجوز لي الآن */
    public function today(Request $r): Response
    {
        $this->tagMobile($r);
        $emp = Workday::emp($r->user());
        if (! $emp) return MobileReportsController::noEmployeeProfile();

        // الشاشةُ تسأل ما يسأله الحارس — `openRow` (ورديّةُ الأمسِ المفتوحةُ تُحتسب)
        $row = Workday::openRow($emp->id);
        $open = $row && $row->time_in && ! $row->time_out;

        return $this->okData([
            'date' => now()->toDateString(),
            'employee' => ['id' => (string) $emp->id, 'name' => (string) $emp->name],
            'attendance' => $row ? self::attendanceShape($row) : null,
            'state' => ! $row || ! $row->time_in ? 'not_checked_in' : ($open ? 'checked_in' : 'checked_out'),
            'can' => ['check_in' => ! $row || ! $row->time_in, 'check_out' => $open],
            'modes' => self::MODES,
            'location' => ['recorded_by_server' => setting('work.geo', '0') === '1', 'consent_required' => true],
        ]);
    }

    /** `POST attendance/check-in` — `Workday::checkIn` نفسُها (مشروع/عميل يُنطَّقان داخلها) */
    public function checkIn(Request $r): Response
    {
        $this->tagMobile($r);

        $d = $r->validate([
            'mode' => ['nullable', 'string', Rule::in(self::MODES)],
            'client_id' => ['nullable', 'string', Rule::exists('clients', 'id')->whereNull('deleted_at')],
            'project_id' => ['nullable', 'string', Rule::exists('projects', 'id')->whereNull('deleted_at')],
        ] + self::locationRules(), [], ['mode' => 'وضع العمل', 'client_id' => 'العميل', 'project_id' => 'المشروع']);

        if (! Workday::emp($r->user())) return MobileReportsController::noEmployeeProfile();
        if ($resp = self::consentGuard($d)) return $resp;

        return $this->idempotently($r, function () use ($r, $d) {
            $geo = self::geo($d);
            $res = Workday::checkIn($r->user(), array_filter([
                'mode' => $d['mode'] ?? null,
                'client_id' => $d['client_id'] ?? null,
                'project_id' => $d['project_id'] ?? null,
                'geo' => $geo,
            ], fn ($v) => $v !== null));

            if (! $res['ok']) {
                $row = $res['row'] ?? null;
                $overnight = $row && $row->time_in && ! $row->time_out
                    && (string) ($row->date?->toDateString() ?? $row->date) !== now()->toDateString();

                return Api::error(Api::BUSINESS_RULE_VIOLATION, 422, (string) $res['msg'],
                    ['reason' => $overnight ? 'open_shift_previous_day' : 'already_checked_in',
                     'attendance' => $row ? self::attendanceShape($row) : null]);
            }

            return $this->okData([
                'attendance' => self::attendanceShape($res['row']),
                'message' => (string) $res['msg'],
                'location_recorded' => $geo !== null && data_get($res['row']->meta, 'checkin.geo') !== null,
            ]);
        });
    }

    /** `POST attendance/check-out` — `Workday::checkOut` (يحسب الساعات ويختم الحالة) */
    public function checkOut(Request $r): Response
    {
        $this->tagMobile($r);
        $d = $r->validate(self::locationRules());

        if (! Workday::emp($r->user())) return MobileReportsController::noEmployeeProfile();
        if ($resp = self::consentGuard($d)) return $resp;

        return $this->idempotently($r, function () use ($r) {
            $res = Workday::checkOut($r->user());

            if (! $res['ok']) {
                $row = $res['row'] ?? null;

                return Api::error(Api::BUSINESS_RULE_VIOLATION, 422, (string) $res['msg'],
                    ['reason' => $row ? 'already_checked_out' : 'not_checked_in',
                     'attendance' => $row ? self::attendanceShape($row) : null]);
            }

            $c = $res['compliance'] ?? null;

            return $this->okData([
                'attendance' => self::attendanceShape($res['row']),
                'message' => (string) $res['msg'],
                // حالُ التقرير اليوميّ بعد الانصراف (المُحلِّلُ المركزيّ نفسُه)
                'report_state' => is_array($c) ? ($c['state'] ?? null) : null,
                'report_deadline_at' => is_array($c) && ($c['deadline_at'] ?? null) ? $c['deadline_at']->toIso8601String() : null,
            ]);
        });
    }

    /** قواعدُ الموقع الاختياريّ (خطٌّ/طول/دقّة + الموافقة) */
    private static function locationRules(): array
    {
        return [
            'lat' => ['nullable', 'numeric', 'between:-90,90', 'required_with:lng'],
            'lng' => ['nullable', 'numeric', 'between:-180,180', 'required_with:lat'],
            'accuracy' => ['nullable', 'numeric', 'min:0', 'max:100000'],
            'location_consent' => ['nullable', 'boolean'],
        ];
    }

    /** موقعٌ بلا موافقةٍ صريحة يُرفض (لا يُحفظ ولا يُتجاهَل صمتاً) */
    private static function consentGuard(array $d): ?Response
    {
        if (isset($d['lat']) && ! filter_var($d['location_consent'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
            return Api::error(Api::VALIDATION_FAILED, 422, 'إرسالُ الموقع يتطلّب موافقتك الصريحة (location_consent=true)',
                ['reason' => 'location_consent_required']);
        }

        return null;
    }

    /** الموقعُ نصّاً لحقل `geo` القائم (≤80): `lat,lng` + `±دقّة` إن أُرسلت */
    private static function geo(array $d): ?string
    {
        if (! isset($d['lat'], $d['lng'])) return null;
        $s = sprintf('%.6f,%.6f', (float) $d['lat'], (float) $d['lng']);
        if (isset($d['accuracy'])) $s .= '±' . (int) round((float) $d['accuracy']) . 'm';

        return substr($s, 0, 80);
    }

    /** بطاقةُ صفِّ الحضور — حقولٌ صريحةٌ لصاحبها (لا IP ولا جهازَ ولا موقع) */
    public static function attendanceShape(Attendance $a): array
    {
        return [
            'id' => (string) $a->id,
            'date' => $a->date?->toDateString() ?? (string) $a->date,
            'time_in' => $a->time_in ? (string) $a->time_in : null,
            'time_out' => $a->time_out ? (string) $a->time_out : null,
            'hours' => $a->hours !== null ? (float) $a->hours : null,
            'status' => $a->status !== null ? (string) $a->status : null,
            'mode' => $a->mode !== null ? (string) $a->mode : null,
            'project_id' => $a->project_id ? (string) $a->project_id : null,
            'client_id' => $a->client_id ? (string) $a->client_id : null,
            'overnight' => (bool) $a->overnight,
            'checked_in_at' => data_get($a->meta, 'checkin.at'),
            'checked_out_at' => data_get($a->meta, 'checkout.at'),
        ];
    }
}
