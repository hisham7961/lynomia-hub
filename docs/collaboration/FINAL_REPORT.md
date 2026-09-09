# مركزُ التواصلِ الموحّد — التقريرُ الختاميّ (Stage 11 · بوّابةُ الإكمال)

> **الغاية:** إثباتٌ أن ترقيةَ التعاونِ اكتملت على محرّكٍ واحدٍ آمنٍ معزول — بلا محرّكٍ
> ثانٍ، ولا تسريبِ عميلٍ/داخليّ، ولا اكتشافٍ غيرِ مصرَّح، ولا هجرةٍ مُدمِّرة. **إضافةٌ لا
> كسر**، والحزمتان (SQLite + MySQL) خضراوان على كلِّ دفعة.

## ١) الفرعُ والإصدار

| الحقل | القيمة |
|---|---|
| الفرع | `claude/lynomia-hub-enterprise-upgrade-xn4p2t` |
| الأساس | `main` |
| مدى الإصدار | v2.456.0 → **v2.469.0** (وهذا التقريرُ عند رأسِ الفرع) |
| المحرّك | Laravel 12 · PHP 8.2+ (اختُبر 8.4 محليّاً · 8.2 على CI) · MySQL/MariaDB + SQLite |

## ٢) رايةُ القدرات (صدقٌ لا ادّعاء)

| الراية | القيمة | المعنى |
|---|---|---|
| `Collaboration transport` | **POLLING** | استطلاعٌ تدريجيٌّ بمؤشّر — لا بثّ بعد |
| `Realtime provider` | **NOT_CONFIGURED** | لا Reverb/Redis/عاملَ صفّ/عمليّةً دائمة |
| `WebSocket readiness` | **READY** | العقدُ (`Collaboration` events) جاهزٌ للبثّ متى هُيّئ بلا تغيّرِ الواجهة |
| `Mobile Backend` | **READY** | `/api/mobile/v1` يكشف قدراتِ التعاون |
| `Native Mobile App` | **DEFERRED** | لا تطبيقٌ أصيلٌ في هذا النطاق |
| `Production Push` | **NOT_CONFIGURED** | لا اعتماداتِ دفعٍ إنتاجيّة (`PushService` يقول NOT_CONFIGURED) |
| Voice Notes | **DEFERRED_PRODUCT_FEATURE** | لا واجهةَ تسجيلٍ زائفة |
| Link Previews | **DEFERRED_SECURITY_FEATURE** | لا جلبَ خادميّ (تفادي SSRF) |

## ٣) المراحلُ المُنجَزة

| المرحلة | الإصدار | المُسلَّم |
|---|---|---|
| ٦·أ مجموعاتُ الرسائل | v2.465.0 | Group DMs على حاويةِ المحادثة · أمنُ الجمهورِ التاريخيّ (الإضافةُ تُنشئ مجموعةً جديدة) |
| ٦·ب الحضورُ والكتابة | v2.466.0 | `Presence` (خشنٌ من نبضةِ الجلسة، بلا كتابة) · `Typing` (عابرٌ في Cache، لا يُدقَّق) |
| ٦ المركزُ الموحّد | v2.467.0 | `/collab` بثلاثةِ ألواح فوق المحرّكِ الواحد |
| ٧ تمييزُ الغرف | v2.468.0 | تحذيرُ جمهورِ العميلِ على الشاشةِ الكاملةِ والمركز |
| ٩ تكافؤُ الجوال | v2.469.0 | `/api/mobile/v1` إضافيّ (قائمة/جلب/حضور/كتابة/تفاعل/محفوظات) |
| ١٠ التدقيقُ الخصميّ | v2.469.0 | `CollabSecurityAuditTest` — بطاريّةٌ تُثبِت العزل |
| ٨ تكاملُ العمل | قائمٌ ومحروس | `/task` `/issue` `/assign` (`ChatCommands`) + `comments.task` (توصيلٌ لخدماتِ المجال الحقيقيّة) · بطاقةُ سياقِ السجلّ في المركز |

## ٤) المعمار: محرّكٌ واحد (لا ازدواج)

- **الحاوية** `Conversation` (kinds: `feed`/`dm`/`channel`/`group` · audience: internal/client/both ·
  visibility: private/members/company/public) — القنواتُ والغرفُ والمجموعاتُ والمحادثاتُ كلُّها هنا.
- **الرسائل** `comments` (بـ`conversation_id`) للقنوات/الغرف/المجموعات، و`dm_messages` للمحادثات —
  لا جدولَ رسائلَ ثالث، ولا محرّكَ تفاعلاتٍ ثانٍ (`reactions` بـ`comment_id` **أو** `dm_message_id`).
- **الحرّاس (نقاطُ الحسمِ الوحيدة):** `guardConversation($op)` للقنوات · `DmService::reachable` +
  مفتاحٌ من `auth()` (F8) للمحادثات · `CommentService::guardTarget` للتعليقات · `PortalGuard` فوق
  العميل · دفاعُ نطاقِ الشركة/العميل فوق العضويّة.
