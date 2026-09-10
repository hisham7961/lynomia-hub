# الجرد — المصادر المرجعيّة للكيان 360 (Employee/Station/Asset + الرسم)

> **قاعدةٌ حاكمة (§3/§4):** كلُّ علاقةٍ تُعرَض في صفحة 360 تأتي من مصدرها الدلاليّ الواحد.
> لا عمودَ حقيقةٍ مُدنوَرل جديد، ولا محرّكَ ثانٍ. هذا الطورُ **تجميعٌ وسياق** لا بناء.

## ٠) الاكتشاف — كيف جُمع هذا الجرد

خمسةُ مسوحٍ متوازية قرأت الكود (نماذج/هجرات/متحكّمات/عروض/إعدادات): سطحُ الموظف،
سطحُ المحطة، سطحُ الأصل، مستكشفُ العلاقات، والمجالاتُ العابرة (اتصالات/مالية/نقاط/جرد/IA/قدرات/بحث).

## ١) الموظف (Employee) — **هويّتان** جسرُهما `employees.user_id`

| المفهوم | المصدر المرجعيّ | العمود/المسار |
|---|---|---|
| الملفُّ الوظيفيّ (HR) | `App\Models\Employee` · جدول `employees` · module **`hr`** | `hub_mod('hr')` |
| حسابُ الدخول | `App\Models\User` · جدول `users` (module `users` إداريّ، و`m.show` له **٤٠٤**) | `Employee::user()` عبر `user_id` |
| الملفُّ الشامل 360 (قائم) | **`PortalController::employee`** → `resources/views/portal/employee.blade.php` | `route('portal.employee', $emp->id)` — حارسه `hub_can('hr','v')` + `hub_scope(Employee,'hr')` |
| المحطةُ الحاليّة | `stations.current_employee_id` → **users.id** | `PortalController::stationsFor($u,$emp->user_id)` |
| تاريخُ المحطات | جدول `station_assignments` (append-only، `action` assign/vacate) | `StationAssignment where user_id=$emp->user_id` |
| العهدةُ (الأصولُ بيده) | `assets.holder_id` → **users.id** (مقفول، يُكتب عبر `Custody`) | `assets where holder_id=$emp->user_id` |
| تاريخُ العهدة | جدول `asset_custody` (append-only) | `AssetCustody where user_id=$emp->user_id` |
| المشاريع | `projects.manager_id` + `projects.members[]` (JSON) — **لا جدولَ أعضاء** | `User::visibleProjectIds()` |
| المهامّ | `tasks.assignee_id` → users.id | `bundle()` + `ExecutionStats::person` |
| الاتصالات (SIM) | `phone_numbers.employee_id` → **employees.id (hr)** | `PortalController::phonesFor($u,$emp->id)` |
| العهدةُ المالية | `employee_custody_moves.employee_id` → **employees.id** · الرصيدُ **مشتقٌّ** `Employee::custody_balance` = `SUM(sign*amount)` | حارسه `hub_can('custody','v')` |
| النقاطُ الطرفية | `endpoint_devices.employee_id` → **users.id** | `PortalController::endpointDevicesFor($u,$emp->user_id)` |
| الأنظمة | `servers.hr_id` → employees.id | `PortalController::serversFor($u,$emp->id)` |
| النشاط | `hub_timeline` · `ExecutionStats::personTimeline` (للمراقب) | — |

**نكتةُ الجسر الحاسمة:** `phone_numbers.employee_id`/`servers.hr_id`/`custody.employee_id` → **employees.id**، بينما
`assets.holder_id`/`stations.current_employee_id`/`endpoint_devices.employee_id` → **users.id**. فالتجميعُ يمرّ
بالمفتاحَين معاً (كما يفعل `PortalController` أصلاً).

## ٢) المحطة (Station) — module `stations`، العرضُ `code` (لا `name`)

| المفهوم | المصدر | العمود/المسار |
|---|---|---|
| الهويّة/الكود/QR | `stations.code` (فريد، `ST-YEAR-SEQ`) · `Qr::svg(route('stations.code',$code))` · مسحٌ `GET s/{code}` | — |
| الشاغلُ الحاليّ (**واحد**) | `stations.current_employee_id` → users.id (مقفول) | `Station::currentEmployee()` |
| تاريخُ الشاغلين | `station_assignments` (assign/vacate، `at`, `by_id`, `note`) | `Station::assignments()` |
| الأصولُ الحاليّة | `assets.station_id` → stations.id (مقفول، يُكتب عبر `Custody::assignStation`) | `Asset where station_id=$id` |
| **تاريخُ الأصول** (موجود!) | `asset_custody.station_id` (يُختَم في كلِّ حركة، `action` إسناد/إخلاء محطة) | `AssetCustody where station_id=$id` |
| النقاطُ الطرفية | `endpoint_devices.station_id` → stations.id (مباشر) | `EndpointDevice::station()` |
| الجرد | **لا `station_id`** — عبر `assets.station_id` → `inventory_items/scans.asset_id` | مشتقٌّ |
| المشروع | `stations.project_id` → projects.id (**مباشر**) + مشتقٌّ عبر الأصول | `Station::project()` |
| الكتابةُ (إسناد/إخلاء) | `StationController::assign/vacate` (قفل+معاملة، لا service منفصل) | `stations.assign` / `stations.vacate` |

العرضُ القائم: `resources/views/modules/custom/stations.blade.php` (هويّة+QR، إشغال+نماذج، تاريخ) — **بلا** أصول/نقاط/جرد/مشروع (فجوةُ 360).

## ٣) الأصل (Asset) — module `assets`، العرضُ `name`

