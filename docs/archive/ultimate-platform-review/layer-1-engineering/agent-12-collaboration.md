# الوكيل ١٢ — التعاون (طبقة ١ · الجولة أ)

> المستودع: `/home/user/lynomia-hub` · الرأس المفحوص: `c06a945` (النسخة `2.540.1`).
> النطاق: `app/Support/Collaboration.php` · `DmService.php` · `CollaborationRail.php` ·
> `ChatCommands.php` · `CommentService.php` · `MessageLink.php` · متحكّمات `Web/`
> (`CollaborationController` · `ConversationController` · `CommentController` ·
> `DmController` · `MessageSearchController` · `SavedController` · `GroupController` ·
> `OversightController` · `FileController` · `ClientPortalController`) ·
> `Api/MobileCollabController.php` · `Http/Middleware/PortalGuard|MobilePortalGuard` ·
> `routes/web.php` · `routes/api.php`.
>
> **قراءةٌ فقط.** لم يُعدَّل من المستودع إلّا هذا الملفّ. كلُّ اختبارات الاستكشاف
> كُتبت ونُفِّذت في `…/scratchpad/probe/` خارج شجرة المشروع
> (`ZZProbeA12Test` … `ZZProbeE12Test`، ٢١ مسباراً، جميعها على `phpunit.xml` / sqlite).

---

## ٠. الخلاصة التنفيذيّة

**سطحُ التعاون محروسٌ حراسةً جيّدةً عند أبوابه الرئيسة، ومثقوبٌ عند أطرافه.**

الأبوابُ الأربعةُ الكبرى — `guardConversation` (العضويّة + نطاق الشركة/العميل +
صلاحية وحدة السجلّ)، و`dmReachable`، واشتقاقُ `thread_key` من `auth()` لا من العميل،
و`PortalGuard`/`MobilePortalGuard` — **صمدت أمام كلّ ما هاجمتُها به** (١٦ محاولةً
موثَّقةً في الفصل ٣). ولا يوجد في هذا السطح ولا مسارٌ واحدٌ يقبل `thread_key` من
العميل: `DmMessage::threadKey($a,$b)` يُستدعى في **كلّ** كاتبٍ وقارئٍ من
`auth()->id()` + معرّفِ الطرفِ الآخر (ويباً وجوّالاً)، والحلقةُ مقفلة.

لكنّ **القنوات الجانبيّة** — البحث، والمحفوظات، وبوابة الملفّات، وتحويلُ رسالةٍ إلى
مهمّة، وقائمةُ الإشعارات، وطريقٌ واحدٌ في القائمة البيضاء للجوال — **لا تمرّ بهذه
الأبواب**، وكلٌّ منها يُعيد بناء التخويل بيدِه أو لا يبنيه. النتيجةُ خمسةُ تسريباتٍ
مُثبَتةٍ بالتشغيل (لا استنتاجاً)، أخطرُها أنّ **نصَّ رسالةِ قناةٍ تُردُّ عنها ٤٠٤
يُقرأ كاملاً من `/search/messages`**.

**عدد التسريبات المُثبَتة في سطح التعاون: ٥** (`A12-01` · `A12-03` · `A12-04` ·
`A12-06` · `A12-07`)، بالإضافة إلى **كتابةٍ خارجيّةٍ مُثبَتة** (`A12-08`) و**عمى
رقابيٍّ مُثبَت** (`A12-09`) و**بوّابةٍ مغلقةٍ خطأً** (`A12-05`).

| # | الخطورة | العنوان | الموضع |
|---|---|---|---|
| A12-01 | **P1** | بحثُ الرسائل يتجاوز دفاعَ نطاقِ الشركة: نصُّ قناةٍ تُردّ عنها ٤٠٤ يُقرأ | `MessageSearchController.php:45` |
| A12-02 | **P1** | `addMember` بلا فحصِ نطاقٍ ولا نوعِ حساب — عضويّةٌ واحدةٌ خاطئة = تاريخٌ كامل | `ConversationController.php:565` |
| A12-03 | **P1** | تحويلُ رسالةِ قناةٍ خاصّةٍ إلى مهمّة ينقل نصَّها حرفيّاً إلى جمهور `tasks` | `CommentController.php:261` |
| A12-04 | **P2** | مرفقُ منشورِ خلاصةٍ موسومٍ بشركة يُخدَم لأيِّ مصادَق (`feed ⇒ return true`) | `FileController.php:288` |
| A12-05 | **P2** | مرفقاتُ القنوات/المجموعات ميتةٌ ٤٠٣ حتى لأعضائها — الحارسُ لا يعرف `module=channel` | `FileController.php:284-291` |
| A12-06 | **P2** | تحريرُ رسالةٍ مباشرة لا يُنظّف النصَّ القديم من جرسِ المستلم | `DmService.php:74` |
| A12-07 | **P2** | المحفوظاتُ بلا `guardFeedComment`: منشورُ شركةٍ أخرى يُحفَظ ويُعرَض نصُّه | `SavedController.php:84` |
| A12-08 | **P2** | `mobile.comments.react` في القائمة البيضاء لحساب العميل بلا تشديدٍ عميليّ | `MobilePortalGuard.php:68` |
| A12-09 | **P2** | «رسالةُ ظلٍّ» في حاويةِ DM: يراها البحثُ ولا تراها الرقابةُ ولا شاشةُ المحادثة | `CommentController.php:101` |
| A12-10 | **P2** | القناةُ المؤرشفةُ لا تُقرأ لأحد — والوعدُ المكتوب «يبقى تاريخُها» | `ConversationController.php:54` |
| A12-11 | **P2** | حذفُ رسالةِ قناةٍ: بلا أثرِ تدقيقٍ وبلا شاهدٍ للقارئ (اختفاءٌ صامت) | `CommentController.php:214` |
| A12-12 | **P3** | `/assign` يُسنِد لأيِّ مستخدمٍ في المنظّمة — بما فيهم حسابُ عميلٍ خارجَ النطاق | `ChatCommands.php:206` |
| A12-13 | **P3** | شارةُ «غير المقروء» غيرُ منطَّقةٍ بالشركة بينما القائمةُ منطَّقة | `DmController.php:527` |
| A12-14 | **P3** | `DmService::thread` بلا `inCompanyScope` — الخيطُ يُظهر ما تُخفيه القائمةُ والاستطلاع | `DmService.php:171` |
| A12-15 | **P3** | صندوقُ الرسائل يعرض حساباتِ العملاء وجهاتٍ — رسالةٌ إلى ثقبٍ أسود | `DmController.php:146` |
| A12-16 | **P3** | التاريخُ عند الانضمام غيرُ مُعلَنٍ في أيِّ سطحٍ للمستخدم | `views/conversations/directory.blade.php` |
| A12-17 | **P3** | غرفُ المشاريع (§9) مبنيّةٌ ولا يستدعيها الإنتاج، ولا مسارَ يُدخِل العميلَ غرفتَه | `ConversationController.php:108` |

---

## ١. الملاحظات

### A12-01 · **P1** · بحثُ الرسائل يتجاوز دفاعَ نطاقِ الشركةِ الذي يفرضه `guardConversation`

**الدليل.** `MessageSearchController::index` يبني نطاقَ القنوات من العضويّة **الخام**:

```php
// app/Http/Controllers/Web/MessageSearchController.php:45-48
$memberConvIds = ConversationMember::where('user_id', $me)->pluck('conversation_id');
$chan = Comment::whereNull('deleted_at')->where('module', 'channel')
    ->whereIn('conversation_id', $memberConvIds)->where('body', 'LIKE', $like)
```

بينما **كلُّ** قارئٍ آخر للقنوات يضع فوق العضويّة دفاعَ نطاقِ الشركة/العميل:
`guardConversation` (`ConversationController.php:64-71`)، و`ConversationController::index`
(`:170-177`)، و`CollaborationRail::forUser` (`CollaborationRail.php:43-50`). وتعليقُ
الحارس نفسِه يسمّي الخطرَ حرفاً: «عضويّةٌ خاطئةٌ لحاويةِ شركةٍ/عميلٍ خارجَ نطاق القارئ
**لا تُسرّب صفّاً**». البحثُ هو الاستثناءُ الوحيد. (وهو كذلك لا يُرشّح `deleted_at`
ولا `archived_at` على الحاوية.)

**الإعادة** (مسبار `ZZProbeA12Test::test_probe_search_bypasses_company_scope`):

1. شركتان `أ` و`ب`؛ مستخدمٌ `outsider` مقيَّدٌ بـ`ب`.
2. قناةٌ `company_id = أ` فيها رسالة «سرُّ شركةِ ألف زئبق».
3. مالكٌ غيرُ مقيَّد يُضيف `outsider` عضواً (مقبولٌ — راجع `A12-02`).
4. `GET /conversations/{id}` بهويّة `outsider` ⇒ **404** (الحارسُ يعمل).
5. `GET /search/messages?q=زئبق` بهويّة `outsider` ⇒ **200 والنصُّ كاملٌ في الصفحة**.

