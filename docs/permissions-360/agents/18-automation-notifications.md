# 18 — التنبيهات والمهام والأتمتة والتكاملات

> وكيلُ تدقيقٍ للقراءة فقط (Permissions 360 · المرحلة 0). مصدرُه سيرُ التدقيق `wf_8fdd85b1-7b4`.

## الخلاصة

Audited HubNotification model + booted() hooks, NotificationController (web bell), NotificationLink resolver, MobileCommController notification endpoints, PushService fan-out, all 12 notification-creation sites (hub_notify + HubNotification::create in AlertEngine/ApprovalService/CommentService/FlowRunner/ReportReview/DmService/ChatCommands/LoginSentry/Staff/ErrorLog + HubDigest/HubAutomation commands), scheduler (routes/console.php), HubOutbox/HubAutomation jobs, and all integration controllers (Webhook/InboundHook/N8n/Odoo/Messaging/Integration). Headline: the notification click path IS correctly re-checked (web go→m.show enforces hub_can+hub_scope; mobile target/markRead only echo IDs), PushService is safe-by-construction (generic title by kind, no raw text), and mention/approval/AlertEngine-daily paths correctly scope recipients. BUT HubAutomation's notifyMonitors + budgetsAuto fan out contract titles and financial/budget amounts to monitor-flag holders WITHOUT the module view permission (only hub_scope checked, hub_can omitted) — the exact leak AlertEngine.php:156 guards against. Integration center is uniformly OWNER_ONLY; inbound hook receive is SYSTEM_INTERNAL (token+HMAC+replay-guard+redaction).

## السطوح المفحوصة (23)

