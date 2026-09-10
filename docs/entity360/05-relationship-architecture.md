# ٠٥ — معماريّةُ الرسم (§45/§91)

**محرّكٌ واحد:** `App\Support\RelationshipProjection` — إسقاطٌ فوق النماذج الحيّة من خريطةِ المراجع،
لا شجرةَ مخزّنةً ولا جدولَ حوافٍّ ثانٍ. لم يُبنَ محرّكٌ آخر؛ الترقيةُ إضافيّةٌ على المواقع الثلاثة لانبعاث الحوافّ.

## الشكل (بعد الترقية)

```
expand($module,$id,$hops=null,$fresh=false,$history=false): ?array
→ { root, hops, max_hops, max_nodes, capped, history, nodes[], edges[] }
```
- **عقدة:** `{key, module, id, label, hop, counts}` — بلا تغيير.
- **حافّة:** `{from, to, via, label, kind, rel[, active, since, ended]}` — **`kind`/`rel` جديدان**؛ والزمنيّةُ (asset_project) تحمل `active/since/ended`.

## أنواعُ الحوافّ

1. **أماميّة (مراجعُ السجل):** من حقول `ref`. `kind=direct`، `rel` من خريطة `REL` (holds/located_at/occupies/…).
2. **عكسيّة (`hub_children`):** من يشير للسجل. `kind=direct`.
3. **زمنيّة (`asset_project`):** من `asset_project_assignments`. `kind=direct`، `rel=allocated_to`، `active/since/ended`. النشطُ فقط افتراضاً؛ ووضعُ التاريخ يُدرج المُنهاة موسومةً `active=false`.
4. **مشتقّة (`station_project_derived`):** المحطة→المشروع عبرَ أصلٍ مخصَّص. `kind=derived`، `rel=project_via_asset`. **ورقةٌ لا تُوسَّع** (لا انفجارَ طوبولوجيا).

## سجلُّ المصدر (§91)

لا سجلَّ علاقاتٍ مركزيٌّ في `config/hub.php` — المراجعُ حقولٌ في كلِّ وحدة (`type=ref, ref, col`).
الترقيةُ **مدّدت** هذا النموذجَ (خريطةُ `REL` الدلاليّة + كتلتا asset_project/derived الموسومتان) بدلَ سجلٍّ ثانٍ.

## الحدود (§50/§51)

`graph.max_hops`(٣)/`graph.max_nodes`(١٢٠) من الإعدادات · `capped` صريحٌ لا اقتطاعَ صامت ·
العدّادُ مُرشَّحٌ دقيقٌ ولو لم يُتوسَّع · المخبَّأُ معزولٌ بقارئه (`hub_scope_key`) + وضعِ التاريخ.