```
[A] addMember status: 302
[A] member row exists: YES
[A] conversations.show status for outsider: 404
[A] search status: 200
[A] search LEAKS body: YES *** LEAK ***
```

**الإصلاح.** استخراجُ مُرشِّحِ النطاق الموجودِ في `ConversationController::index` إلى
دالّةٍ ساكنةٍ واحدة (`ConversationController::scopedMemberConversationIds(User)`)
تُطبّق: العضويّة + `whereNull('deleted_at')` + نطاقَ `hub_company_ids`/`hub_client_ids`،
ثم استدعاؤها في `MessageSearchController` (في استعلام النتائج **وفي استعلام العدّ**
`:91` معاً) وفي كلِّ قارئٍ لاحقٍ يبني نطاقاً من `conversation_members` مباشرة.
البديلُ الأضعفُ — ضمُّ `conversations` في الاستعلام وترشيحُه — مقبولٌ لكنّه يكرّر المنطق.

**اختبارُ الانحدار.** `test_message_search_respects_company_scope_over_membership`:
عضويّةٌ في قناةِ شركةٍ خارج نطاق القارئ ⇒ `conversations.show` ‏٤٠٤ **و**
`search/messages` لا يحوي النصَّ ولا يعدّه في «N نتيجة». (يُؤكَّد على العدّ أيضاً:
`assertSee('٠ نتيجة')` أو ما يعادله، فالعدُّ مسارٌ ثانٍ بالعيب نفسِه.)

**الثقة: عالية جداً** — مُثبَتٌ بالتشغيل، والتفاوتُ مع ثلاثةِ قرّاءٍ نظراءَ حرفيّ.

---

### A12-02 · **P1** · `addMember` بلا فحصِ نطاقٍ ولا نوعِ حساب — عضويّةٌ واحدةٌ خاطئة = تاريخٌ كامل

**الدليل.**

```php
// app/Http/Controllers/Web/ConversationController.php:565-566
$target = User::whereNull('deleted_at')->find($data['user_id']);
abort_unless($target, 422, 'لا مستخدمَ بهذا المعرّف');
```

هذا **كلُّ** ما يُفحَص في الهدف. لا `DmController::dmReachable($target, $actor)`،
ولا `hub_is_client($target)`، ولا تحقّقٌ من أنّ الهدفَ في نطاقِ الحاوية. قارِن:
- `DmController::reachableColleagues` (`:158`) يُسقط العملاءَ ويفرض `dmReachable`؛
- `GroupController::validateParticipants` (`:138-152`) يفرض الاثنين صراحةً ويردّ ٤٢٢.

فمنتقي «إضافة أشخاص» في الواجهة مبنيٌّ على `reachableColleagues` (آمن)، لكنّ
**المسارَ** لا يُعيد فحصَ ما يصله — و«لا تثق بالدليل» قاعدةٌ مطبَّقةٌ في
`ConversationController::join` (`:305-315`) ومهملةٌ هنا.

وأثرُ ذلك مضاعَفٌ لأن **الانضمامَ يفتح كلَّ التاريخ**: لا `since_at` على العضويّة،
و`Conversation::rootMessages()` يقرأ الحاويةَ من أوّلها. فعضويّةٌ خاطئةٌ واحدة =
تسريبٌ تاريخيٌّ كامل، وهو ما تحميه `GroupController` بـ«الإضافة تُنشئ مجموعةً
جديدة» (`ConversationController:557-559` يردّ `kind=group` بـ٤٢٢) — والقناةُ بلا هذا الدرع.

**الإعادة** (مسباران):
- `ZZProbeA12Test::test_probe_search_bypasses_company_scope` — إضافةُ مستخدمٍ من شركةٍ
  أخرى تنجح (302 + صفُّ عضويّة) رغم أنّ الحارسَ سيردّه ٤٠٤.
- `ZZProbeD12Test::test_probe_client_member_of_internal_channel` — إضافةُ **حسابِ عميل**
  (`account_type=client`) إلى قناةٍ `audience=internal` تنجح:
  `[Q] addMember(client) status: 302 · row: YES`.

**ما لم يتسرّب (مهمّ).** حسابُ العميلِ المُضافُ إلى قناةٍ **داخليّة** لا يبلغها:
`/conversations/{id}` ⇒ ٤٠٤ (PortalGuard)، وقائمةُ البوّابة `ClientPortalData::conversationRows`
تُرشّح `audience ∈ {client, both}` فلا يظهر عنوانُ القناة (مُثبَتٌ في `ZZProbeE12Test`:
`portal list shows internal channel title: no (held)`). فالعيبُ اليومَ **باب** لا تسريبٌ
مباشر — لكنّه البابُ الذي يفتح `A12-01`، ويصير تسريباً مباشراً في الحالة التالية:

قناةٌ `audience=both` و`client_id = null` (وكلاهما مقبولٌ في `store:359-378`: `client_id`
اختياريٌّ ولا يُشترط) + `addMember(حساب عميل)` ⇒ **العميلُ يقرأ الحاويةَ كلَّها**:

```
[Q2] portal shows audience=both, client_id=null channel: YES
[Q2] detail status: 200 · shows body: YES
```

**الإصلاح.** في `addMember`، بعد إيجاد `$target` وقبل إنشاء العضويّة:
1. `abort_unless(DmController::dmReachable($target, $user), 422, 'مستخدمٌ خارجَ نطاقك')`؛
2. إن كان `$conv->audience === 'internal'`: `abort_if(hub_is_client($target), 422,
   'حسابُ عميلٍ لا يُضاف إلى محادثةٍ داخليّة')`؛
3. إن كان `hub_is_client($target)`: اشتراطُ أن يكون `$conv->client_id` غيرَ فارغٍ وضمنَ
   `hub_client_ids($target)` — فلا يُضاف عميلٌ إلى غرفةِ عميلٍ آخر ولا إلى غرفةٍ بلا عنوان؛
4. وفي `store`: `client_id` **إلزاميٌّ** متى كان `audience ∈ {client, both}`.

**اختبارُ الانحدار.** `test_add_member_rejects_out_of_scope_and_client_targets`: ثلاثُ
حالات (خارج الشركة ⇒ ٤٢٢ · عميلٌ في قناةٍ داخليّة ⇒ ٤٢٢ · عميلُ شركةٍ أخرى في غرفةِ
عميل ⇒ ٤٢٢)، مع التأكيد على **غياب صفِّ العضويّة** بعد كلّ ردّ.

**الثقة: عالية جداً** — مُثبَتٌ بالتشغيل، والتفاوتُ مع نظيرَين في المستودع نفسِه حرفيّ.

---

### A12-03 · **P1** · تحويلُ رسالةِ قناةٍ خاصّةٍ إلى مهمّة ينقل نصَّها حرفيّاً إلى جمهور `tasks`

**الدليل.**

```php
// app/Http/Controllers/Web/CommentController.php:224-262
public function toTask(string $id) {
    abort_unless(hub_can(auth()->user(), 'tasks', 'a'), 403, …);
    $c = Comment::findOrFail($id);
    $this->guardTarget($c->module, $c->record_id);      // عضويّةُ القناة — صحيحة
    …
    'description' => $c->body . "\n\n— حُوّلت من تعليق بواسطة " . auth()->user()->name,
```

حارسُ الهدفِ صحيحٌ على **الفاعل** (عضوٌ في القناة). لكنّ المهمّةَ المُنشأةَ تُحكَم
بعد ذلك بمصفوفة `tasks` ونطاقِ الشركة — **لا بعضويّةِ القناة**. والوراثةُ
(`:249-258`) تعتمد `hub_mod($c->module)`، و`'channel'` **ليست وحدةً مسجَّلة** في
`config/hub.php` (تحقّقتُ: لا مفتاحَ `channel` في `modules`) ⇒ لا يُورَث شيءٌ من
الحاوية، فتقع المهمّةُ في شركةِ **المحوِّل** ويراها كلُّ حاملِ `tasks:v` فيها.

فقناةٌ خاصّةٌ من ثلاثةِ أعضاءٍ يصير نصُّها مقروءاً لكلِّ من يملك `tasks:v` — بنقرةٍ
واحدة، بلا تحذير، وبلا أثرِ تدقيقٍ يقول إنّ نصّاً خرج من حاويةٍ خاصّة.

**الإعادة** (`ZZProbeB12Test::test_probe_channel_message_to_task`):

```
[H] non-member show: 404
[H] toTask status: 302
[H] task description: راتبُ المدير الجديد 9500 دينار — سرٌّ زئبق
[H] non-member reads channel secret via task: YES *** LEAK ***
```