| السطح | النوع | المرجع | التوثيق الحالي | التصنيف | المفتاح | الحساسية | ملاحظات |
|---|---|---|---|---|---|---|---|
| مركز الإشعارات (الصفحة) | route | notifications.index | where user_id=auth()->id() (own only) | SELF_SERVICE | — | normal | يسلسل text المخزون كما هو — لا يُعاد ترشيحه عند سحب صلاحية لاحق (انظر finding residual) |
| عدّ غير المقروء | route | notifications.count | user_id=auth()->id() | SELF_SERVICE | — | normal | يعدّ إشعارات صاحبها فقط — لا count_leak |
| الجرس المصغّر | route | notifications.mini | user_id=auth()->id() | SELF_SERVICE | — | normal |  |
| فتح إشعار (go) | route | notifications.go | findOrFail(user_id=auth) ثم redirect m.show | SELF_SERVICE | — | normal | إعادة التوجيه تمر بـ resolve()=hub_can(v)+findScoped=hub_scope → 403/404 عند سحب الوصول. لا IDOR على النقر |
| تحديد الكل مقروء | route | notifications.readall | user_id=auth()->id() | SELF_SERVICE | — | normal |  |
| إشعارات الجوال (قائمة) | api | mobile.notifications.index | HubNotification where user_id=auth()->id() | SELF_SERVICE | — | normal | notificationShape يسلسل text+module+record_id+target |
| وجهة إشعار الجوال | api | mobile.notifications.target | find(user_id=auth) | SELF_SERVICE | — | normal | يعيد {module,id,action} — معرّفات فقط؛ الجلب الفعلي يُعاد فحصه في مسار وحدة الجوال |
| تعليم إشعار مقروء (جوال) | api | mobile.notifications.read | find(user_id=auth) | SELF_SERVICE | — | normal |  |
| تسجيل/إلغاء رمز الدفع | api | mobile.push.register / unregister | PushService scoped to auth user; F7 dedup يبطل ربط الغير | SELF_SERVICE | — | normal | revoke مقصور على رموز المستخدم — لا IDOR |
| حالة/اختبار الدفع (إدارة جوال) | api | mobile.push.admin.status / admin.test | hub_is_owner() (403 وإلا) | OWNER_ONLY | owner | owner_only | لا يعرض المفتاح الخاص أبداً |
| اختبار الدفع (ويب) | route | mobileplatform.push.test | hub_is_owner()\|\|hub_flag('mobile'); يرسل لرموز المُنادي فقط | ASSIGNABLE_PERMISSION | flag:mobile | sensitive | myActiveTokens() — لا تسريب عابر |
| مركز Webhooks | route | webhooks.index/store/toggle/destroy/test/log/resend | gate()=hub_is_owner() في كل دالة | OWNER_ONLY | owner | owner_only | store يمر بحارس SSRF hub_outbound_ok |
| استقبال Webhook وارد | api | hook.receive (POST /hook/{token}) | token عشوائي 48 + HMAC اختياري + طابع زمن ±5د + throttle:120,1 | SYSTEM_INTERNAL | — | sensitive | لا مصادقة جلسة (مقصود)؛ الحمولة تُطمس Redactor::json وتُسقف 64KB؛ رد موحّد 404 للمفقود/المعطّل |
| إدارة الويبهوك الوارد | route | hooks.index/store/toggle/destroy | gate()=hub_is_owner() | OWNER_ONLY | owner | owner_only |  |
| لوحة/حفظ/اختبار n8n | route | integrations.n8n / n8n.save / n8n.test | gate()=hub_is_owner() | OWNER_ONLY | owner | owner_only | url يمر بـ hub_outbound_ok (SSRF) |
| اتصالات أودو | route | integrations.odoo.* | gate()=hub_is_owner() في كل دالة | OWNER_ONLY | owner | owner_only | يخزّن مفاتيح API مشفّرة |
| مركز المراسلة (بريد/تلجرام) | route | integrations.messaging.* | gate()=hub_is_owner() في كل دالة | OWNER_ONLY | owner | owner_only |  |
| مركز التكاملات | route | integrations.index / guide | gate()=hub_is_owner() | OWNER_ONLY | owner | owner_only |  |
| المجدول (automation/outbox/digest/backup/…) | api | routes/console.php | console kernel + withoutOverlapping (بلا سياق مستخدم) | SYSTEM_INTERNAL | — | sensitive | hub:digest يعمل بـ Auth::login(owner) لأرقام كاملة ثم يرسل للمالكين فقط |
| تفريع الدفع (created hook) | notification | HubNotification::booted created → PushService::scheduleFanout | payloadFor: عنوان عام حسب النوع + جسم عام + target؛ لا نص خام | SYSTEM_INTERNAL | — | sensitive | آمن بالبناء — يستهدف رموز صاحب الإشعار حصراً |
| إشعار المنشن/الرد | notification | CommentService::notifyAround/extractMentions | filterRecordVisible: hub_can(v)+hub_scope لكل مُشار إليه | PARTICIPATION_GOVERNED | module.v | sensitive | مُنطّق بالهدف — لا يبلغ مقتطف من لا يرى السجل. مؤمّن |
| إشعار الموافقة | notification | ApprovalService:196-199 | hub_approvers_for(module,record) — منطّق لكل معتمِد | PARTICIPATION_GOVERNED | flag:approve | sensitive | module=approvals؛ العنوان يحمل اسم السجل الأصلي والمعتمدون مُنطّقون |
| إشعار قاعدة التنبيه اليومية | notification | AlertEngine:243-254 | hub_can(mod,v) [155-157] + hub_scope لكل مستلم [199-207] | ASSIGNABLE_PERMISSION | module.v + flag:monitor | sensitive | النموذج المرجعي الصحيح — يفرض الصلاحية قبل النطاق |

## المفاتيح الدقيقة المقترحة (3)

| المفتاح (module.action) | التسمية | يحكم | متمايز عن | الحساسية | خريطة الإرث |
|---|---|---|---|---|---|
| `contracts.v / budgets.v / fin.v (إعادة استخدام مفاتيح العرض القائمة في محرّكات الأتمتة)` | رؤية الوحدة تحكم محتوى إشعارها الآليّ | استحقاق تلقّي إشعارات الأتمتة الحاملة لأسماء العقود ومبالغ الميزانيات والفواتير — يجب أن يُشتق من hub_can(module,'v') لا من راية monitor وحدها | راية monitor (تراقب صحة النظام والأعطال) ≠ رؤية سجلات العقود/المالية. الراية لا تمنح مصفوفة الصلاحيات (تعليق AlertEngine:151) | sensitive | موجود بالفعل (v/a/e/d لكل وحدة) — الإصلاح هو ربط الفان-آوت بها لا مفتاح جديد |
| `integrations.manage` | إدارة مركز التكامل | إنشاء/تعطيل/حذف Webhooks الصادرة والواردة، ربط n8n/أودو، إرسال بريد/تلجرام يدوي | حالياً غير قابل للفصل عن owner إطلاقاً (كل الدوال gate()=hub_is_owner). لا سبيل لتفويض مسؤول تكامل غير مالك | owner_only | new — قد يبقى owner-only حوكمةً؛ يُطرح فقط إن أُريد تفويضه لمشرف موثوق مع إبقاء حارس SSRF |
| `push.admin` | إدارة إعدادات الدفع | عرض حالة FCM واختبار الدفع الإداري (mobile.push.admin.*) | مبعثر حالياً: mobile.push.admin.* = owner فقط، بينما شاشة منصّة الجوال = owner\|\|flag:mobile — عدم اتساق في من يدير الدفع | sensitive | flag:mobile (لتوحيد المسارين تحت الراية نفسها) |