| المفهوم | المصدر | العمود/المسار |
|---|---|---|
| الهويّة | `assets.code` (فريد) + `record_identifiers` (`Identity::of`) + `Barcode::svg` | — |
| الحائزُ الحاليّ (**عهدة**) | `assets.holder_id` → users.id (مقفول) | `Asset::holder()` |
| تاريخُ العهدة | `asset_custody` (تسليم/استرداد/نقل...) | `Asset::custodyLog()` · `Custody::history($id)` |
| المحطة | `assets.station_id` → stations.id (مقفول) | `Asset::station()` |
| **تاريخُ المحطة** | `asset_custody.station_id` (إسناد/إخلاء محطة) | `AssetCustody where asset_id AND station_id NOT NULL` |
| المشاريع (**تخصيصٌ لا عهدة**) | `asset_project_assignments` (زمنيّ، `active_flag`) | `AssetProjectService::activeForAsset/historyForAsset` |
| النقطةُ الطرفية | `endpoint_devices.asset_id` | `Asset::activeEndpoint()` · الأهليّة `endpointEligible()` |
| الجرد | `inventory_items.asset_id` / `inventory_scans.asset_id` (لا عمودَ محطة) | مشتقٌّ: آخرُ مسحٍ `InventoryScan where asset_id order at desc` |
| الدورة | `buy_date/warranty/maint/life/disposal/price` + `AssetMaintenance` | إهلاكٌ خطّيّ (قائمٌ في العرض) |
| الكتابة | `App\Support\Custody` (`move`/`permit`/`transition`/`assignStation`) + `AssetProjectService` | `holder_id/station_id/status` **مقفولة** — قراءةٌ فقط في 360 |

**الفصلُ الثلاثيّ (§30):** الحائز (holder_id/custody) ≠ المحطة (station_id) ≠ تخصيصُ المشروع (asset_project_assignments) — ثلاثةُ مفاهيمَ لا تُخلَط.

العرضُ القائم: `resources/views/modules/custom/assets.blade.php` — كومةُ بطاقاتٍ (custody_card + asset_projects + هويّة + إهلاك) فوقَ الجسد العامّ.

## ٤) الرسم (RelationshipProjection) — محرّكٌ **واحد**

- `App\Support\RelationshipProjection::expand($module,$id,$hops,$fresh): ?array`. الشكل: `{root,hops,max_hops,max_nodes,capped,nodes,edges}`.
- عقدة: `{key,module,id,label,hop,counts}` · حافّة: `{from,to,via,label}` **فقط اليوم** — لا `kind`/`direct`/`derived`/`active`.
- الحدود: `graph.max_hops`(٣)/`graph.max_nodes`(١٢٠). التصريحُ للعقدة عبر `hub_read` (طرفان مقروءان ⇒ حافّة). `capped` صريح.
- الحوافّ من: (أ) حقولُ `ref` أماماً، (ب) `hub_children` عكساً، (ج) كتلةُ `asset_project` (`::active()` فقط، `via='asset_project'`).
- المستكشف: `graph.explore`/`graph.expand` (حارسٌ `guardInternal`: عميلٌ أو معزولٌ بعملاء ⇒ ٤٠٤). عرضان: شجرةٌ `<ul>` + راسمٌ SVG محلّيّ (`public/vendor/lynomia-graph/graph.js`) — بلا مكتبة. الأزرارُ اليوم: العمق/تحديث/الوضع/العروضُ المحفوظة.
- **لا سجلَّ علاقاتٍ مركزيّ** في `config/hub.php` — المراجعُ حقولٌ في كلِّ وحدة (`type=ref, ref, col, multi?, edge?`).

## ٥) IA + سجلُّ القدرات + البحث

- **IA** (`config/hub_ia.php`): hr→`hr/files` (والملفّ الشامل عبر مقصدِ `portal.employee`)، stations→`digital/infra`، assets→`legalws/assets`. **كلُّها مساراتٌ قائمةٌ مصنّفة** — لا مسارَ GET جديدٌ لصفحات 360.
- **سجلُّ القدرات** (`config/hub_features.php`): `workos.employee_360` (ENABLED)، `workos.project_360`، `workos.relationship_explorer` (web_routes `graph.explore`)، `workos.stations/station_identity/station_history`، `assets.*` — **قائمة**. **الجديدُ فقط:** `assets.asset_360` و`workos.station_360` (§65). لا `workos.relationship_views` (مكرِّرٌ لـ`relationship_explorer`).
- **البحث/اللوحة**: السجلُّ → `m.show[module,id]`. الموظف→`m.show[hr]` (وفيه زرُّ «الملف الشامل»)، المحطة→`m.show[stations]`، الأصل→`m.show[assets]`.

## ٦) الخصوصيّة والأمن (مثبَّتٌ بالكود)

- **لا تخابرَ اقتحاميّ أصلاً:** لا عمودَ لقطةِ شاشةٍ/keylog/كاميرا/ميكروفون/حافظة في أيِّ جدول نقاط؛ و`EndpointPrivacy::FORBIDDEN` يرفض ابتلاعَها (٤٢٢). فبطاقاتُ النقاط في 360 آمنةٌ ببنيتها (§18/§79).
- الأسرار: `pin/puk/portal_password/public_key/hw/posture` لا تُنتقى/تُقنَّع — العروضُ تُظهر الآمنَ فقط.
- كلُّ قسمٍ خلف `hub_can`+`hub_scope`+`hub_field_mode`؛ والعميلُ محجوبٌ (`hub_is_client`) عن كلِّ أسطح 360 الداخليّة.

## ٧) قرارُ الهجرة

**لا هجرةَ جديدة.** كلُّ علاقةٍ يطلبها الطورُ لها مصدرٌ قائم (جدولٌ أو عمود). §90 مستوفاة: تجميعٌ لا سكيمة.
