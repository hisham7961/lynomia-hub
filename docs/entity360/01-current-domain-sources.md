# ٠١ — المصادرُ المرجعيّةُ لكلِّ علاقة (§4)

المرجعُ الكاملُ في `INVENTORY.md`. هنا الخلاصةُ التنفيذيّة: كلُّ حافّةٍ ومصدرُها الواحد.

| العلاقة | المصدرُ المرجعيّ | نوعُ الحافّة |
|---|---|---|
| موظف → محطة (حاليّ) | `stations.current_employee_id` (→users) | مباشر · `occupies` |
| موظف → محطة (تاريخ) | `station_assignments` | مباشر/تاريخيّ |
| موظف → أصل (عهدة) | `assets.holder_id` (→users) | مباشر · `holds` |
| موظف → أصل (تاريخ) | `asset_custody` | مباشر/تاريخيّ |
| موظف → مشروع | `projects.manager_id` + `members[]` | مباشر |
| موظف → SIM | `phone_numbers.employee_id` (→employees) | مباشر · `assigned_sim` |
| موظف → مالية | `employee_custody_moves.employee_id` · رصيدٌ مشتقّ | مباشر (خلف custody:v) |
| موظف → نقطة | `endpoint_devices.employee_id` (→users) | مباشر |
| أصل → محطة | `assets.station_id` | مباشر · `located_at` |
| أصل → محطة (تاريخ) | `asset_custody.station_id` | مباشر/تاريخيّ |
| أصل → مشروع | `asset_project_assignments` (زمنيّ) | مباشر · `allocated_to` |
| أصل → نقطة | `endpoint_devices.asset_id` | مباشر · `enrolled_as` |
| أصل → جرد | `inventory_scans/items.asset_id` | مشتقّ (مسح) |
| محطة → مشروع (مباشر) | `stations.project_id` | مباشر · `station_project` |
| محطة → مشروع (مشتقّ) | محطة→أصل→تخصيصٌ نشط | **مشتقّ** · `project_via_asset` |

**جسرُ الهويّتَين:** `phones/custody/servers.hr_id` → `employees.id`؛
`assets.holder_id`/`stations.current_employee_id`/`endpoint_devices.employee_id` → `users.id`.
التجميعُ يمرّ بالمفتاحَين (`Employee::user_id`).

**لا مصدرَ مختلَق:** حيث لا تاريخَ (وضعُ محطةِ أصلٍ بلا `asset_custody.station_id`) يُقال صراحةً (§23/§33).