## النتائج (4)

| # | النوع | الخطورة | الموضع | الدليل | الإصلاح المقترح |
|---|---|---|---|---|---|
| 18.1 | field_leak | P2 | app/Console/Commands/HubAutomation.php:442-462 (notifyMonitors) + :306-317 (budgetsAuto); مصدر المستلمين :419-428 | recipientUsers(null) = المالكون + كل حامل hub_flag(u,'monitor'). ثم notifyMonitors يرشّح فقط بـ hub_scope: `if ($md && $recordId && ! hub_scope(DB::table($md['table'])..., $module, $u)->exists()) continue;` — ولا يوجد أي hub_can في الملف كله (grep أكّد غيابه). budgetsAuto نظيره: `if (! hub_scope(...budgets..., 'budgets', $ru)->exists()) continue;` ثم يكتب النص «الميزانية «{name}» بلغت {pct}% ({spent} من {amount})». لأن hub_scope لا يقيّد إلا المستخدم المُنطّق (project/company/client)، فمستخدم داخليّ غير مُنطّق يحمل راية monitor يجتاز ->exists() لأي صف، فيتلقّى: عناوين العقود (:244 «انتهى العقد «title»»، :269 مسودة تجديد)، أسماء+مبالغ الفواتير المولّدة (:372 module=fin)، أسماء المتكررات (:377)، وأسماء+مبالغ الميزانيات (:312) — لوحدات لا يملك عليها صلاحية عرض. AlertEngine:155-157 يحرس هذه الحالة عينها: `if (filled($rule->mod)) $to = $to->filter(fn($ru)=>$ru->role?->is_owner \|\| hub_can($ru,$rule->mod,'v'));` بتعليق صريح «راية monitor لا تمنح مصفوفةَ الصلاحيات». HubAutomation يفتقده. | في notifyMonitors وbudgetsAuto: أضف قبل/مع فحص hub_scope شرط `$u->role?->is_owner \|\| hub_can($u, $module, 'v')` (المالك يمرّ تلقائياً) — بنفس نمط AlertEngine:156. لوحدة 'recur' (:377) تحقّق من تعيين $md وإلا استعمل تخويل fin. يُثبَت باختبار يفشل أولاً: مستخدم monitor بلا contracts.v/budgets.v/fin.v لا يتلقّى إشعار الأتمتة. |
| 18.2 | field_leak | P3 | app/Support/FlowRunner.php:230-252 (tpl) + :162-179 (act notify، الفرع to != 'owners') | act() فرع notify: `$targets = ($a['to']??'owners')==='owners' ? hub_approvers_for($module,$m->id) : [(string)$a['to']];` — الفرع 'owners' مُنطّق (hub_approvers_for)، أمّا فرع المستلم الصريح فيُشعِر أيّ user id يضعه مؤلّف المسار بلا أي فحص hub_can/hub_scope على رؤيته للسجل، وينشئ HubNotification بـ module+record_id (رابط عميق) ونصّ tpl. وtpl (:238-252) يستبدل {field} بقيمة أي حقل ما عدا sec/file/img فقط — لا يحترم hub_field_mode (حقل مُخفى عبر role.field_rules يُحقَن نصاً)، فينفذ اسم السجل ({_display}) وقيم حقول غير سرّية إلى إشعار/تلجرام/بريد لمستلم لا يفتح السجل. النقر يُعاد فحصه (m.show) لكن نصّ الإشعار المتسرّب لا. | في فرع المستلم الصريح طبّق نفس بوّابة الرؤية (hub_can(v)+hub_scope->exists) قبل الإنشاء، أو اقصر مستلمي المسار على قائمة مُنطّقة. وفي tpl احترم hub_field_mode (تخطّ الحقول hide/ro الحسّاسة) بالإضافة إلى استثناء sec/file/img. |
| 18.3 | leak | P3 | app/Http/Controllers/Web/NotificationController.php:21,42 + app/Http/Controllers/Api/MobileCommController.php:474 (notificationShape) مقابل app/Support/PushService.php:200-224 | index/mini وnotificationShape يسلسلان n->text المخزون حرفياً لكل إشعار يملكه المستلم، دون إعادة ترشيح إن سُحبت صلاحيته على السجل بعد الإنشاء. لا يوجد مسار يحذف/يخفي الإشعارات القديمة عند سحب الوصول. PushService (نفس الدومين) يُظهر الوعي بالحساسية فيجرّد النص عمداً (GENERIC_BODY + عنوان عام حسب النوع، تعليق :44-45 «قد يحمل سرّاً/رقماً ماليّاً/جسم رسالة») — لكن مركز الإشعارات ويب/جوال لا يفعل. فعنوان/اسم/مبلغ محقون في text وقت الإنشاء يبقى ظاهراً لصاحبه بعد فقدان الوصول. | خيار متحفظ: قبول السلوك كأثر تاريخيّ لإشعارات المستخدم نفسه (منخفض الأثر). خيار أمتَن: للأنواع المرتبطة بسجل (module+record_id) أعِد فحص hub_can+hub_scope عند العرض وأخفِ/عمّم نص الإشعار إن لم يعُد المستلم يرى السجل — نظير تجريد PushService. |
| 18.4 | uncovered | P3 | app/Console/Commands/HubOutbox.php:155-170 (telegram) + AlertEngine.php:262-270 / :670-673 (إنشاء OutboxMessage بـ target=null) | OutboxMessage من AlertEngine يُنشأ بـ target=null وuser_id=$canSee->first()?->id ونص يحمل اسم سجل مُنطّق. HubOutbox::telegram يحلّ الوجهة: `$chat = $msg->target ?: userPref(user_id,'tg') ?: setting('notify.tg_chat')` — فإن لم يكن للمستلم tg في تفضيلاته يسقط إلى قناة تلجرام الشركة العامة notify.tg_chat، فيصل نصّ تنبيه يحمل اسم سجل مُنطّق إلى قناة عامّة قد تضم من لا يرى السجل. المستلم الداخلي مُنطّق في الجرس لكن التوجيه الخارجي يعمّم. | هذا اختيار حوكمة (مؤلّف القاعدة يختار قناة تلجرام) والقناة يضبطها المالك؛ لكن يُفضّل: عند سقوط الوجهة إلى notify.tg_chat لرسالة تحمل record_id مُنطّقاً، إمّا تعميم النص (بلا اسم السجل) أو منع السقوط للقناة العامة وإبقائها على الجرس الداخلي المُنطّق. تقييد أدنى: توثيق أن القنوات الخارجية غير مُنطّقة بحكم التصميم. |

