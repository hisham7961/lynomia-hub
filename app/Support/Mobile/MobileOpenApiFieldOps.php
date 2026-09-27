<?php

namespace App\Support\Mobile;

use App\Models\InventoryItem;
use App\Models\InventoryScan;

/**
 * **وصفاتُ OpenAPI لأفعال الميدان والموظّف** (خطّةُ التطبيق · المرحلة ٣) — جزءٌ من
 * `MobileOpenApi` مفصولٌ في صنفٍ مستقلّ كي تنمو كتلُ المجالات دون أن يتضخّم الملفُّ الواحد:
 * وصفاتُ التشغيل (بمفتاح اسمِ المسار) + المخطّطاتُ + تصنيفُ المجالات لبيان القدرات.
 * المسارُ/الطريقةُ/المصادقةُ تأتي من السجلّ حيّاً كما في سائر المواصفة — هذا يضيف الدلالة.
 */
final class MobileOpenApiFieldOps
{
    /** اسمُ المسار ⇒ المجال في `mobile-capabilities.json` */
    public const AREAS = [
        'mobile.attendance.today' => 'attendance', 'mobile.attendance.check_in' => 'attendance',
        'mobile.attendance.check_out' => 'attendance',
        'mobile.leaves.decide' => 'leaves', 'mobile.leaves.decision' => 'leaves',
        'mobile.me.custody' => 'custody', 'mobile.custody.handover' => 'custody', 'mobile.custody.recover' => 'custody',
        'mobile.custody.abilities' => 'custody',
        'mobile.inventory.index' => 'inventory', 'mobile.inventory.show' => 'inventory', 'mobile.inventory.freeze' => 'inventory',
        'mobile.inventory.scan' => 'inventory', 'mobile.inventory.reconcile' => 'inventory', 'mobile.inventory.close' => 'inventory',
        'mobile.files.index' => 'files', 'mobile.files.destroy' => 'files',
        'mobile.comments.attachment' => 'comments', 'mobile.dm.attachment' => 'dm',
        'mobile.versions.index' => 'crud',
    ];

    /** وسومُ المجالات الجديدة */
    public const TAGS = [
        'attendance' => 'الحضورُ والانصراف (Workday — للموظّف عن نفسه)',
        'leaves' => 'قرارُ الإجازة (سلسلةُ المدير/الموارد البشريّة)',
        'custody' => 'العهدة: عهدتي + التسليم/الاسترداد',
        'inventory' => 'جلساتُ الجرد بالمسح',
    ];