**الإصلاح** (ثلاثُ درجاتٍ، أقلُّها الأولى):
1. **لا نسخَ نصٍّ من حاويةٍ خاصّة**: حين `$c->module === 'channel'`، يكون وصفُ المهمّة
   رابطاً ومقتطفاً قصيراً بإذنِ المستخدم، لا النصَّ الكامل — أو `description` تُترك
   فارغةً و`Comment.task_id` هو الرابط (المسارُ القائم أصلاً).
2. **وراثةُ نطاقِ الحاوية**: `ChatCommands::inheritScope` يعرف بالفعل كيف يقرأ
   `company_id`/`client_id`/`project_id` من `Conversation` حين `module === 'channel'`
   (`ChatCommands.php:236-241`) — يُعاد استعمالُها هنا بدل فرع `hub_mod` الميّت.
3. **إعلانٌ وأثر**: تأكيدٌ في الواجهة («سيُنسَخ نصُّ الرسالة إلى مهمّةٍ يراها كلُّ من
   يملك عرضَ المهامّ») + `hub_audit('channel.message_exported', 'conversations', $conv->id)`.

**اختبارُ الانحدار.** `test_channel_message_to_task_does_not_leak_body_to_non_members`:
رسالةٌ في قناةٍ خاصّة ⇒ تحويلٌ بعضوٍ ⇒ `assertDontSee(نصُّ الرسالة)` على `m.show tasks`
بهويّةِ غيرِ عضوٍ يملك `tasks:v`.

**الثقة: عالية جداً** — مُثبَتٌ بالتشغيل من طرفٍ إلى طرف.

---

### A12-04 · **P2** · مرفقُ منشورِ خلاصةٍ موسومٍ بشركة يُخدَم لأيِّ مصادَق

**الدليل.**

```php
// app/Http/Controllers/Web/FileController.php:284-291
foreach (DB::table('comments')->whereNull('deleted_at')->where('att', $path)
            ->get(['module','record_id','user_id']) as $c) {
    if ((string) $c->user_id === (string) $u->id) return true;
    $mk = (string) $c->module;
    if ($mk === 'feed') return true;      // ← قناةٌ عامّة لكل مصادَق
```

التعليقُ يقول «قناةٌ عامّةٌ لكلِّ مصادَق» — وهذا لم يعد صحيحاً منذ الطور A: منشورُ
الخلاصةِ يُوسَم بـ`comments.company_id` (`CommentService::create:131-135`)،
و`CommentController::feedCompanyFilter` (`:69-76`) و`CommentService::guardFeedComment`
(`:66-72`) يعزلانه. فالبوّابةُ تُبيح **بايتات** ما يعزل قارئُه **نصَّه**.

**الإعادة** (`ZZProbeC12Test::test_probe_mayread_matrix`، استدعاءُ `mayRead` مباشرةً
بانعكاسٍ كي لا يُخلَط ٤٠٤ «الملفُّ غيرُ موجودٍ على القرص» بـ٤٠٣ «ممنوع»):

```
[G2] feed att · stranger(other company) mayRead: TRUE *** LEAK ***
```

(الناشرُ مقيَّدٌ بشركة `أ`، والقارئُ مقيَّدٌ بشركة `ب`، والمنشورُ لا يظهر له في
`/feed` أصلاً — مُثبَتٌ في `ZZProbeB12Test`.)

**الإصلاح.** استبدالُ السطر بـ:

```php
if ($mk === 'feed') {
    $cids = hub_company_ids($u);
    if (! $c->company_id || $cids === null || in_array((string) $c->company_id, $cids, true)) return true;
    continue;
}
```

أي منطقُ `guardFeedComment` نفسُه — لا محرّكَ عزلٍ ثانٍ.

**اختبارُ الانحدار.** `test_feed_attachment_is_company_scoped`: منشورُ شركةٍ بمرفقٍ ⇒
`file.show` لقارئٍ من شركةٍ أخرى ⇒ ٤٠٣؛ ولقارئٍ من الشركة نفسِها ⇒ يمرّ.

**الثقة: عالية** — مُثبَتٌ على البوّابة نفسِها.

---

### A12-05 · **P2** · مرفقاتُ القنوات والمجموعات ميتةٌ ٤٠٣ حتى لأعضائها

**الدليل.** في الحلقة نفسِها (`FileController.php:289-291`): بعد فرعِ `feed` يأتي
`if (! hub_mod($mk) || ! hub_can($u, $mk, 'v')) continue;`. ورسالةُ القناةِ تُخزَّن
دائماً بـ`module = 'channel'` (`CommentController::store:104`, `CommentService::create`,
`ChatCommands::postMessage`)، و`'channel'` **ليست في `config('hub.modules')`** ⇒
`hub_mod('channel') === null` ⇒ `continue` ⇒ لا فرعَ يعرف القنوات ⇒ `mayRead` تعود
`false` ⇒ **٤٠٣ لكلِّ من ليس رافعَ الملف**، عضواً كان أو مالكَ القناة.

والواجهةُ ترسم الروابطَ في موضعين: `views/partials/_comment.blade.php:30-31`
و`views/collaboration/_ctx_conversation.blade.php:96` (لوحُ «الملفّات» في مركز
التواصل). فلوحٌ كاملٌ من الروابط الميّتة.

**الإعادة** (مسباران متّفقان):

```
[B] member (not uploader) file.show status: 403
[B] non-member file.show status: 403
[G2] channel att · uploader mayRead:      true
[G2] channel att · fellow member mayRead: FALSE *** dead link ***
[G2] channel att · non-member mayRead:    false
```

**الاتّجاه: فشلٌ مغلق** — لا تسريبَ هنا، بل ميزةٌ ميتة. لكنّه يستحقّ P2 لأنّه (أ)
يُفسد ميزةً مُعلَنةً في الواجهة، و(ب) يدفع المستخدمين إلى قناةٍ بديلةٍ (بريد/واتساب)
هي أخطرُ من القناةِ المحروسة.

**الإصلاح.** فرعٌ صريحٌ قبل `hub_mod` في الحلقة:

```php
if ($mk === 'channel' && $c->record_id
    && \App\Models\Conversation::roleOf((string) $c->record_id, (string) $u->id) !== null
    && /* نطاقُ الشركة/العميل كما في guardConversation */ …) return true;
```

والأنقى: استخراجُ قرارِ `guardConversation` إلى `Conversation::readableBy(User,$id): bool`
(بلا `abort`) واستدعاؤها هنا وفي الحارس معاً — مصدرُ قرارٍ واحد.

**اختبارُ الانحدار.** `test_channel_attachment_is_served_to_members_only`: ثلاثُ هويّات
(الرافع · عضوٌ آخر · غيرُ عضو) ⇒ (٢٠٠ · ٢٠٠ · ٤٠٣)، وقناةٌ خارجَ نطاقِ الشركة ⇒ ٤٠٣.

**الثقة: عالية جداً** — مُثبَتٌ بالانعكاس على `mayRead` وبالمسار الحيّ معاً.

---

### A12-06 · **P2** · تحريرُ رسالةٍ مباشرة لا يُنظّف النصَّ القديم من جرسِ المستلم

**الدليل.** `DmService::send` يكتب نصَّ الرسالة **كاملاً** في إشعار المستلم
(`DmService.php:63-64`، حتى ٥٩٠ حرفاً بعد `Str::limit` في `hub_notify`).
و`DmController::destroy` يعرف الخطرَ ويعالجه صراحةً:

```php
// DmController.php:386-388 — «الإشعار يُسحب مع رسالته»
HubNotification::where('kind','dm')->where('record_id',$m->id)->delete();
```

لكنّ `DmService::edit` (`:74-81`) لا يفعل شيئاً من ذلك — يُحدّث `body` و`edited_at`
فقط. والتحريرُ والسحبُ **نيّةٌ واحدة**: تصحيحُ ما أُرسل خطأً.

**الإعادة** (`ZZProbeA12Test::test_probe_edit_leaves_old_text_in_notification`):

```
[D] notification before edit: 💬 رسالة من المالك: رقمُ الحساب السري 4111111111111111
[D] notification after  edit: 💬 رسالة من المالك: رقمُ الحساب السري 4111111111111111
[D] old secret still in bell: YES *** LEAK ***
```

**السطحُ نفسُه في التعليقات**: `CommentService::edit` (`:155-176`) يُشعر المُضافين
حديثاً فقط — وهذا صحيح — لكنّ إشعاراتِ الإشارةِ القديمةَ تحمل `excerpt` من النصّ
**قبل** التحرير ولا تُحدَّث ولا تُحذَف. العيبُ نفسُه بدرجةٍ أخفّ.

**الإصلاح.** في `DmService::edit`: تحديثُ صفِّ الإشعار المعلّق بدل تركِه —

```php
HubNotification::where('kind','dm')->where('record_id',$m->id)->where('read', false)
    ->update(['text' => '💬 رسالة من ' . $actor->name . ': ' . trim($body)]);
```