## اختبارات مطلوبة

- اختبار يفشل أولاً: مستخدم يحمل راية monitor فقط (بلا hub_can contracts.v)، غير مُنطّق بمشروع/شركة، لا يتلقّى إشعار 'contract-exp' من HubAutomation بعد انتهاء عقد — إثبات فرض hub_can في notifyMonitors
- اختبار يفشل أولاً: مستخدم monitor بلا budgets.v لا يتلقّى إشعار budgetsAuto الحامل لاسم الميزانية ومبلغها
- اختبار: مستخدم monitor بلا fin.v لا يتلقّى إشعار 'recur' الحامل لمبلغ الفاتورة المولّدة (:372)
- اختبار: مستخدم owner ومستخدم monitor يملك contracts.v (كلاهما) يستمران في التلقّي — الإصلاح لا يُخرِس المستحقّين
- اختبار FlowRunner: مسار notify بمستلم صريح ('to'=user لا يرى السجل) لا يُنشئ إشعاراً له / أو لا يحقن قيمة حقل mode=hide في نصّه
- اختبار regression: مسار notify فرع 'owners' يبقى مُنطّقاً عبر hub_approvers_for كما هو
- اختبار residual: إشعار مرتبط بسجل أُنشئ ثم سُحب وصول المستلم على الوحدة → مركز الإشعارات لا يكشف اسم/مبلغ السجل في text (بعد تبنّي إعادة الفحص عند العرض)
- اختبار re-check على النقر: notifications.go لمستلم فقد الوصول يعطي 403/404 عبر m.show ولا يكشف بيانات (تثبيت السلوك القائم)
- اختبار SYSTEM_INTERNAL: hook.receive برمز صحيح دون توقيع صالح (سرّ مضبوط) يرد 401؛ وإعادة نفس X-Hub-Event-Id لا تُنشئ حدثاً مكرّراً
- اختبار OWNER_ONLY: مستخدم غير مالك يحمل كل الرايات يُرفض 403 على كل مسارات webhooks/hooks/n8n/odoo/messaging

