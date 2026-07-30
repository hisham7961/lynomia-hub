<?php

/** مجموعات التنقل الجانبي — مفاتيح من سجل الوحدات config/hub.php */
return [
    ['g' => 'الكيانات',          'icon' => '🏢', 'items' => ['companies', 'projects', 'clients', 'services']],
    ['g' => 'الأصول الرقمية',    'icon' => '💠', 'items' => ['apps', 'code', 'websites', 'domains', 'servers', 'accounts', 'dbs', 'apis', 'social', 'posts', 'emails', 'phones']],
    ['g' => 'العمل',             'icon' => '🗂️', 'items' => ['tasks', 'designs', 'updates', 'issues', 'tickets', 'meetings', 'decisions', 'approvals', 'okrs', 'feats']],
    ['g' => 'المالية',           'icon' => '💰', 'items' => ['fin', 'banks', 'quotes', 'budgets', 'recur', 'costc', 'entries', 'accounts2']],
    ['g' => 'الموارد البشرية',   'icon' => '👥', 'items' => ['hr', 'attend', 'leaves', 'payroll', 'recruit', 'hrlog']],
    ['g' => 'الأصول والعقود',    'icon' => '📦', 'items' => ['assets', 'assetlog', 'stock', 'stockmv', 'contracts']],
    ['g' => 'المعرفة والأتمتة',  'icon' => '📚', 'items' => ['kb', 'autos', 'rules']],
    ['g' => 'الموارد',           'icon' => '🗃️', 'items' => ['files', 'subs', 'vault']],
];