(المقروءُ لا يُمسّ: المستلمُ رآه فعلاً، وتزويرُ ما رآه كذبٌ من نوعٍ آخر.) ونظيرُها في
`CommentService::edit` لإشعارات `mention` المعلّقة.

**اختبارُ الانحدار.** `test_editing_a_dm_rewrites_the_pending_bell_text`: إرسالٌ ⇒
تحريرٌ ⇒ `assertDatabaseMissing` للنصّ الأوّل في `notifications_hub.text`.

**الثقة: عالية جداً** — مُثبَتٌ، والنظيرُ (`destroy`) في الملفّ نفسِه يُثبت أنّ
القاعدةَ مقصودةٌ ومطبَّقةٌ في نصفِ المسار.

---

### A12-07 · **P2** · المحفوظاتُ بلا `guardFeedComment` — منشورُ شركةٍ أخرى يُحفَظ ويُعرَض نصُّه

**الدليل.** `SavedController::guardTargetVisible:84` و`resolveRow:105` يستدعيان
`CommentService::guardTarget` وحدَها. و`guardTarget` تُعيد `['feed', null]` **بلا أيِّ
تنطيق** (`CommentService.php:46`) — ولهذا بالضبط أُضيفت `guardFeedComment` وتُستدعى
في مسارَي التفاعل (`CommentController::react:280`, `MobileCollabController::commentReact:243`)
مع تعليقٍ صريح: «يُستدعى **بعد** `guardTarget` في مسارَي التفاعل». المحفوظاتُ مسارٌ
ثالثٌ بالخاصيّة نفسِها **ولا تستدعيها** — لا في الويب ولا في `MobileCollabController::savedShape`.

**الإعادة** (`ZZProbeB12Test::test_probe_saved_feed_cross_company`) — والتباينُ مع
المسار المحروس في التشغيل نفسِه:

```
[I] post company_id: 0e38f4e9-…
[I] saved.toggle status: 302
[I] saved row: YES
[I] saved list leaks body: YES *** LEAK ***
[I] comments.react status (guarded path): 404
```

**التحفّظ الصادق.** الاستغلالُ يحتاج **معرّفَ التعليق** (UUID)، وهو لا يُعرَض لقارئٍ
خارج الشركة في أيِّ سطحٍ فحصتُه. فهذا `IDOR` بمعرّفٍ غيرِ قابلٍ للتخمين — خطورتُه
الحقيقيّةُ في التسريبِ الثانويّ (معرّفٌ في سجلّ، في نسخةٍ احتياطيّة، في أثرِ تدقيق،
في رابطٍ مُشارَك). لذلك P2 لا P1.

**الإصلاح.** سطرٌ واحدٌ في ثلاثة مواضع (`SavedController:86`, `SavedController:106`,
`MobileCollabController::savedShape`): `CommentService::guardFeedComment($me, $c);`
بعد `guardTarget` مباشرة. والأنقى: **نقلُه داخلَ `guardTarget`** نفسِها في فرع `feed`
— فينتهي صنفُ العيبِ كلُّه ولا يتكرّر في القارئ الرابع.

**اختبارُ الانحدار.** `test_saved_message_respects_feed_company_scope`: حفظُ منشورِ
شركةٍ أخرى ⇒ ٤٠٤؛ ولو أُدخل الصفُّ يدويّاً ⇒ `saved.index` يعرضه «غيرَ متاح» بلا نصّ.

**الثقة: عالية** — مُثبَتٌ بالتشغيل مع نظيرٍ محروسٍ في المقارنة نفسِها.

---

### A12-08 · **P2** · `mobile.comments.react` في القائمة البيضاء لحساب العميل بلا تشديدٍ عميليّ

**الدليل.** `MobilePortalGuard::NAME_ALLOW` يحوي النمطَ `'mobile.comments.*'`
(`:68`)، وتوثيقُه يشرح لماذا: «التعليقاتُ: حارسُ الهدف **يُشدَّد عميليّاً في
المتحكّم**». وهذا صحيحٌ في `MobileCommController::comments:180-199` و`postComment:262-282`
(فحصُ `clientModuleAllowed` + اشتراطُ `CLIENT_CONV_KINDS`/`CLIENT_AUDIENCES` + حجبُ
`internal`). لكنّ المسارَ الثالثَ بالاسمِ نفسِه — `mobile.comments.react`
(`routes/api.php:287`) — يقع في **متحكّمٍ آخر**:

```php
// Api/MobileCollabController.php:238-244
public function commentReact(Request $r, string $id): Response {
    $this->tagMobile($r);
    $c = Comment::find($id);                       // ← لا denyClient()
    CommentService::guardTarget(auth()->user(), (string) $c->module, $c->record_id);
    CommentService::guardFeedComment(auth()->user(), $c);
```

فلا `denyClient()` (وهي موجودةٌ في الملفّ نفسِه وتُستدعى في ست دوالٍّ أخرى)، ولا
`clientModuleAllowed`، ولا اشتراطُ جمهورٍ عميليّ للقناة. والقرارُ يعود **للمصفوفة
وحدَها** — وهو بالضبط ما بُني الحارسُ ليعلوَ عليه (درس C1 الموثَّق في رأس الملفّ).

**الإعادة** (`ZZProbeB12Test::test_probe_client_reacts_to_internal_feed_via_mobile`):

```
[L] route name mobile.comments.react allowed by guard pattern 'mobile.comments.*': YES
[L] commentReact status for client: 200
[L] reaction row by client on internal feed post: YES *** external write ***
```

حسابُ عميلٍ خارجيّ يكتب صفَّ تفاعلٍ على منشورٍ داخليٍّ للفريق، فيظهر اسمُه داخلَ
الخلاصة الداخليّة. (الردُّ لا يحمل نصَّ المنشور، فليس تسريبَ محتوى — بل **كتابةٌ
خارجيّة** + **مِرقابُ وجود**: ٢٠٠ مقابل ٤٠٤ يميّز معرّفاً موجوداً من غيره.)

**الإصلاح.** في `commentReact`: `if ($deny = $this->denyClient()) return $deny;`
كأوّل سطرٍ بعد `tagMobile` — فالتفاعلُ ليس من سطح العميل أصلاً. وإن أُريد إبقاؤه له
في غرفته: نسخُ شرطَي `MobileCommController::comments:180-199` حرفاً. **والأمتنُ
بنيويّاً**: تضييقُ نمطِ القائمة البيضاء من `mobile.comments.*` إلى الاسمين المُشدَّدَين
صراحةً (`mobile.comments.index`, `mobile.comments.store`) — فالنمطُ المفتوح يبتلع كلَّ
مسارٍ يُضاف لاحقاً بالبادئة نفسِها، وهذا ما وقع.

**اختبارُ الانحدار.** `test_client_account_cannot_react_to_internal_feed_on_mobile`:
`POST /api/mobile/v1/comments/{id}/react` بهويّةِ عميل ⇒ ٤٠٤ وصفرُ صفوفٍ في `reactions`.

**الثقة: عالية جداً** — مُثبَتٌ بالتشغيل، ونمطُ القائمة البيضاء مُتحقَّقٌ منه بـ`Str::is`.

---

### A12-09 · **P2** · «رسالةُ ظلٍّ» في حاويةِ DM: يراها البحثُ ولا تراها الرقابةُ ولا شاشةُ المحادثة

**الدليل.** كلُّ خيطِ DM يُطوى في `Conversation(kind='dm')` بعضويّتَي طرفَيه
(`DmController::ensureDmConversation:104-140`). و`CommentController::store:100-105`
يقبل **أيَّ** `conversation_id` ويمرّره إلى `guardConversation(…, 'post')` — الذي
لا يُميّز `kind`. فطرفُ المحادثةِ عضوٌ بدور `member` ⇒ يمرّ ⇒ يُكتب صفُّ `Comment`
بـ`module='channel'` داخلَ حاويةِ DM.

وهذا الصفُّ يقع في نقطةٍ عمياء:
- **شاشةُ المحادثة** تقرأ `dm_messages` (`DmService::thread`) ⇒ لا يظهر؛
- **الرقابةُ (§6)** لحاويةِ `kind='dm'` تستدعي `readDm` (`OversightController:273-302`)
  الذي يقرأ `dm_messages` حصراً ⇒ **لا يظهر**؛
- **بحثُ الرسائل** يقرأ `comments` بالعضويّة ⇒ **يظهر للطرفين**.

فرسالةٌ يقرأها الطرفان ولا يراها القارئُ الرقابيُّ الذي بُني ليرى «ما حاول أحدٌ
إخفاءَه» (تعليق `readChannel:265-267`). هذه ثغرةُ امتثالٍ لا ثغرةُ خصوصيّة.

**الإعادة** (`ZZProbeE12Test::test_probe_shadow_message_oversight_blindspot` — مع
تصعيدِ مصادقةٍ حقيقيّ وسببٍ مُسجَّل):

