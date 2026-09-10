# 10 — الاختبارات (§31/§76-109)

`tests/Feature/PermissionsReconciliationTest.php` — 14 اختباراً، على المحرّكين:

| الاختبار | يحرس |
|---|---|
| `test_inspector_explains_internal_employee` | المُفسِّر: عرضٌ ممنوح، كتابةٌ ممنوعة، users محكومةٌ بعلَم (§59) |
| `test_inspector_owner_allows_everything` | تجاوزُ المالك (§33) |
| `test_inspector_client_boundary_overrides_polluted_matrix` | حدُّ العميلِ يعلو على مصفوفةٍ ملوّثة (§37/§73/§109) |
| `test_applications_visibility_follows_view_permission` | العيبُ المُبلَّغ: apps تتبع العرضَ (§97) |
| `test_projects_and_applications_parity` | تكافؤُ projects/apps بثلاثةِ أدوار (§98) |
| `test_every_module_is_discoverable_when_view_granted` | مُنِح ⇒ مُكتشَف لكلِّ الوحدات (§31/§103) |
| `test_no_module_visible_without_grant` | لا منحَ ⇒ لا رؤية (§103) |
| `test_role_editor_covers_every_module_except_users` | كلُّ وحدةٍ في المحرّر عدا users (§16) |
| `test_no_unknown_module_keys_in_editor` | لا مفتاحَ معدوم (§28) |
| `test_saving_mutation_without_view_auto_enables_view` | الكتابةُ تستلزم العرض (§57/§58) |
| `test_role_templates_are_integrity_clean` | قوالبُ بلا مفتاحٍ معدومٍ ولا كتابةٍ بلا عرض (§28/§58/§82) |
| `test_access_diagnostics_is_owner_only` | الشاشةُ للمالك؛ الموظفُ ٤٠٣؛ العميلُ ٤٠٤ (§86) |
| `test_access_diagnostics_renders_effective_matrix_and_probe` | العرضُ والفحصُ النقطيّ (§59/§22) |
| `test_access_role_preview_is_computed_without_creating_a_user` | معاينةٌ لا انتحال (§24) |

## المُغطّى بالاختباراتِ القائمة (لا تكرار)

- عزلُ الشركات/العميل/الحقول: `CompanyIsolation*`، `WorkOsClientPortal*`، `FieldTrackIsolation`،
  `ScopeLeakAudit`، الشخصيّاتُ (`Ia/ManagerPersona*`، `Ia/MonitorPersona*`) — خضراء.
- تغطيةُ مسارات IA (تشمل `access.index`/`access.role`): `Ia/RouteCoverage*` — خضراء.
- API parity: `ApiTest`، `AdversarialAuditTest` — خضراء.

## البوّابةُ الثنائيّة (§110)

الحزمةُ الكاملةُ خضراءُ على SQLite وMySQL/MariaDB — الأرقامُ في `FINAL_REPORT.md`.
