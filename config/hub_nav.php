<?php

/**
 * مجموعات التنقل الجانبي — مفاتيح من سجل الوحدات config/hub.php.
 * ٧ مجموعات بعد دمج الصغيرة في أقربها (كانت ٩): «المشتريات» ضُمّت للمالية،
 * و«الموارد» وُزّعت على أهلها — الاشتراكات مالية، والخزنة أصل رقمي، والملفات معرفة.
 */
return [
    ['g' => 'الكيانات',            'icon' => '🏢', 'items' => ['companies', 'projects', 'clients', 'services', 'brands', 'competitors']],
    ['g' => 'الأصول الرقمية',      'icon' => '💠', 'items' => ['apps', 'code', 'websites', 'domains', 'servers', 'changes', 'accounts', 'vault', 'dbs', 'apis', 'social', 'posts', 'emails', 'phones', 'incidents', 'deploys', 'restores', 'deps']],
    ['g' => 'العمل',               'icon' => '🗂️', 'items' => ['tasks', 'designs', 'updates', 'issues', 'tickets', 'meetings', 'decisions', 'approvals', 'okrs', 'krs', 'feats', 'requests']],
    ['g' => 'المالية والمشتريات',  'icon' => '💰', 'items' => ['fin', 'banks', 'quotes', 'budgets', 'subs', 'recur', 'costc', 'entries', 'accounts2', 'suppliers', 'purchases']],
    ['g' => 'الموارد البشرية',     'icon' => '👥', 'items' => ['hr', 'attend', 'leaves', 'payroll', 'recruit', 'hrlog', 'skills']],
    ['g' => 'الأصول والعقود',      'icon' => '📦', 'items' => ['assets', 'assetlog', 'stock', 'stockmv', 'contracts', 'obligations', 'ip', 'compliance']],
    ['g' => 'المعرفة والملفات',    'icon' => '📚', 'items' => ['kb', 'files', 'rules', 'policies', 'policyacks', 'media', 'events', 'plans', 'ideas']],
];