```
[O2] oversight status: 200
[O2] sees the real dm:      YES
[O2] sees the shadow msg:   NO *** oversight blind spot ***
[O2] shadow msg count in comments: 1
```

**الإصلاح** (أحدهما يكفي، والثاني أعمق):
1. **منعُ الكتابة**: في `CommentController::store` و`MobileCommController::postComment`،
   بعد `guardConversation`: `abort_if($conv->kind === 'dm', 422, 'خيطُ المحادثةِ
   المباشرةِ له مسارُه')` — القناةُ الواحدةُ للـDM هي `dm.send`.
2. **سدُّ العمى**: في `OversightController::readDm`، ضمُّ `Comment::withTrashed()
   ->where('conversation_id', $conv->id)` إلى الناتج (كما يُضمّ المسحُ الخامُّ
   بـ`thread_key` لسببٍ مماثل: «فلا يغيب خيطٌ قديمٌ لم تُعبَّأ حاويتُه»).

**اختبارُ الانحدار.** `test_no_comment_can_be_posted_into_a_dm_container` (٤٢٢) **و**
`test_oversight_of_a_dm_shows_every_row_in_its_container` (لو أُدخل صفٌّ يدويّاً، يظهر).

**الثقة: عالية** — مُثبَتٌ بالتشغيل عبر ثلاثة سطوحٍ في مسبارٍ واحد.

---

### A12-10 · **P2** · القناةُ المؤرشفةُ لا تُقرأ لأحد — والوعدُ المكتوب «يبقى تاريخُها»

**الدليل.** `guardConversation` يُقصي المؤرشفةَ افتراضاً (`:54`) ولا يُمرّرها إلّا
`toggleArchive` (`:536`). فلا مسارَ قراءةٍ يبلغ قناةً مؤرشفة — لا للعضو ولا للمالك.
بينما الوعدُ مكتوبٌ في موضعين: توثيقُ `toggleArchive:530-531` («ويبقى تاريخُها»)،
ونصُّ التأكيد في `views/conversations/show.blade.php:50` («تختفي من القوائم النشطة
**ويبقى تاريخُها**»).

وفي المقابل `MessageSearchController` **لا يُرشّح المؤرشفة**: فالباحثُ يقرأ مقتطفَ
الرسالة ثم يضغط الرابطَ (`MessageLink::comment` ⇒ `conversations.show`) فيصطدم بـ٤٠٤.

**الإعادة** (`ZZProbeA12Test::test_probe_archived_channel_unreadable`):

```
[E] archived_at: 2026-09-18 00:26:26
[E] owner show status: 404
[E] member show status: 404
[E] search still shows archived body: YES
```

**الإصلاح.** قراءةٌ بلا كتابةٍ للمؤرشفة: `guardConversation($id, 'v', includeArchived: true)`
في `show`/`center`/`since` مع `canPost = false` قسراً (فالوعدُ «تختفي من القوائم
النشطة ولا يُكتَب فيها» يبقى محفوظاً)، وشريطٌ في الشاشة يقول «قناةٌ مؤرشفة — قراءةٌ فقط».
وهذا يُصلح الرابطَ الميّتَ في البحث تلقائيّاً. (البديلُ الأضعف: ترشيحُ المؤرشفةِ من
البحث — يحفظ الاتّساقَ ويكسر الوعد.)

**اختبارُ الانحدار.** `test_archived_channel_is_readable_but_not_writable`:
عضوٌ يقرأ (٢٠٠) · لا حقلَ إرسال · `comments.store` ⇒ ٤٠٤/٤٠٣ · ورابطُ البحث يفتح.

**الثقة: عالية جداً** — مُثبَتٌ، والتناقضُ مع نصٍّ معروضٍ للمستخدم حرفيّ.

---

### A12-11 · **P2** · حذفُ رسالةِ قناةٍ: بلا أثرِ تدقيقٍ وبلا شاهدٍ للقارئ

**الدليل.**

```php
// app/Http/Controllers/Web/CommentController.php:214-220
public function destroy(string $id) {
    $c = Comment::findOrFail($id);
    abort_unless($c->user_id === auth()->id() || hub_is_owner(), 403);
    $c->delete();
    return back()->with('ok', 'حُذف التعليق');
}
```

ثلاثةُ نواقص:
1. **لا `hub_audit`** — بينما كلُّ فعلٍ إداريٍّ آخر على الحاوية يكتب أثراً
   (`channel.created`, `channel.joined`, `channel.member_added|removed|role`,
   `channel.archived`, `group.created`). فحذفُ **المحتوى** وحدَه بلا أثر.
2. **لا شاهدَ للقارئ**: `Conversation::rootMessages()` يمرّ بنطاق `SoftDeletes`
   الافتراضيّ ⇒ الرسالةُ تختفي تماماً. وهذا يناقض القاعدةَ المُعلَنةَ في المحرّك
   الشقيق: `DmMessage::scopeAlive` («حذفٌ من الشاشة لا من التاريخ… المحادثةُ
   المبتورةُ بلا تفسيرٍ أسوأُ من أثرٍ يقول ماذا جرى»).
3. **لا `guardTarget`** — غيرُ مؤثّرٍ اليومَ (صاحبُ الرسالة أو المالكُ فقط) لكنّه
   التفاوتُ الوحيد بين `destroy` وأخواتها `edit`/`pin`/`resolve`/`react`.

**الإعادة** (`ZZProbeC12Test::test_probe_channel_delete_tombstone`):

```
[F2] body still present: no
[F2] tombstone 'حُذفت رسالة': NO — silent vanish
[F2] soft-deleted row kept: YES
[F2] audits for comment.deleted: 0
```

(الصفُّ محفوظٌ ناعماً — فالرقابةُ تراه عبر `withTrashed` في `readChannel`. النقصُ في
**شفافيّة القارئ** و**أثرِ التدقيق**، لا في حفظ البيانات.)

**الإصلاح.** (أ) `hub_audit('message.deleted', 'conversations', $c->conversation_id,
Str::limit($c->body, 60))` قبل `delete()` — مع مراعاةِ ألّا يُنسَخ نصٌّ حسّاسٌ في
الأثر إن كان ذلك مقصوداً، فيكفي المعرّفُ والكاتب. (ب) شاهدُ حذفٍ في القناة نظيرَ
الـDM: `rootMessages` تضمّ المحذوفَ وتعرضه «حُذفت رسالة» (السلوكُ القائم في
`views/dm/_messages.blade.php`).

**اختبارُ الانحدار.** `test_deleting_a_channel_message_leaves_an_audit_and_a_tombstone`.

**الثقة: عالية** — مُثبَتٌ، والتفاوتُ مع المحرّك الشقيق حرفيٌّ وموثَّق.

---

### A12-12 · **P3** · `/assign` يُسنِد لأيِّ مستخدمٍ في المنظّمة

**الدليل.** `ChatCommands::titleAndMentions:206-208` يطابق `@اسم` على
`CommentController::userNames()` — وهي **كلُّ** المستخدمين غيرِ المحذوفين، بلا نطاقٍ
ولا نوعِ حساب. ثم `runAssign:98-107` يُسنِد `$mentions[0]` مباشرة.

قارِن `CommentService::extractMentions:191-216` التي بُنيت لهذا بعينه: «الإشارةُ
تُحلّ **ضمن نطاق الهدف** … فلا يصل مقتطفُ الرسالةِ إلى من لا يقدر فتحَها». الأمرُ
`/assign` هو الوحيدُ الذي يتخطّاها — وهو الوحيدُ الذي **يفعل شيئاً** بالهدف
(إسنادٌ + إشعار) لا مجرّدِ إشعاره.

**الإعادة** (`ZZProbeB12Test::test_probe_assign_unscoped_target`):

```
[J] far is client: YES · dmReachable: no
[J] /assign status: 302
[J] task assignee is the client account: YES *** cross-boundary ***
[J] client got notification: YES
```

**التحفّظ الصادق.** نصُّ العنوان من الآمرِ نفسِه، فلا يتسرّب محتوى غيرِه. الضررُ في
**الإسناد** (حسابُ عميلٍ خارجيّ يصير «مسؤولاً» عن مهمّةٍ داخليّة يراها في وحداته إن
مُنحها) وفي **الإشعار** الذي يعبر حدَّ النطاق. لذلك P3.

**الإصلاح.** استبدالُ `CommentController::userNames()` في `titleAndMentions` بـ
`DmController::reachableColleagues($user)` (المصدرُ الواحدُ المُعلَنُ لمنتقي
المشاركين: داخليّون، ضمن النطاق، غيرُ عملاء)، مع ردٍّ صريحٍ ٤٢٢ إن لم يبقَ مسنَدٌ
إليه صالح («لا مسندَ إليه بهذا الاسم ضمن فريقك»). ويُلاحَظ كذلك أنّ السطر يستعمل
`auth()->id()` لا `$user` — فيسقط على أيِّ سطحٍ غيرِ ويبيٍّ يستدعي `dispatch`.

