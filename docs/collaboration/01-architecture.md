# هندسةُ التواصل — المحرّكُ الواحد وأوليّاتُه (01)

> **المرحلةُ ٢ (§103).** تقسيةُ المجال فوق المحرّك القائم — **لا محرّكَ رسائلَ ثانٍ**.

## المحرّكُ الواحد (يُحفَظ)

```
Conversation (حاوية: kind · audience · visibility · company/client/project · module/record)
        │  (conversation_id)
        ├── comments      → خلاصةُ الفريق · رسائلُ القنوات · تعليقاتُ السجلّات
        └── dm_messages   → الرسائلُ المباشرة (thread_key زوجيّ · conversation uuid5 حتميّ)

ConversationMember (role · source · last_read_at · muted_at · favorite_at · notify_pref)
reactions (comment) · saved_messages (شخصيّ) · HubNotification (إشعارٌ واحد · كتمٌ عند المصدر)
```

النصُّ لا يُنسَخ إلى جدولٍ جديد: `Conversation::messages()` تقرأ من `comments`.
الأدوارُ داخل الحاوية (owner/moderator/member/guest) عضويّةٌ لا بديلٌ عن `Role` —
«من يقدر» يحسمه `hub_can`، و«من عضوٌ هنا» تحسمه العضويّة.

## أوليّاتُ المرحلة ٢ (إضافيّةٌ عكوسة · هجرة `2026_09_24_000001`)

| الأوليّة | المكان | المواصفة |
|---|---|---|
| `favorite_at` | `conversation_members` | §15 نجمةٌ شخصيّة (لا تمسّ العضويّة) |
| `notify_pref` (all/mentions/muted) | `conversation_members` | §16 — «muted» متزامنٌ مع `muted_at` (كتمٌ واحد) |
| `edited_at` | `comments` + `dm_messages` | §22 ختمُ تحريرٍ صادق |
| `reply_count` · `last_reply_at` | `comments` | §19 عدّادُ الخيط للعرض (يُصان عند الرد لا بالاستعلام) |
| `pinned_at` · `pinned_by` | `comments` | §28 بيانُ التثبيت فوق `pinned` |
| `saved_messages` (جدول) | جديد | §27 محفوظاتٌ شخصيّة (مرجعٌ لا نسخُ محتوى، تخويلٌ عند الفتح) |

## عقدُ الزمن الحقيقيّ (`App\Support\Collaboration`)

قرارُ §38/§39 (لا بنيةَ بثٍّ + استضافةٌ عاديّة بلا عمليّةٍ دائمة): **استطلاعٌ
تدريجيٌّ بمؤشّر `since`** مع **عقدِ أحداثٍ مستقرّ** يستهلكه العميلُ سواءٌ وصله
استطلاعاً أو — مستقبلاً — بثّاً، بلا كسرِ عقده:

- `EVENTS`: `message.created` · `message.updated` · `message.deleted` ·
  `reaction.changed` · `read.updated` · `typing.started/stopped` · `conversation.updated`.
- `encodeCursor/decodeCursor`: keyset حتميّ على `(created_at, id)` — base64url،
  فاسدٌ ⇒ null (من البداية بأمان)، لا OFFSET (أداء §71). نظيرُ مؤشّر الإشعارات القائم.
- `NOTIFY_PREFS`/`normalizeNotifyPref`: تطبيعُ تفضيل الإشعار.

## مبادئُ التنفيذ

إضافيٌّ عكوسٌ (§17) · العزلُ خادميّ (الحارسان + audience) · المحرّكُ الواحد ·
الحزمتان خضراوان لكلّ دفعة · العربيّةُ أولاً (RTL). المرحلةُ ٢ **لا تلمس الواجهة** —
الأوليّاتُ فقط، والميزاتُ تُبنى عليها في المراحل التالية.
