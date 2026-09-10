# 01 — المعمار وجردُ النظام + تدقيقُ الازدواج

## جردُ النظام (آليّاً من الكود · §5)

| العنصر | العدد | المصدر |
|---|---|---|
| نماذج Eloquent | 152 | `app/Models/*.php` |
| متحكّمات | 135 | `app/Http/Controllers/**` |
| أصنافُ خدمة/مجال (Support) | 125 | `app/Support/*.php` |
| وسطاء (Middleware) | 17 | `app/Http/Middleware/*.php` |
| هجرات | 233 | `database/migrations/*.php` |
| أوامرُ Console | 21 | `app/Console/Commands/*.php` |
| جداولُ قاعدةِ البيانات | 183 | مخطّطٌ مُهاجَر (SQLite/MySQL) |
| قوالبُ Blade | 365 | `resources/views/**` |
| وحداتُ `config/hub.php` | 85 | `hub.modules` |
| مدخلاتُ سجلِّ القدرات | 136 | `config/hub_features.php` |
| مساراتٌ كلّيّاً | ~593 | `route:list` |
| مسارات `/api/v1` | 28 | `route:list --path=api/v1` |
| مسارات `/api/mobile/v1` | 84 | `route:list --path=api/mobile` |
| مهامُّ المجدول | 11 | `routes/console.php` |
| وظائفُ الطابور (ShouldQueue) | 0 | كلُّها متزامنة (`sync`) |
| ملفّاتُ اختبارِ الميزات | 524 | `tests/Feature/*.php` |

## المحرّكاتُ الجوهريّة (سكّةٌ واحدةٌ لكلِّ مجال)

- **الصلاحيّة والتنطيق:** `hub_can()` / `hub_scope()` / `hub_field_mode()` / `hub_is_client()`
  (`app/Support/helpers.php`). المالكُ يختصر؛ وإلّا تُقرأ مصفوفةُ الدور — لأيِّ مفتاحٍ لا
  للوحداتِ وحدَها (لذلك `custody` مفتاحُ صلاحيّةٍ صالحٌ رغمَ أنّه ليس وحدةَ CRUD).
- **سجلُّ القدرات:** `App\Support\FeatureRegistry` فوق `config/hub_features.php` — محرّكٌ واحد.
- **الإعدادات:** `App\Support\Settings` (`::put`/`::get`) — محرّكٌ واحد، لا ثانيَ له.
- **التعاون:** محرّكٌ واحد (`Conversation`/`Comment`/`DmService`/`CollaborationRail`) —
  القنواتُ والمجموعاتُ والغرفُ فوقَه، والمحادثاتُ المباشرةُ عبر `DmService`.
- **رسمُ العلاقات:** `App\Support\RelationshipProjection` — محرّكٌ واحد؛ `expand()` هو
  المدخلُ الوحيد (حدود: `max_hops=3`, `max_nodes=120`, حوافٌّ تحمل `kind/rel/active`).
- **العهدة:** مجالان **منفصلان قصداً** (لا ازدواج): `App\Support\Custody` (عهدةُ الأصل —
  انتقالاتُ الحالة) و`App\Support\CustodyPostingService` (العهدةُ الماليّة — رصيدٌ مُشتَق).
- **التدقيق (Audit):** محرّكٌ واحد فوق سلسلةِ تجزئةٍ (hash-chain).
- **الكيان 360:** `Project360`/`Employee360`/`Station360`/`Asset360` — نماذجُ قراءةٍ محدودة،
  بلا كتابة، تعتمد `hub_ref_labels` لتفادي N+1.

## تدقيقُ الازدواج (§6) — النتيجة

| المجال | محرّكات | حكم |
|---|---|---|
| Settings | 1 | لا ازدواج |
| Feature Registry | 1 | لا ازدواج |
| الصلاحيّة/التوثيق | 1 | لا ازدواج |
| Audit | 1 | لا ازدواج |
| Collaboration | 1 | لا ازدواج |
| Notifications | 1 (`HubNotification`) | لا ازدواج |
| Search | 1 (`InformationArchitecture` + مزوّدو البحث) | لا ازدواج |
| Projects / Client workspace / Rooms | 1 | لا ازدواج |
| Assets / Custody / Inventory / Stations | فصلٌ مقصود بين عهدةِ الأصلِ والماليّة | مُوثَّق لا ازدواج |
| Relationship Graph | 1 (`RelationshipProjection`) | لا ازدواج |

**ازدواجاتٌ مُوثَّقةٌ كدَينٍ فنّيّ (لا محرّكاتٌ منافسة):** `ARCH-06` (قبولُ عرضِ السعر) و
`ARCH-07` (محرّكا الإقرار) — مُسجَّلان في سجلِّ الدَّين الفنّيّ، بمسارٍ واحدٍ موثوقٍ للكتابة
لكلِّ مجال. **محرّكاتٌ منافِسةٌ غيرُ مُبرَّرة = 0.**