**اختبارُ الانحدار.** `test_assign_command_rejects_out_of_scope_and_client_assignees`.

**الثقة: عالية** — مُثبَتٌ بالتشغيل.

---

### A12-13 · **P3** · شارةُ «غير المقروء» في الرسائل المباشرة غيرُ منطَّقةٍ بالشركة

**الدليل.** `DmController::unreadCount():527-533` يعدّ `to_id = auth` بلا
`inCompanyScope()`، بينما `DmService::threadRows:111-112` يعدّ بنطاق الشركة. وتعليقُ
`inbox` (`:230-238`) يسمّي هذا الصنفَ بعينِه عيباً أُصلح سابقاً: «فيقول الشريطُ «غير
مقروءة ٣» وتُظهر القائمةُ واحدة، ولا سبيلَ إلى فتح الباقي إلا بالصدفة» — والعيبُ عاد
من بابٍ آخر (نطاقُ الشركة بدل نافذةِ الخمسمئة).

**الإعادة** (`ZZProbeA12Test::test_probe_unread_badge_vs_list`):

```
[C] threadRows count (scoped): 0
[C] unreadCount badge (unscoped): 1
```

**الإصلاح.** `$q = DmMessage::where('to_id', auth()->id())->whereNull('read_at')->inCompanyScope();`

**اختبارُ الانحدار.** `test_dm_unread_badge_matches_the_scoped_thread_list`: مقارنةُ
`unreadCount()` بمجموع `threadRows()['unread']` لمستخدمٍ مقيَّدٍ بشركةٍ ورسالةٍ موسومةٍ بأخرى.

**الثقة: عالية جداً** — مُثبَتٌ، ورقمان متعارضان في التشغيل نفسِه.

---

### A12-14 · **P3** · `DmService::thread` بلا `inCompanyScope`

**الدليل.** من قرّاء الـDM الأربعة: `threadRows` (`:111`) و`DmController::since`
(`:410`) و`MobileCollabController::dmSince` (`:155`) تُطبّق `inCompanyScope()`،
و`DmService::thread:171-178` **لا** تُطبّقها. فالخيطُ المفتوحُ يُظهر ما تُخفيه القائمةُ
ويُخفيه الاستطلاعُ التدريجيُّ بعد ثانية.

**الإعادة** (`ZZProbeC12Test::test_probe_dm_thread_scope_inconsistency`):

```
[N] thread view shows out-of-scope history: YES
[N] since() events count (scoped): 0
[N] thread listed in inbox rail: no
```

**القراءةُ الصادقة.** ليس تسريباً — القارئُ **طرفٌ** في الخيط. لكنّه سلوكٌ غيرُ
حتميّ: الرسالةُ تظهر عند فتح الصفحة وتختفي من القائمة ومن الاستطلاع. والأسوأُ أنّ
التفاوتَ يُخفي القرار: لا أحدَ يعلم أيَّ السلوكين هو المقصود.

**الإصلاح.** **حسمُ القرار أوّلاً** ثم توحيدُ الأربعة عليه. الأقربُ للصواب: طرفا
المحادثةِ يريان تاريخَهما كاملاً (فالعزلُ عن **الثالث** لا عن نفسِه) ⇒ تُرفَع
`inCompanyScope` من `since` والقائمةِ معاً ويبقى حارسُ `dmReachable` وحدَه. والبديلُ:
تُضاف إلى `thread`. أيّهما اختير يُوثَّق في `DmMessage::scopeInCompanyScope`.

**اختبارُ الانحدار.** `test_dm_readers_agree_on_company_scope`: أربعةُ قرّاءٍ ⇒ نتيجةٌ
واحدةٌ على المُدخل نفسِه.

**الثقة: عالية** — مُثبَتٌ بالتشغيل؛ والحكمُ على «أيُّهما صواب» **يحتاج قرارَ منتج**.

---

### A12-15 · **P3** · صندوقُ الرسائل يعرض حساباتِ العملاء وجهاتٍ — رسالةٌ إلى ثقبٍ أسود

**الدليل.** `DmController::startableUsers:146-154` يُرشّح بـ`dmReachable` وحدَها، ولا
يُسقط `hub_is_client`. والنظيرُ `reachableColleagues:158-168` **يُسقطها** صراحةً
(«داخليّون (لا عملاء)»). و`DmController::send:307-317` كذلك لا يفحص نوعَ الحساب.
والنتيجة: المرسِلُ يرى «✓» ويبني عليها، والمستلمُ لا يبلغ `dm.*` أبداً (PortalGuard)
ولا `mobile.dm.*` (خارجَ القائمة البيضاء).

**الإعادة** (`ZZProbeB12Test::test_probe_dm_picker_lists_clients`):

```
[K] dm inbox lists client account: YES
[K] dm.send to client status: 302
[K] message stored: YES
[K] client can open thread: 404
```

**ما لم يتسرّب.** الإشعارُ يُكتب في `notifications_hub` لحساب العميل، لكنّ `hub_notify`
كتابةُ صفٍّ فقط (لا بريدَ ولا تلغرام)، ومساراتُ الإشعارات خارجَ قائمتَي البوّابة
البيضاوَين ⇒ **لا يبلغه النصُّ بأيِّ سطحٍ فحصتُه**. فهذا عطبُ منتجٍ لا تسريب — لكنّه
يبني عادةَ «أرسلتُ ولم يردّ» على وهم.

**الإصلاح.** ترشيحُ `! hub_is_client($u)` في `startableUsers`، وحارسٌ في `send`:
`! $other || hub_is_client($other) || ! dmReachable(…) ⇒ 'لا حساب بهذا المعرّف'`
(يُضاف إلى `match` القائم في `:311-316` فيُطوى في الرسالة نفسِها بلا كشفِ وجود).
والأفضلُ بنيويّاً: نقلُ الشرط إلى `DmService::reachable` فيسري على الويب والجوال معاً.

**اختبارُ الانحدار.** `test_dm_cannot_target_a_client_account`.

**الثقة: عالية جداً** — مُثبَتٌ من طرفٍ إلى طرف.

---

### A12-16 · **P3** · التاريخُ عند الانضمام غيرُ مُعلَنٍ في أيِّ سطحٍ للمستخدم

**الدليل والإعادة** (`ZZProbeC12Test::test_probe_join_history_disclosure`):

```
[M] directory status: 200
[M] directory warns about history: NO
[M] joiner reads pre-join history: YES
[M] add-member form warns about history: NO
```

**القاعدةُ الفعليّة** (استخرجتُها من الكود، لا من وثيقة):

| السطح | القاعدة | مُعلَنة؟ |
|---|---|---|
| قناة · انضمامٌ ذاتيّ (`join`) | العضوُ الجديد يقرأ **كلَّ** التاريخ | **لا** |
| قناة · إضافةٌ من مشرف (`addMember`) | العضوُ الجديد يقرأ **كلَّ** التاريخ | **لا** |
| مجموعة (`kind=group`) | لا إضافةَ البتّة — تُنشأ مجموعةٌ جديدةٌ بتاريخٍ فارغ | **نعم** (`show.blade.php:119` + رسالةُ ٤٢٢ + رسالةُ `fork`) |
| رسائلُ مباشرة | الطرفان فقط، دائماً | لا يلزم |
| غرفةُ عميل | العميلُ يقرأ كلَّ ما في الحاوية منذ نشأتها | **لا** |

فالمنتجُ يعرف القاعدةَ ويطبّقها بدقّةٍ في المجموعات (وهذا تصميمٌ ممتاز) — والقناةُ
وغرفةُ العميلِ تركاها صامتة. والصمتُ هنا ليس محايداً: مشرفٌ يُضيف زميلاً إلى قناةٍ
عمرُها سنةٌ لا يخطر له أنّه سلّمه أرشيفَ سنة.

**الإصلاح.** (أ) سطرٌ في `views/conversations/directory.blade.php` وفي نموذج «إضافة
أشخاص» في `views/conversations/show.blade.php` و`views/collaboration/_ctx_conversation.blade.php`:
«العضوُ الجديد يقرأ كلَّ ما سبق في هذه القناة». (ب) وللخيارِ الأقوى مستقبلاً:
`conversation_members.history_from` (زمنُ الانضمام) يُحترمه `rootMessages`/`since`/البحث
— وهو التصميمُ الذي تحتاجه الغرفُ العميليّة أصلاً. (اقرأ `A12-17`.)

**الثقة: عالية** — مُثبَتٌ بالتشغيل (سلوكاً وغيابَ نصّ).

---

### A12-17 · **P3** · غرفُ المشاريع (§9) مبنيّةٌ ولا يستدعيها الإنتاج

