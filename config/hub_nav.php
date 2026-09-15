<?php

/**
 * مجموعات التنقل الجانبي — مفاتيح من سجل الوحدات config/hub.php.
 *
 * **قائمةٌ منفصلةٌ عن السجلّ — ومن غاب عنها اختفى صامتاً.** وحدةٌ مسجّلةٌ في
 * `config/hub.php` ولها مساراتٌ وشاشاتٌ وصلاحيّاتٌ لا تظهر في الشريطِ إن لم
 * تُذكَر هنا، مهما كانت صلاحيّاتُ المستخدم. هكذا اختفت `stations` و`endpoints`
 * و`restores` حتى اكتشفها المالكُ صدفة.
 *
 * فالحارسُ الآن آليّ: `NavCoverageTest` يُسقط الحزمةَ إن غابت وحدةٌ بلا سببٍ
 * مكتوبٍ في جدولِ الاستثناءات.
 *
 * **ولا تُكرَّر وحدةٌ في مجموعتَين** (يحرسه `NavAndFlowsStarterTest`)، وكلُّ
 * وحدةٍ هنا لها بيتٌ من نوع `module` في مجالِ IA الذي تُشير إليه مجموعتُها
 * (يحرسه `IaNavParityTest`). فالمحطاتُ وُضعت في «الموارد البشرية» — حيث يسأل
 * عنها مَن يُسنِد المقاعدَ — ومعها بيتٌ في مجال `hr`؛ وتبقى في مساحةِ
 * «التقنية» أيضاً عبر خريطةِ المعلومات، فلا قدرةَ نُزعت.
 * ٧ مجموعات بعد دمج الصغيرة في أقربها (كانت ٩): «المشتريات» ضُمّت للمالية،
 * و«الموارد» وُزّعت على أهلها — الاشتراكات مالية، والخزنة أصل رقمي، والملفات معرفة.
 */
return [
    ['g' => 'الكيانات',            'icon' => '🏢', 'items' => ['companies', 'projects', 'clients', 'engagements', 'services', 'brands', 'competitors']],
    ['g' => 'الأصول الرقمية',      'icon' => '💠', 'items' => ['apps', 'code', 'websites', 'domains', 'servers', 'changes', 'accounts', 'vault', 'dbs', 'apis', 'social', 'posts', 'emails', 'phones', 'carriers', 'incidents', 'deploys', 'deps']],
    ['g' => 'العمل',               'icon' => '🗂️', 'items' => ['tasks', 'designs', 'updates', 'issues', 'tickets', 'meetings', 'decisions', 'approvals', 'okrs', 'krs', 'feats', 'requests']],
    ['g' => 'المالية والمشتريات',  'icon' => '💰', 'items' => ['fin', 'banks', 'quotes', 'changeorders', 'budgets', 'subs', 'recur', 'costc', 'entries', 'accounts2', 'suppliers', 'purchases']],
    ['g' => 'الموارد البشرية',     'icon' => '👥', 'items' => ['hr', 'attend', 'leaves', 'payroll', 'recruit', 'hrlog', 'skills', 'stations']],
    ['g' => 'العمليات الميدانية',  'icon' => '🧭', 'items' => ['hcps', 'facilities', 'territories', 'terrassigns', 'cycles', 'visits']],
    ['g' => 'الأصول والعقود',      'icon' => '📦', 'items' => ['products', 'assets', 'assetlog', 'stock', 'stockmv', 'contracts', 'obligations', 'ip', 'compliance']],
    ['g' => 'المعرفة والملفات',    'icon' => '📚', 'items' => ['kb', 'files', 'rules', 'policies', 'policyacks', 'media', 'events', 'plans', 'ideas']],
];