    /**
     * @param \Closure $ref  (string) => ['$ref' => …]
     * @param \Closure $env  (array) => غلافُ {data, request_id}
     * @param \Closure $obj  (array $props, array $required = []) => object schema
     */
    public static function opMeta(\Closure $ref, \Closure $env, \Closure $obj): array
    {
        $str = ['type' => 'string'];
        $strN = ['type' => 'string', 'nullable' => true];
        $bool = ['type' => 'boolean'];
        $int = ['type' => 'integer'];
        $date = ['type' => 'string', 'format' => 'date'];
        $loc = [
            'lat' => ['type' => 'number', 'minimum' => -90, 'maximum' => 90, 'description' => 'اختياريّ — مع lng ومع location_consent=true'],
            'lng' => ['type' => 'number', 'minimum' => -180, 'maximum' => 180],
            'accuracy' => ['type' => 'number', 'minimum' => 0, 'description' => 'دقّةُ الموقع بالأمتار'],
            'location_consent' => ['type' => 'boolean', 'description' => 'موافقةٌ صريحة — شرطٌ لإرسال الموقع (وإلا 422 location_consent_required)'],
        ];
        $stepUp = fn (string $p) => 'خلفَ تصعيد الجوال — مِنحةُ auth/step-up بالغرض `' . $p . '` وإلا 428 STEP_UP_REQUIRED';

        return [
            // ── 3.1 الحضور ──
            'mobile.attendance.today' => ['tag' => 'attendance',
                'summary' => 'ورديّتي المفتوحة/اليوم (Workday::openRow) + ما يجوز الآن — 422 no_employee_profile بلا ملفّ موظّف',
                'ok' => $env($ref('AttendanceToday')), 'errors' => ['404', '422']],
            'mobile.attendance.check_in' => ['tag' => 'attendance', 'bodyRequired' => false,
                'summary' => 'تسجيلُ الحضور (Workday::checkIn نفسُها) — موقعٌ اختياريٌّ بموافقة، Idempotency · throttle 30/دقيقة',
                'params' => ['Idempotency-Key'],
                'body' => $obj(['mode' => ['type' => 'string', 'enum' => \App\Http\Controllers\Api\MobileAttendanceController::MODES],
                    'project_id' => $strN, 'client_id' => $strN] + $loc),
                'ok' => $env($obj(['attendance' => $ref('AttendanceRow'), 'message' => $str, 'location_recorded' => $bool])),
                'okDesc' => 'سُجّل الحضور. الرفضُ الدلاليّ 422 BUSINESS_RULE_VIOLATION بـdetails.reason: already_checked_in | open_shift_previous_day | no_employee_profile | location_consent_required',
                'errors' => ['404', '409', '422']],
            'mobile.attendance.check_out' => ['tag' => 'attendance', 'bodyRequired' => false,
                'summary' => 'تسجيلُ الانصراف (Workday::checkOut — الساعاتُ والحالةُ خادميّة) — Idempotency · throttle 30/دقيقة',
                'params' => ['Idempotency-Key'],
                'body' => $obj($loc),
                'ok' => $env($obj(['attendance' => $ref('AttendanceRow'), 'message' => $str,
                    'report_state' => $strN, 'report_deadline_at' => ['type' => 'string', 'format' => 'date-time', 'nullable' => true]])),
                'okDesc' => 'سُجّل الانصراف. الرفضُ 422 بـdetails.reason: not_checked_in | already_checked_out | no_employee_profile',
                'errors' => ['404', '409', '422']],

            // ── 3.2 قرارُ الإجازة ──
            'mobile.leaves.decide' => ['tag' => 'leaves', 'bodyRequired' => true,
                'summary' => 'قرارُ طلبِ إجازة (LeaveDecision — المديرُ يوصي، الموارد البشريّة تحسم، سببُ الرفض إلزاميّ) — Idempotency',
                'params' => ['Idempotency-Key'],
                'body' => $obj(['decision' => ['type' => 'string', 'enum' => ['approve', 'reject']],
                    'reason' => ['type' => 'string', 'maxLength' => 500, 'description' => 'إلزاميٌّ عند reject']], ['decision']),
                'ok' => $env($obj(['id' => $str, 'status' => $str, 'previous_status' => $str, 'decision' => $str, 'message' => $str])),
                'okDesc' => 'قُرِّر. الرفضُ بـdetails.reason: already_decided (422) | self_request (403) | not_decider (403) | reason_required (422 VALIDATION_FAILED)',
                'errors' => ['403', '404', '409', '422']],

            'mobile.leaves.decision' => ['tag' => 'leaves',
                'summary' => 'أهليّةُ زرِّ القرار بلا أثر — LeaveDecision::abilities نفسُها التي يحسم بها decide (leaves:v ⇒ 403، خارجَ النطاق 404)',
                'ok' => $env($obj(['id' => $str, 'status' => $str, 'can_decide' => $bool,
                    'reason' => ['type' => 'string', 'nullable' => true, 'enum' => ['already_decided', 'self_request', 'not_decider', null],
                        'description' => 'السببُ الآليُّ نفسُه الذي يردّ به decide — null حين can_decide'],
                    'can_approve' => $bool, 'can_reject' => $bool,
                    'approve_status' => ['type' => 'string', 'nullable' => true, 'enum' => ['معتمد', 'موافقة المدير', null],
                        'description' => 'ما يصير إليه الطلبُ بالموافقة (HR يحسم، المديرُ يوصي)'],
                    'reject_reason_required' => $bool],
                    ['id', 'status', 'can_decide', 'reason', 'can_approve', 'can_reject', 'approve_status'])),
                'errors' => ['403', '404']],

            // ── 3.3 العهدة ──
            'mobile.me.custody' => ['tag' => 'custody',
                'summary' => 'عهدتي: ما بيدي + حركاتُها + إقراراتُ الاستلام المعلّقة (الإقرارُ عبر POST assets/{id}/actions/ack)',
                'ok' => $env($ref('MyCustody')), 'errors' => ['404']],
            'mobile.custody.handover' => ['tag' => 'custody', 'bodyRequired' => true,
                'summary' => 'تسليمُ عهدة (CustodyHandover — assets:e أو custodyAssign، نطاقُ الأصل) — Idempotency',
                'params' => ['Idempotency-Key'],
                'body' => $obj(['user_id' => $str, 'at' => $date, 'note' => ['type' => 'string', 'maxLength' => 500],
                    'project_id' => $strN], ['user_id', 'at']),
                'ok' => $env($ref('CustodyMove')), 'errors' => ['403', '404', '409', '422']],
            'mobile.custody.recover' => ['tag' => 'custody', 'bodyRequired' => true,
                'summary' => 'استردادُ عهدة (العهدةُ بيد أحدٍ شرط، وإلا 422) — Idempotency',
                'params' => ['Idempotency-Key'],
                'body' => $obj(['at' => $date, 'note' => ['type' => 'string', 'maxLength' => 500]], ['at']),
                'ok' => $env($ref('CustodyMove')), 'errors' => ['403', '404', '409', '422']],

            'mobile.custody.abilities' => ['tag' => 'custody',
                'summary' => 'أهليّةُ زرَّي التسليم/الاسترداد بلا أثر — بوّابةُ CustodyHandover (assets:e أو custodyAssign) + Custody::scoped (404) + الحيازة',
                'ok' => $env($obj(['id' => $str, 'can_handover' => $bool, 'can_recover' => $bool,
                    'holder_id' => ['type' => 'string', 'nullable' => true, 'description' => 'null إن لم تكن بيد أحد أو حُجب الحقلُ عن الدور'],
                    'reason' => ['type' => 'string', 'nullable' => true, 'enum' => ['not_permitted', 'not_held', null]]],
                    ['id', 'can_handover', 'can_recover', 'holder_id', 'reason'])),
                'errors' => ['403', '404']],

            // ── 3.4 الجرد ──
            'mobile.inventory.index' => ['tag' => 'inventory', 'summary' => 'جلساتُ الجرد (assets:v · عزلُ الشركة) — الأحدثُ أوّلاً',
                'ok' => $env($obj(['sessions' => ['type' => 'array', 'items' => $ref('InventorySession')],
                    'can' => $ref('InventoryAbilities')])), 'errors' => ['403', '404']],
            'mobile.inventory.show' => ['tag' => 'inventory', 'summary' => 'جلسةٌ: العدُّ بالحكم + صفحةُ أصنافٍ (50) + أحدثُ المسحات',
                'params' => ['page'],
                'ok' => $env($obj(['session' => $ref('InventorySession'),
                    'items' => ['type' => 'array', 'items' => $obj(['asset_id' => $str,
                        'verdict' => ['type' => 'string', 'enum' => InventoryItem::VERDICTS],
                        'code' => $str, 'name' => $str, 'type' => $str, 'status' => $str])],
                    'page' => $int, 'last_page' => $int, 'items_total' => $int,
                    'scans' => ['type' => 'array', 'items' => $ref('InventoryScan')], 'can' => $ref('InventoryAbilities')])),
                'errors' => ['403', '404']],
            'mobile.inventory.freeze' => ['tag' => 'inventory', 'bodyRequired' => false, 'created' => true,
                'summary' => 'تجميدٌ: فتحُ جلسةٍ بلقطةِ أصولي المنطَّقة (e أو assetInventory) — شركةُ X-Lynomia-Company إن ضُيِّقت · Idempotency',
                'params' => ['Idempotency-Key'], 'body' => $obj([]),
                'ok' => $env($obj(['session' => $ref('InventorySession'), 'frozen' => $int])), 'errors' => ['403', '422']],
            'mobile.inventory.scan' => ['tag' => 'inventory', 'bodyRequired' => true,
                'summary' => 'مسحُ رمزٍ عبر المحلِّل الموحّد (بنطاقي) — معروف/غير متوقع/غير معروف؛ الرمزُ الأجنبيّ لا يُخزَّن · Idempotency',
                'params' => ['Idempotency-Key'],
                'body' => $obj(['code' => ['type' => 'string', 'maxLength' => 300]], ['code']),
                'ok' => $env($obj(['result' => ['type' => 'string', 'enum' => InventoryScan::RESULTS],
                    'result_key' => ['type' => 'string', 'enum' => ['known', 'unexpected', 'unknown']], 'message' => $str,
                    'scan' => $ref('InventoryScan'),
                    'asset' => ['type' => ['object', 'null'], 'properties' => ['id' => $str, 'code' => $str, 'name' => $strN]]])),
                'okDesc' => 'سُجّلت المسحة. جلسةٌ مغلقة ⇒ 422 details.reason=session_closed',
                'errors' => ['403', '404', '409', '422']],
            'mobile.inventory.reconcile' => ['tag' => 'inventory', 'bodyRequired' => false,
                'summary' => 'المصالحةُ الكتابيّة (موجود/مفقود/انتقل/غير متوقع) — ' . $stepUp('action:inventory:reconcile'),
                'params' => ['Idempotency-Key'], 'body' => $obj([]),
                'ok' => $env($obj(['id' => $str, 'counts' => ['type' => 'object', 'additionalProperties' => $int],
                    'reconciled_at' => $strN])), 'errors' => ['403', '404', '409', '428']],
            'mobile.inventory.close' => ['tag' => 'inventory', 'bodyRequired' => false,
                'summary' => 'إغلاقُ الجلسة (لا مسحَ بعده · يُطلق inventory.session_closed) — ' . $stepUp('action:inventory:close'),
                'params' => ['Idempotency-Key'], 'body' => $obj([]),
                'ok' => $env($obj(['id' => $str, 'status' => $str, 'closed_now' => $bool,
                    'closed_at' => ['type' => 'string', 'format' => 'date-time', 'nullable' => true]])),
                'errors' => ['403', '404', '409', '428']],

            // ── 3.5 المرفقات (طلبا الجوال #1/#2) ──
            'mobile.files.index' => ['tag' => 'files',
                'summary' => 'مرفقاتُ سجلٍّ بقواعد شاشة الويب (guardRecord v + DocumentPolicy: الممنوعُ صراحةً لا يُعرَض) — العميلُ محجوب',
                'params' => [['name' => 'module', 'in' => 'query', 'required' => true, 'schema' => $str],
                    ['name' => 'record_id', 'in' => 'query', 'required' => true, 'schema' => $str]],
                'ok' => $env($obj(['module' => $str, 'record_id' => $str, 'count' => $int,
                    'files' => ['type' => 'array', 'items' => $ref('RecordFile')]])), 'errors' => ['403', '404', '422']],
            'mobile.files.destroy' => ['tag' => 'files',
                'summary' => 'حذفُ مرفقٍ (ناعم) — رافعُه أو المالكُ أو محرّرُ وحدته، ونطاقُ السجلّ (حارسُ الويب نفسُه)',
                'ok' => $env($obj(['id' => $str, 'module' => $str, 'record_id' => $str, 'deleted' => $bool])),
                'errors' => ['403', '404']],
            'mobile.comments.attachment' => ['tag' => 'comments',
                'summary' => 'تنزيلُ مرفقِ تعليقٍ بمقبضه (معرّفُ التعليق) — لمن يرى خيطَه (guardTarget) · attachment + nosniff',
                'ok' => ['type' => 'string', 'format' => 'binary'], 'okDesc' => 'بايتاتُ المرفق',
                'okType' => 'application/octet-stream', 'errors' => ['403', '404']],
            'mobile.dm.attachment' => ['tag' => 'dm',
                'summary' => 'تنزيلُ مرفقِ رسالةٍ بمقبضها (معرّفُ الرسالة) — لطرفَيها وحدَهما (غيرُهما 404)',
                'ok' => ['type' => 'string', 'format' => 'binary'], 'okDesc' => 'بايتاتُ المرفق',
                'okType' => 'application/octet-stream', 'errors' => ['404']],

            // ── 3.6 النسخ ──
            'mobile.versions.index' => ['tag' => 'crud',
                'summary' => 'نسخُ السجلّ (رقم/متى/من + أسماءُ الحقول المتغيّرة المرئيّة لدورك) — لتفعيل إجراء restore-version',
                'params' => [['name' => 'limit', 'in' => 'query', 'schema' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 100, 'default' => 20]]],
                'ok' => $env($obj(['module' => $str, 'id' => $str, 'current_version' => ['type' => 'integer', 'nullable' => true],
                    'trashed' => $bool, 'versions' => ['type' => 'array', 'items' => $ref('RecordVersionItem')],
                    'restore' => $obj(['action' => $str, 'path' => $str, 'needs' => ['type' => 'array', 'items' => $str]])])),
                'errors' => ['403', '404']],
        ];
    }

    /** مخطّطاتُ المجالات الجديدة (تُدمج في components.schemas) */
    public static function schemas(): array
    {
        $s = ['type' => 'string'];
        $sN = ['type' => 'string', 'nullable' => true];
        $b = ['type' => 'boolean'];
        $i = ['type' => 'integer'];
        $dt = ['type' => 'string', 'format' => 'date-time', 'nullable' => true];
        $userRef = ['type' => ['object', 'null'], 'properties' => ['id' => $s, 'name' => $s]];

        return [
            'AttendanceRow' => ['type' => 'object', 'description' => 'صفُّ حضوري — بلا عنوانٍ ولا جهازٍ ولا موقع', 'properties' => [
                'id' => $s, 'date' => $s, 'time_in' => $sN, 'time_out' => $sN, 'hours' => ['type' => 'number', 'nullable' => true],
                'status' => $sN, 'mode' => $sN, 'project_id' => $sN, 'client_id' => $sN, 'overnight' => $b,
                'checked_in_at' => $dt, 'checked_out_at' => $dt,
            ]],
            'AttendanceToday' => ['type' => 'object', 'properties' => [
                'date' => $s, 'employee' => $userRef,
                'attendance' => ['oneOf' => [['$ref' => '#/components/schemas/AttendanceRow'], ['type' => 'null']]],
                'state' => ['type' => 'string', 'enum' => ['not_checked_in', 'checked_in', 'checked_out']],
                'can' => ['type' => 'object', 'properties' => ['check_in' => $b, 'check_out' => $b]],
                'modes' => ['type' => 'array', 'items' => $s],
                'location' => ['type' => 'object', 'properties' => ['recorded_by_server' => $b, 'consent_required' => $b]],
            ]],
            'MyCustody' => ['type' => 'object', 'properties' => [
                'assets' => ['type' => 'array', 'items' => ['type' => 'object', 'properties' => [
                    'id' => $s, 'code' => $sN, 'name' => $s, 'type' => $sN, 'tag' => $sN,
                    'serial' => ['type' => 'string', 'nullable' => true, 'description' => 'null حين يحجبه قيدُ الحقل على دورك'],
                    'status' => $sN, 'station' => $userRef, 'receipt_pending' => $b,
                ]]],
                'moves' => ['type' => 'array', 'items' => ['type' => 'object', 'properties' => [
                    'id' => $s, 'asset_id' => $s, 'asset_name' => $s, 'action' => $s, 'at' => $sN, 'note' => $sN,
                ]]],
                'pending_receipts' => ['type' => 'array', 'items' => ['type' => 'object', 'properties' => [
                    'module' => $s, 'id' => $s, 'title' => $s, 'label' => $s, 'why' => $s,
                    'ack' => ['type' => 'object', 'properties' => ['method' => $s, 'path' => $s]],
                ]]],
            ]],
            'CustodyMove' => ['type' => 'object', 'properties' => [
                'asset' => ['type' => 'object', 'properties' => ['id' => $s, 'name' => $s, 'status' => $sN, 'holder_id' => $sN]],
                'movement' => ['type' => 'object', 'properties' => ['id' => $s, 'action' => $s, 'at' => $sN, 'user_id' => $sN, 'project_id' => $sN]],
            ]],
            'InventorySession' => ['type' => 'object', 'properties' => [
                'id' => $s, 'status' => $s, 'open' => $b, 'company_id' => $sN, 'by' => $userRef,
                'created_at' => $dt, 'closed_at' => $dt, 'reconciled_at' => $sN,
                'items_count' => ['type' => 'integer', 'nullable' => true], 'scans_count' => ['type' => 'integer', 'nullable' => true],
                'counts' => ['type' => 'object', 'additionalProperties' => $i, 'description' => 'في التفصيل وحدَه — العدُّ بالحكم'],
                'total' => $i, 'scans_total' => $i,
            ]],
            'InventoryScan' => ['type' => 'object', 'properties' => [
                'id' => $s, 'result' => $s, 'asset_id' => $sN,
                'code' => ['type' => 'string', 'nullable' => true, 'description' => 'للمحلول داخل النطاق وحدَه'],
                'by' => $userRef, 'at' => $dt,
            ]],
            'InventoryAbilities' => ['type' => 'object', 'description' => 'عرضٌ — الخادمُ يعيد الفحصَ عند كلِّ فعل', 'properties' => [
                'freeze' => $b, 'scan' => $b, 'reconcile' => $b, 'close' => $b,
                'step_up' => ['type' => 'object', 'properties' => ['reconcile' => $s, 'close' => $s]],
            ]],
            'RecordFile' => ['type' => 'object', 'description' => 'مرفقُ سجلٍّ — لا مسارَ قرصٍ ولا رابطٌ عامّ', 'properties' => [
                'id' => $s, 'original_name' => $sN, 'mime' => $sN, 'size' => $i, 'kind' => $sN, 'kind_label' => $sN,
                'av_status' => $sN, 'expires_at' => $sN, 'uploaded_by' => $userRef, 'created_at' => $dt,
                'can' => ['type' => 'object', 'properties' => ['download' => $b, 'preview' => $b, 'delete' => $b]],
                'download' => $s, 'stream' => $s,
            ]],
            'MessageAttachment' => ['type' => ['object', 'null'], 'description' => 'مقبضُ مرفقِ رسالة/تعليق — id معرّفُ صاحبه، والتنزيلُ عبر download', 'properties' => [
                'id' => $s, 'name' => $s, 'size' => $i, 'mime' => $s, 'download' => $s,
            ]],
            'RecordVersionItem' => ['type' => 'object', 'properties' => [
                'version' => $i, 'at' => $dt, 'by' => $userRef, 'current' => $b, 'restorable' => $b,
                'changed' => ['type' => ['array', 'null'], 'items' => $s, 'description' => 'مفاتيحُ الحقول المرئيّة لدورك التي تغيّرت عن النسخة السابقة (أسماءٌ لا قيم)'],
            ]],
        ];
    }
}