**الدليل.** `ConversationController::ensureProjectRooms:108-155` — تصميمٌ متينٌ
(فصلٌ فيزيائيٌّ بين الغرفةِ الداخليّةِ وغرفةِ العميل، لا علَمٌ على الرسالة). لكنّ
البحثَ عن مُستدعِيها في كامل `app/` يعطي **صفراً**:

```
$ grep -rn "ensureProjectRooms" app/ database/ tests/
tests/Feature/WorkOsAcceptanceClientTest.php:200
tests/Feature/WorkOsProjectRoomsTest.php:73,118,199,200,232
```

— الاختباراتُ وحدَها. فلا مسارَ ولا حدثَ ولا خدمةَ تُنشئ غرفةَ مشروعٍ في التشغيل
الحقيقيّ. و`CollaborationRail::forUser:71-77` يرسم قسمَ «الغرف» بناءً على
`project_id !== null` — قسمٌ لا يمتلئ أبداً.

وأعمقُ من ذلك: حتى لو استُدعيت، **لا مسارَ يُدخِل العميلَ غرفتَه**. الدالّةُ تبذر
مالكاً داخليّاً فقط (`:141-147`)، و`addMember` هو السبيلُ الوحيد — وهو بلا فحصٍ
عميليّ (`A12-02`). فالسؤالُ «من يدخل غرفةَ العميل؟» جوابُه اليومَ: **لا أحد، أو من
يُضيفه مشرفٌ بلا أيّ حاجز**.

**الإصلاح.** قرارُ منتجٍ أوّلاً (هل الغرفتان ميزةٌ مقصودةٌ للإصدار؟). إن نعم:
استدعاءُ `ensureProjectRooms` من حدثِ إنشاء المشروع (`FlowRunner::fire('created','projects')`)
أو كسلاً عند أوّل فتحٍ لتبويب «الغرفة» في المشروع؛ + مسارُ عضويّةٍ عميليٍّ مُنطَّقٍ
يشتقّ الأعضاءَ من `ClientMembers`/`client_id` (لا `addMember` الحرّ). إن لا:
إزالةُ قسمِ «الغرف» من السكّة كي لا يَعِد بما لا يأتي.

**الثقة: عالية** على الحقيقة (الاستدعاءُ غائبٌ قطعاً) · **متوسّطة** على التصنيف
(قد تكون ميزةً مؤجَّلةً عمداً — تحتاج تأكيدَ المنتج).

---

## ٢. أجوبةُ المهمّة العشرة (خلاصةٌ مباشرة)

1. **القنوات.** `kind=channel` بأربعِ درجاتِ ظهور (`private/members/company/public`)
   وثلاثةِ جماهير (`internal/client/both`). **يُنشئ**: أيُّ مستخدمٍ مصادَق
   (`store` بلا `hub_can`) ويصير `owner`. **ينضمّ**: ذاتيّاً للقنوات
   `internal + visibility∈{company,public}` فقط (`join`، فحصٌ خادميٌّ كاملٌ يُعاد لا
   يثق بالدليل). **يُضيف**: `moderator` فأعلى؛ وتنصيبُ مشرفٍ/مالكٍ للمالك وحده؛
   ولا تُخلى القناةُ من مالكها الأخير — **لكن بلا فحصِ نطاقٍ ولا نوعِ حسابٍ على
   الهدف** (`A12-02`). **الأرشفة**: للمالك وحده، وتُحوّل القناةَ إلى صندوقٍ لا
   يُقرأ (`A12-10`).
2. **`threadKey`.** **يُشتقّ خادميّاً دائماً ولا يُقبل من العميل قطّ.** فحصتُ
   ثمانيةَ مواضع (`DmService::send/thread/markThreadRead/threadKey`،
   `DmController::thread/since/typing`, `MobileCollabController::dmSince/dmTyping`)
   — كلُّها `DmMessage::threadKey(auth()->id(), $other)`، والمعاملُ في المسار هو
   **معرّفُ الطرف** لا الخيط. والحاويةُ تُشتقّ حتميّاً (`uuid5` من المفتاح) فلا
   هويّتان لخيط. **هذا أمتنُ ما في السطح.**
3. **غرفُ المشاريع والعملاء.** التصميمُ صحيحٌ (فصلٌ فيزيائيٌّ لا علَمٌ على الرسالة)
   لكنّه **غيرُ مُستدعىً في الإنتاج** (`A12-17`). وقراءةُ العميل: `conversationDetail`
   تفرض العضويّة **و**الجمهورَ **و**عملاءَ القارئ الأحياء معاً ⇒ قناةٌ داخليّةٌ هو
   عضوٌ فيها خطأً تبقى ٤٠٤ (**مُثبَت**). و`internal` على الرسالة لا معنى له في
   القنوات (`CommentService::create:119` يفرضه `false` إلا في `tickets`) — وهذا
   متّسقٌ مع قرار «الفصلُ فيزيائيّ»، لكنّه يعني أنّ أيَّ رسالةٍ في غرفةٍ
   `audience∈{client,both}` يقرؤها العميلُ بلا استثناء.
4. **التاريخُ عند الانضمام.** يقرأ **كلَّ** ما مضى في القنوات والغرف؛ ولا يقرأ شيئاً
   في المجموعات (تُنشأ مجموعةٌ جديدة). **غيرُ مُعلَنٍ** في أيِّ سطحٍ إلا المجموعات
   (`A12-16`).
5. **التعديلُ والحذف.** التحريرُ لصاحب الرسالة وحده في المحرّكين (٤٠٣ لغيره،
   **مُثبَت**)؛ الحذفُ لصاحبها أو للمالك. الأثر: **DM يُبقي شاهداً** («حُذفت رسالة»)
   ويسحب الإشعار؛ **القناةُ تُخفي بلا شاهدٍ وبلا أثرِ تدقيق** (`A12-11`).
   والتحريرُ يترك النصَّ القديمَ في الجرس (`A12-06`).
6. **المرفقات.** **لا**، لا تُحرَس كما تُحرَس الوثائق — بل بعكسِها في اتّجاهين:
   مرفقُ قناةٍ خاصّةٍ **لا يعمل لأحدٍ** إلّا رافعِه (`A12-05`)، ومرفقُ منشورِ خلاصةٍ
   **يعمل للجميع** بلا عزلِ شركة (`A12-04`). ومرفقُ الـDM محروسٌ بطرفَيه حرساً
   صحيحاً (`FileController:268-275`). وحاجزُ الإصابة (`av_status`) وسياسةُ الوثيقة
   يسريان على المسار الخام — نقطةٌ محسوبةٌ للتصميم.
7. **الإشارات.** **مُنطَّقةٌ جيّداً**: `extractMentions` تبني مجموعَ المرشَّحين من
   **أعضاء الحاوية** للقنوات، ومن مشاركي الشركة للخلاصة، وتُسقِط من لا يرى السجلَّ
   في الوحدات (`filterRecordVisible`). ونصُّ الإشعار يحمل `Str::limit($body, 60)` —
   ولا يبلغ من لا يرى السياق. **الاستثناءُ الوحيد**: أمرُ `/assign` يتخطّاها
   (`A12-12`). وعنوانُ السجلّ في الإشعار مقنّعٌ بـ`hub_notification_text` إن زالت
   الصلاحيةُ لاحقاً — تصميمٌ جيّد.
8. **البحثُ والروابطُ الدائمة.** الروابطُ نفسُها بلا تخويل (وهذا صحيح — الحرسُ عند
   الفتح، **ومُثبَتٌ** أنّه يعمل: ٤٠٤ لغير العضو). **والبحثُ هو الثقب** (`A12-01`).
9. **المحفوظاتُ والمثبَّتات.** المحفوظاتُ تصميمٌ ممتاز: **مرجعٌ لا نسخُ محتوى**،
   ويُعاد التخويلُ عند كلِّ عرض، وما لم يعد يُرى يُعرَض «غيرَ متاح» بلا نصّ — في
   الويب والجوال معاً. ثقبُها الوحيد `guardFeedComment` الغائبة (`A12-07`).
   والتثبيتُ محروسٌ بدورِ الحاوية (`moderator` فأعلى) وبتنطيقِ السجلِّ الأمّ.
10. **أفعالُ العمل من الرسالة.** `hub_can` على الوحدةِ الهدف مفروضٌ في
    `toTask` و`ChatCommands` معاً (لا نجاحٌ زائف، ورفضٌ صريحٌ ٤٠٣/٤٢٢، وأثرُ تدقيقٍ
    بسياقِ الأمر). **لكن** صلاحيةَ **القارئِ الناتج** غيرُ محسوبة: نصُّ رسالةٍ خاصّةٍ
    ينتقل إلى جمهورِ `tasks` (`A12-03`)، والمسنَدُ إليه غيرُ منطَّق (`A12-12`).

---

## ٣. ما هاجمتُه وصمد

كلُّ سطرٍ هنا **مُثبَتٌ بتشغيلٍ** في `ZZProbeD12Test::test_probe_guards_that_held`
و`ZZProbeE12Test` (لا استنتاجاً من قراءة الكود).

