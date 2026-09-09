# تخصيص الأصل للمشروع (03) — المحرّك

**علاقةٌ زمنيّةٌ لا عهدة** (تفاصيلُ القرار في `PLAN.md`).

## الجدول `asset_project_assignments`
`id · company_id? · asset_id · project_id · purpose? · note? · assigned_at · assigned_by? · ended_at? · ended_by? · active_flag? · timestamps · softDeletes`.

- **الحاليُّ مشتقّ:** `ended_at IS NULL` (⇔ `active_flag=1`).
- **فرادةُ النشط عبر المحرّكين:** فهرسٌ فريد `(asset_id,project_id,active_flag)` — NULL مختلفٌ في SQLite وMySQL، فالمُنهاةُ لا تتصادم والنشطُ فريد. لا فهرسٌ جزئيٌّ خاصٌّ بمحرّك.
- **التزامن:** الخدمةُ تحيط الإنشاءَ بمعاملة + `lockForUpdate`، وتلتقط 23000 فتُعيد النشطَ (idempotent).

## الخدمة `AssetProjectService`
`assign(asset,project,actor,purpose?,note?)` · `end(assignment,actor,reason?)` + قراءاتٌ محدودة.
تتحقّق: العميلُ مرفوض · شركةٌ متوافقة · حالةٌ غيرُ نهائيّة · الزوجُ النشطُ لا يُكرَّر. تُدقّق كلَّ تغيير.

## الاستقلالُ عن العهدة (§18)
لا تمسّ `holder_id`/`station_id`/`asset_custody`/النقطةَ الطرفيّة/`status`؛ والإنهاءُ لا يفكّها.

## المتحكّمات
ويب `AssetProjectController` (assignFromProject/assignFromAsset/end) + API `AssetProjectApiController` — **بخدمةٍ واحدة**. الحلُّ ضمنَ النطاق (`hub_scope`→٤٠٤)، `hub_can('assets','e')`+`hub_can('projects','v')`، العميلُ ٤٠٤.
