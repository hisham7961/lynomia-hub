<?php

/** سجلُّ الوحدات — «endpoints» (docs/REORG_PLAN.md §R4) — يُحمَّل بترتيبه من قائمة config/hub.php */

return [
    'key' => 'endpoints',
    'table' => 'endpoint_devices',
    'model' => 'EndpointDevice',
    'label' => 'النقاط الطرفية',
    'display' => 'hostname',
    'status' => 'status',
    'columns' => [
        'hostname',
        'os',
        'companyId',
        'employeeId',
        'status',
        'lastHeartbeatAt',
    ],
    'fields' => [
        [
            'key' => 'hostname',
            'col' => 'hostname',
            'label' => 'اسم الجهاز',
            'type' => 'text',
            // يعلنه وكيلُ الجهاز لحظةَ التسجيل ويحدّثه heartbeat (WP-J.2)
            // — لا يُكتب يدوياً فتفترق الشاشةُ عن الجهاز.
            'locked' => true,
        ],
        [
            'key' => 'deviceUuid',
            'col' => 'device_uuid',
            'label' => 'هويّة الوكيل',
            'type' => 'text',
            'locked' => true,
        ],
        [
            'key' => 'os',
            'col' => 'os',
            'label' => 'نظام التشغيل',
            'type' => 'sel',
            // المدعومُ رسميّاً من المصدرِ الواحد (§1): Windows/macOS — لا يُنشأ
            // سجلٌّ جديدٌ بنظامٍ غيرِ مدعوم؛ والصفوفُ القديمة (linux) تبقى تُعرَض.
            'options' => \App\Support\Endpoint\Endpoint::SUPPORTED,
            'locked' => true,
        ],
        // (لا حقلَ `agentVersion` في السجل عمداً: قيمةٌ آليّةٌ يبلّغها
        // الوكيلُ وتُعرض في شاشات WP-J.3 من الصفّ نفسِه — وعمودُها (٣٠)
        // أضيقُ من عتبة الحقل النصّيّ الحرّ، والكاتبُ الوحيد يقصّها
        // بـmb_substr في النموذج.)
        [
            'key' => 'companyId',
            'col' => 'company_id',
            'label' => 'الشركة',
            'type' => 'ref',
            'ref' => 'companies',
            // تُسنَد من رمز التسجيل لحظةَ السكّ — لا تُنقل من CRUD فيقفز
            // جهازٌ بين شركتين خارج مسارِ الأمن.
            'locked' => true,
        ],
        [
            'key' => 'employeeId',
            'col' => 'employee_id',
            'label' => 'الموظف الحامل',
            'type' => 'ref',
            'ref' => 'users',
            // Permissions 360 · 12.2 — يُختم من رمزِ التسجيلِ الموقَّع (EnrollController)
            // لا من نموذجِ CRUD — فلا يُعاد توجيهُ جهازٍ لموظفٍ/أصلٍ/محطةٍ بيدِ محرِّر.
            'locked' => true,
        ],
        [
            'key' => 'assetId',
            'col' => 'asset_id',
            'label' => 'الأصل المرتبط',
            'type' => 'ref',
            'ref' => 'assets',
            // Permissions 360 · 12.2 — يُختم من رمزِ التسجيلِ الموقَّع (EnrollController)
            // لا من نموذجِ CRUD — فلا يُعاد توجيهُ جهازٍ لموظفٍ/أصلٍ/محطةٍ بيدِ محرِّر.
            'locked' => true,
        ],
        [
            'key' => 'stationId',
            'col' => 'station_id',
            'label' => 'المحطة',
            'type' => 'ref',
            'ref' => 'stations',
            // Permissions 360 · 12.2 — يُختم من رمزِ التسجيلِ الموقَّع (EnrollController)
            // لا من نموذجِ CRUD — فلا يُعاد توجيهُ جهازٍ لموظفٍ/أصلٍ/محطةٍ بيدِ محرِّر.
            'locked' => true,
        ],
        [
            'key' => 'pubkeyFp',
            'col' => 'pubkey_fp',
            'label' => 'بصمة المفتاح العامّ',
            'type' => 'text',
            // تُشتقّ من المفتاح المخزَّن نفسِه في النموذج — لا تُدَّعى.
            'locked' => true,
        ],
        [
            'key' => 'status',
            'col' => 'status',
            'label' => 'الحالة',
            'type' => 'sel',
            // allowlist مصدرُها الواحد النموذج (لا DB enum — C10)؛ مقفولةٌ:
            // isolate/lock تمرّ بأوامر WP-J.2 خلف step-up لا من CRUD.
            'options' => \App\Models\EndpointDevice::STATUSES,
            'locked' => true,
        ],
        [
            'key' => 'lastHeartbeatAt',
            'col' => 'last_heartbeat_at',
            'label' => 'آخر نبضة',
            'type' => 'dt',
            'locked' => true,
        ],
    ],
    'search' => [
        'hostname',
        'device_uuid',
        'os',
    ],
];