| الهجمة | الرد | التعليق |
|---|---|---|
| غيرُ عضوٍ يفتح قناةً خاصّةً بمعرّفها | **404** | لا كشفَ وجود (٤٠٤ لا ٤٠٣) |
| غيرُ عضوٍ يستطلع `conversations/{id}/since` | **404** | الحارسُ نفسُه على المسار الحيّ |
| غيرُ عضوٍ يدسّ رسالةً بـ`conversation_id` مزوَّر | **404** | `guardConversation('post')` |
| **ضيفٌ** (role=guest) يكتب في قناةٍ هو عضوٌ فيها | **403** | «الضيفُ يقرأ ولا يكتب» — مفروضٌ لا موثَّقٌ فقط |
| غيرُ عضوٍ يتفاعل على رسالةِ قناةٍ بمعرّفها | **404** | `guardTarget ⇒ guardConversation` |
| غيرُ عضوٍ **يحفظ** رسالةَ قناةٍ بمعرّفها | **404** | المحفوظاتُ تُخوِّل عند الحفظ وعند العرض |
| غيرُ عضوٍ **يثبّت** رسالةَ قناة | **404** | تنطيقُ السجلِّ الأمّ قبل فحص الدور |
| عضوٌ يُحرّر رسالةَ غيره (قناة) | **403** | «لا يُعدّل أحدٌ كلام غيره» |
| ثالثٌ يُحرّر/يحذف/يتفاعل/يحفظ رسالةً مباشرة | **403 ×٤** | حارسُ الطرفين في أربعة مسارات |
| **مستلمُ** رسالةٍ مباشرةٍ يحذفها | **403** | «من تلقّى رسالةً لا يُخفيها عن مُرسِلها» |
| إضافةُ عضوٍ إلى **مجموعة** (`kind=group`) | **422** | أمنُ الجمهورِ التاريخيّ — الإضافةُ `fork` بتاريخٍ فارغ |
| `threadKey('b','a') == threadKey('a','b')` | **نعم** | الثنائيُّ خيطٌ واحدٌ دائماً، ولا مفتاحَ من العميل |
| **حسابُ عميلٍ** عضوٌ في قناةٍ داخليّة ⇒ قائمةُ البوّابة | **لا يظهر** | `audience` فوق العضويّة |
| **حسابُ عميلٍ** عضوٌ في قناةٍ داخليّة ⇒ تفصيلُ البوّابة | **404** | `conversationDetail` يفرض الثلاثةَ معاً |
| **حسابُ عميلٍ** على `/conversations/{id}` | **404** | `PortalGuard` قبل المتحكّم |
| قارئٌ رقابيٌّ بلا تصعيدٍ أو بلا سبب | **302 إلى التصعيد / نافذةُ السبب** | ثلاثةُ حواجزَ + `hub_audit` لكلِّ قراءة |

**وما صمد بالقراءة لا بالتشغيل** (أسجّله بوسمه الصحيح):

- **لا `thread_key` من العميل في أيِّ مسار** — مسحٌ كاملٌ لثمانيةِ مواضع.
- **`assertReplyIntegrity`** يمنع دسَّ ردٍّ في خيطِ سجلٍّ آخر (`module`+`record_id` معاً).
- **الرقابةُ لا تمسّ `read_at`/`read_by`** — لا `update` في `readDm`/`readChannel`،
  والرقيبُ لا يصير «قارئاً» في عينِ الطرف.
- **الرقابةُ ليست امتيازاً موروثاً للمالك**: تُقرأ من `role->flags` الخام لا بـ`hub_flag`.
- **بوّابتا القدرات** (`collab.presence`/`collab.typing`) فشلُهما آمنٌ (٤٠٤/مصفوفةٌ فارغة).
- **`Collaboration::decodeCursor`** يفشل بأمان (فاسدٌ ⇒ `null` ⇒ من البداية) ولا
  يُستعمل في أيِّ قرارِ تخويل — المؤشّرُ ترتيبٌ لا هويّة.
- **الخنقُ** مضبوطٌ على المسارات الساخنة (`since` ١٢٠/د، `typing` ٦٠/د، الإنشاءُ
  والعضويّة ٣٠–٦٠/د).

---

## ٤. فجواتُ منتجٍ مرشَّحة (بصنفِ الدليل)

| # | الفجوة | صنفُ الدليل |
|---|---|---|
| PG-1 | **لا `history_from` على العضويّة**: لا سبيلَ لإضافةِ عضوٍ «من اليوم». المجموعاتُ تعالجها بـ`fork` (نسخةٌ فارغة)، والقنواتُ والغرفُ بلا علاج | **كود** — لا عمودَ ولا فرعَ في `rootMessages`/`since`/البحث |
| PG-2 | **غرفُ المشاريع غيرُ مفعَّلة** ولا مسارَ عضويّةٍ عميليٍّ لها | **كود** — `ensureProjectRooms` صفرُ مُستدعٍ خارج الاختبارات |
| PG-3 | **لا «ملاحظةٌ داخليّة» في القنوات**: `internal` مقصورٌ على `tickets`؛ فأيُّ كلمةٍ في غرفةٍ `audience∈{client,both}` يقرؤها العميل. متّسقٌ مع قرار «الفصلُ فيزيائيّ» لكنّه غيرُ مُعلَنٍ في الواجهة | **كود** (`CommentService:119`) + **غيابُ نصٍّ** في القوالب |
| PG-4 | **القناةُ المؤرشفةُ أرشيفٌ لا يُقرأ** — الوعدُ المعروضُ يقول عكسَ السلوك | **مُثبَتٌ بالتشغيل** + نصُّ واجهةٍ متعارض |
| PG-5 | **لا شاهدَ حذفٍ في القنوات** بينما الـDM يضعه — المحادثةُ تُبتَر بلا تفسير | **مُثبَتٌ بالتشغيل** |
| PG-6 | **لا مغادرةَ لقناة**: `groups.leave` موجود، ولا نظيرَ له للقنوات؛ الخروجُ يحتاج مشرفاً | **كود** — لا مسارَ `conversations/{id}/leave` في `routes/web.php` |
| PG-7 | **لا حدَّ لحجم القناة ولا لعددِ أعضائها** بينما المجموعةُ محدودةٌ بعشرين — وتضخّمُ العضويّة هو محرّكُ التسريبِ التاريخيّ | **كود** — `GroupController::MAX_MEMBERS` بلا نظير |
| PG-8 | **حسابُ العميلِ وجهةٌ ممكنةٌ في منتقي الرسائل المباشرة** ولا يستقبل شيئاً | **مُثبَتٌ بالتشغيل** |
| PG-9 | **لا إعادةَ توجيهٍ (forward) ولا اقتباس** — والمستخدمون يعوّضونها بالنسخ واللصق خارج المنصّة، وهو أسوأُ سطحِ تسريبٍ ممكن | **غياب** (لا مسارَ ولا واجهة) |
| PG-10 | **الزمنُ الحقيقيُّ استطلاعٌ** (`since` كلَّ نبضة، ١٢٠/د) — عقدُ الأحداثِ جاهزٌ للبثّ لكنّ السائقَ غائب. قرارٌ موثَّقٌ ومُبرَّرٌ بالاستضافة، يُعاد النظرُ فيه عند النموّ | **كود + توثيقٌ صريح** في `Collaboration.php` |

---

## ٥. منهجُ الفحص وحدودُه

**ما فُحص**: ١٨ ملفّاً في النطاق + ٦ ملفّاتٍ مجاورةٍ لزمت (`FileController`,
`ClientPortalData`, `PortalGuard`, `MobilePortalGuard`, `MobileCommController`,
`CommentService`) + ٣٩ مساراً في `routes/web.php` و١٠ في `routes/api.php`.
**٢١ مسباراً** نُفِّذت على sqlite عبر `phpunit.xml`.

**حدودٌ أُقرّ بها**:
- لم أشغّل الحزمةَ الكاملة ولا محرّكَ MySQL — فما يخصّ لهجةَ الاستعلامِ أو ترتيبَ
  مفاتيحِ JSON لم يُفحَص في هذا التقرير.
- الفحصُ على `RefreshDatabase` بمخطّطٍ كاملٍ مُرحَّل ⇒ لم أختبر مساراتِ
  «قبل الهجرة» (`hub_has_col` false) التي يكثر تفرّعُها في هذا السطح.
- لم أفحص `public/js/collab-poll.js` (سلوكُ العميل) — العزلُ خادميٌّ في كلِّ ما
  فحصتُه، لكنّ تجربةَ المستخدمِ عند تعارضِ الاستطلاعِ والحارسِ لم تُقاس.
- `A12-14` و`A12-17` يحتاجان **قرارَ منتجٍ** لا إصلاحَ كود: سجّلتُ التفاوتَ ولم أحكم
  أيَّ الطرفين صواب.