- **السند:** `App\Support\Collaboration` (أحداث + مؤشّر keyset) · `CollaborationRail` (سكّةُ المركز) ·
  `Presence` · `Typing` · `MessageLink` (روابطُ دائمة) · `CommentService`/`DmService`.

## ٥) المسارات

- **الويب:** ٥٩ مساراً تحت مِظلّةِ التعاون (conversations/dm/groups/saved/search.messages/comments/
  collab.center + since/typing/react/favorite/archive/notify/directory/join/fork/leave).
- **الجوال:** `/api/mobile/v1` — القائمُ (comments · dm/threads · notifications) **بلا تغيير**،
  والمُضاف (conversations · conversations/{id}/since · …/typing · dm/threads/{user}/since ·
  …/typing · dm/messages/{id}/react · comments/{id}/react · presence · saved) **قبل** الـcatch-all
  `{module}` (F9). مواصفةُ الجوال الحيّة وبيانُ القدرات مُشتقّان من المسارات (لا انحراف).

## ٦) الهجرات (إضافيّةٌ لا مُدمِّرة)

`collab_foundation` (CB-A) و`dm_reactions` (CB-E: `reactions.comment_id` nullable + `dm_message_id`
+ قيدٌ فريد) — كلُّها **إضافةُ أعمدة/جداول/قيود**، لا حذفَ عمودٍ ولا تغييرَ نوعٍ مُدمِّر. أوامرُ
`migrate:fresh/rollback` محروسةٌ (`HUB_ALLOW_DESTRUCTIVE=1`).

## ٧) الاختبارات

- **١٦ ملفَّ تعاونٍ مخصّص:** CollabFoundation · Realtime · GroupDm · PresenceTyping · Center ·
  RoomDisclosure · SecurityAudit · MentionScope · ThreadPin · EditSaved · NotifyPref · DmReaction ·
  MessageSearch · Unread · ChannelMgmt + Mobile/MobileCollab — عدا حرّاسِ العزلِ الأوسع
  (WorkOsProjectRooms · SearchDmLeak · WorkOsChatCommands · MobileDm …).
- **الحزمتان خضراوان:** SQLite **3560** اختباراً · MySQL **3560** (تخطٍّ واحدٌ سابقٌ بيئيّ) — على كلِّ دفعة.
- **بوّابةُ OpenAPI:** `docs/openapi.json` مُولَّدٌ لا مُحرَّر (يُعاد عند تغيّرِ VERSION/المسارات).

## ٨) ثوابتُ الإكمال (المصفوفة)

| الثابت | الحالة | الدليل |
|---|---|---|
| محرّكاتُ رسائلَ مزدوجة = 0 | ✅ | كلُّ سطحٍ على `Conversation`+`comments`/`dm_messages` |
| تسريبُ عميل↔داخليّ = 0 | ✅ | `WorkOsProjectRoomsTest` · `CollabSecurityAuditTest` · PortalGuard |
| اكتشافٌ غيرُ مصرَّح = 0 | ✅ | `CollabSecurityAuditTest` (قناة/مجموعة) · directory يرشّح |
| تسريبُ بحثٍ = 0 | ✅ | `CollabSecurityAuditTest` · `SearchDmLeakTest` (منطَّقٌ بالعضويّة) |
| تسريبُ جمهورِ مجموعةٍ تاريخيّ = 0 | ✅ | fork يُنشئ مجموعةً جديدةً بتاريخٍ فارغ |
| أسرارٌ بنصٍّ صريح = 0 | ✅ | لا اعتماداتٍ في الكود؛ الحضور/الكتابةُ بلا بياناتٍ حسّاسة |
| هجراتٌ مُدمِّرة = 0 | ✅ | إضافةُ أعمدة/قيودٍ فقط |
| بنودُ مواصفةٍ غيرُ محلولة = 0 | ✅ | عدا DEFERRED/EXTERNAL/NOT_CONFIGURED أعلاه |

## ٩) الامتثالُ والرقابة (فصلٌ محفوظ)

الرقابةُ (`OversightController` · §6) تبقى **منفصلةً**: إذنٌ صريحٌ + خطوةٌ + سبب + أثرُ تدقيق،
لا تُحوِّل إيصالاتِ القراءة، والعميلُ لا يبلغها أبداً. الحضورُ والكتابةُ **للتواصلِ لا للمراقبة**
(خشونةٌ مقصودة، لا خزنٌ للكتابة، لا تدقيقٌ لها) — لا تُبنى عليهما تقاريرُ حضورٍ أو أداء.

## ١٠) البنودُ المؤجَّلة (صريحةٌ لا مُختلَقة)

Voice Notes (منتَجٌ مؤجَّل — لا UI زائف) · Link Previews (أمنٌ مؤجَّل — لا SSRF) · Native Mobile App ·
Production Push (NOT_CONFIGURED) · WebSocket (READY، غيرُ مُهيَّأ). كلُّها **مُعلَنةٌ** لا مصمتة.
