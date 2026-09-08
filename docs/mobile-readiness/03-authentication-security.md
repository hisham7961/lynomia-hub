# 03 · المصادقةُ والأمن (السطحُ الطرفيّ الأصيل)

> Mobile Readiness · الطور H · H.1. مصادقةُ الجوال الأصيلة (first-party)، **مبنيّةٌ
> على الشجرة الحقيقيّة**. المبدأُ الحاكم (spec §Auth): **«NO weaker parallel login»**
> — سطحُ الجوال يفرض ما يفرضه الويبُ و`ApiAuth` بلا استثناء، لا بوّابةً أخفَّ.
> والتطبيقُ **غيرُ موثوق**: لا تخويلَ على زرٍّ خفيٍّ أو دورٍ مُدَّعىً أو وضعيّةٍ
> يُبلِّغها العميل.

## 1. تدفّقُ الدخول: login → (MFA) → session

المتحكّم: `app/Http/Controllers/Api/MobileAuthController.php`.

### 1a. `POST auth/login` (عامّ · `throttle:10,1` — نظيرُ الويب `web.php:90`)

الترتيبُ الأمنيّ الحرج (Critic F12/F13):

1. **تحقّقُ الاعتماد أوّلاً عبر مزوّد الإطار** (`login:82-87`):
   `Auth::getProvider()->retrieveByCredentials()` + `validateCredentials()` — **لا
   `Hash::check` يدويّ** (F13: تُعاد قاعدةُ الاعتماد نفسُها، وإعادةُ التجزئة عند الدخول).
   المصادقةُ عديمةُ الحالة: لا `Auth::login`، لا جلسةُ ويب.
2. **ردٌّ واحدٌ لا يميّز** لمجهولِ البريد وخاطئِ الكلمة (F12 · `login:87-93`):
   `UNAUTHENTICATED` «بيانات الدخول غير صحيحة» — لا أوراكل تعداد. الفشلُ يستدعي
   `AccountLockout::bump` (محرّكُ القفل نفسُه) + `hub_audit('دخول فاشل')`.
3. **الحراسُ الخمسةُ بعد إثبات الاعتماد وحدَه** (`accountGate:563` · F12): يُفصَح
   `ACCOUNT_RESTRICTED`/`LOCKDOWN` **للمالكِ المُثبَت** فقط — فحسابٌ موقوفٌ بكلمةٍ خاطئةٍ
   يبدو كبريدٍ مجهول (لا يُعاد فتحُ أوراكل التعداد الذي أغلقه الويبُ في v2.319).
4. **تسجيلُ/إيجادُ التنصيب** (`registerInstallation:593`): بمُعرّف التطبيق
   `installation_uuid`؛ فإن كان لمستخدمٍ آخر فهذا **تسليمُ جهاز** ⇒ إعادةُ ربطٍ + إبطالُ
   جلسات المالك السابق الحيّة على التنصيب (`:618-628` · نظيرُ F7).
5. **MFA أو سكُّ جلسة:**
   - `totp_enabled` ⇒ `MFA_REQUIRED` 401 + `{challenge_id, methods:["totp"], expires_at}`
     (`login:108-127`). التحدّي في الكاش قصيرُ الأجل (`mobile.mfa_challenge_min`، ٥ دقائق).
   - وإلا ⇒ `issueSession` مباشرةً.

### 1b. `POST auth/mfa/verify` (عامّ · `throttle:6,1` — نظيرُ الويب `web.php:93`)

يستهلك `challenge_id`، يعيد فحصَ `locked_until` (نظيرُ `otpVerify:113`)، ثم
`Totp::verifyOnce($secret, $code, 'login:'.$id)` (`mfaVerify:173`) — **السرُّ الواحد**
(`users.totp_secret_cipher`)، لا سرَّ TOTP ثانٍ. الفشلُ ⇒ `AccountLockout::bump` +
`hub_audit('فشل رمز التحقق')` + `MFA_REQUIRED` (التحدّي يبقى ضمن مهلته وخنقِه).
النجاحُ ⇒ استهلاكُ التحدّي مرّةً + **إعادةُ الحراس الخمسة** (`mfaVerify:198` — الحالةُ قد
تتغيّر بين الخطوتين) + `issueSession`.

**MFA صادقة (Critic F10):** يُعلَن `totp` **حصراً** في `methods[]` — ما يستطيع الخادمُ
التحقّقَ منه. WebAuthn موجودٌ للويب (`webauthn_credentials`)، لكنّ دورانَه للجوال **غيرُ
مبنيٍّ بعد**، فلا يُعلَن قدرةً زائفة. مؤجَّلٌ صراحةً (`10-mobile-app-handoff.md`).

### 1c. سكُّ الجلسة (`issueSession:647`) — خطواتُ إتمامٍ منفصلة، بلا `finishLogin`

`MobileSessionService::mint` يسكّ الزوجَ، ثم **خطواتٌ منتقاةٌ** (Critic F11) —
دونَ `finishLogin`/`Devices::bindOnLogin`/`session()` التي تمسّ ثقةَ `user_devices`
وجلسةَ الويب:
1. تصفيرُ العدّادات + ختمُ `last_login_at/ip` (`saveQuietly`).
2. `LoginSentry::inspect($user, $ip, $newDevice)` — يتعلّم العناوينَ ويرصد الغريب.
3. `hub_audit('دخول ناجح', … source=mobile)` — الجلسةُ والتنصيبُ فقط، **لا أيّ رمز**.

## 2. زوجُ الرمزَين — التجزئةُ حصراً

`app/Support/MobileSessionService.php`. القاعدةُ الواحدة: **الرمزان sha256 hex حصراً**
(نمطُ `ApiToken.token_hash`)، والنصُّ الصريحُ يُعاد مرّةً ولا يُخزَّن ولا يُسجَّل ولا
يُدقَّق. النصُّ: `lyma_…` للوصول (`:49`)، `lymr_…` للتحديث (`:55`).

| الرمز | العمود | المهلة | الافتراض |
|---|---|---|---|
| Access | `mobile_sessions.access_hash` (`char(64) unique`) | `setting('mobile.access_ttl_min')` | ١٥ دقيقة |
| Refresh | `mobile_sessions.refresh_hash` (`char(64) unique`) | `setting('mobile.refresh_ttl_days')` | ٣٠ يوماً |

## 3. التدويرُ المتجدّدُ + إبطالُ العائلة (single-use + reuse defense)

`MobileSessionService::rotate` (`:101`). كلُّ تدويرٍ ينشئ صفّاً جديداً في **العائلة
نفسِها** (`family_id`)، يحمل `prev_refresh_hash` = تجزئةَ الرمز المُستهلَك، ويُبطِل القديم:

- **تدويرٌ صالح** (`:133-157`) ⇒ `rotated`: زوجٌ جديدٌ + القديمُ `revoked_at`.
- **رمزٌ مُدوَّرٌ أُعيد استعمالُه** — يُطابِق صفّاً مُبطَلاً بالتجزئة نفسِها (`:122`) أو
  يُطابِق `prev_refresh_hash` (`:111`) ⇒ **إشارةُ هجوم**: `revokeFamily` تُبطِل
  **العائلةَ كلَّها** (`:113,123`) وتُعيد `reuse`.
- **رمزٌ لا يُعرَف/منتهٍ** ⇒ `invalid` (انتهاءٌ لا هجوم — لا إبطالَ عائلة).

المعالج `refresh` (`MobileAuthController.php:220`) يترجم: `reuse` ⇒
`SecurityRadar::record('إعادةُ استخدامِ رمزِ تحديث')` + `hub_notify(…,'sec',…)` +
`hub_audit` + `REFRESH_TOKEN_INVALID`؛ `invalid` ⇒ `REFRESH_TOKEN_INVALID`؛
`rotated` ⇒ **إعادةُ الحراس الخمسة على المصدر** (`refresh:264-274` — لا يُمدَّد رمزٌ
لحسابٍ صار محجوباً/محذوفاً) ثم الزوجُ الجديد.

**حتميّةٌ لا قرعة (C13):** البحثُ بـ`refresh_hash` (فريدٌ) وكلُّ استعلامٍ متساوٍ يحمل
`orderBy('id')` — فالسلوكُ نفسُه على SQLite وMySQL 8.

## 4. الحراسُ الخمسةُ للحساب (السطحُ الخامسُ يفرضها)

نظيرُ `AuthController::login` gates و`ApiAuth.php:43-63`. تُفرَض في **موضعين**:
`MobileSessionAuth` على كل طلبٍ مُصادَق (`MobileSessionAuth.php:59-72`)، و
`accountGate` عند login/mfa/refresh (`MobileAuthController.php:563-585`):

1. `status === 'موقوف'` ⇒ `ACCOUNT_RESTRICTED` 403.
2. `expires_at` ماضٍ ⇒ `ACCOUNT_RESTRICTED` (انتهاءُ الحساب).
3. `locked_until > now` ⇒ `ACCOUNT_RESTRICTED` بمهلةٍ متبقّية.
4. `allowed_ips` عبر `ip_allowed()` ⇒ `ACCOUNT_RESTRICTED` (حصرُ عنوان).
5. `security.lockdown` (المالكُ مُستثنى) ⇒ `LOCKDOWN` 503.

**قفلُ الحساب** عبر `AccountLockout::bump` — المحرّكُ نفسُه الذي يستعمله الويب
(`setting('auth.max_fail')`/`auth.lock_min`)، فلا قفلَ جوالٍ مُوازٍ أضعف (Critic F4).

## 5. تصعيدُ المصادقة (Step-Up) — مِنحةٌ مربوطةٌ لا نافذةُ جلسة

`POST auth/step-up` (`stepUp:427`). تصعيدُ الويب يختم نافذةً في `session()` — عديمُ
الجدوى للجوال. الجوالُ يُعيد استعمالَ **فحصِ الاعتماد وحدَه** `StepUp::checkCredential`
(`app/Support/StepUp.php:59` — TOTP لمن فعّله وإلا `Hash::check`، بلا `session()` ·
Critic F11)، ثم يُثبِت المِنحةَ في `mobile_stepup_grants` مربوطةً بـ**(المستخدم + جلسة
الجوال + الغرض + الانتهاء)**. الفشلُ ⇒ `STEP_UP_REQUIRED` 428. تستهلكها الأطوارُ
اللاحقة عبر `MobileSessionService::mobileStepUpFresh($session, $purpose)`
(`MobileSessionService.php:198`). طريقةُ التصعيد `totp|password` من `StepUp::method`.

## 6. تجزئةُ الرموز — sha256 حصراً (لا نصَّ صريحاً)

- `mobile_sessions.access_hash`/`refresh_hash`/`prev_refresh_hash` = `char(64)`
  (sha256 hex · migration `2026_09_18_000002:48-50`). البحثُ دائماً
  `where('access_hash', hash('sha256', $plain))` — نمطُ `ApiAuth.php:25`.
- النصُّ الصريحُ يُعاد **مرّةً** في `sessionPayload` (`MobileAuthController.php:686`) ولا
  يُخزَّن. **لا رمزٌ في تدقيقٍ أو سجلّ** أبداً — كلُّ التدقيقات تحمل الجلسة/التنصيب فقط.
- `push_tokens.token` **ليس سرَّ خادم**: رمزُ توجيهٍ لجهاز، لا مفتاحُ مزوّد (ذاك خارجيٌّ
  لا يهبط قاعدةً ولا نسخةً — spec §Push).

## 7. رادارُ الأمن والتدقيق — سكّةٌ واحدة (`source=mobile`)

لا «مركز أمن جوال» منفصل (spec §Security):

| الحدث | الاستدعاء |
|---|---|
| وصولٌ بلا رمز / رمزٌ غيرُ صالح / جلسةٌ مُبطَلة | `SecurityRadar::record` (`MobileSessionAuth.php:37,45,52`) |
| إعادةُ استخدامِ رمزِ تحديث | `SecurityRadar::record` + `hub_notify(…,'sec')` (`MobileAuthController.php:239-245`) |
| دخولٌ فاشل / محجوب / فشلُ MFA / فشلُ تصعيد | `hub_audit('دخول فاشل'|'دخول محجوب'|'فشل رمز التحقق'|'فشلُ تصعيد المصادقة')` |
| دخولٌ ناجح / خروج / إبطالُ جلسة / تصعيدٌ ناجح | `hub_audit(… source=mobile)` |

الوسمُ `source=mobile` عبر `tagMobile` (`request_source` · `:481`) — **وسمٌ لا تخويل**.
**لا يُدقَّق أبداً:** رمزٌ، كلمةُ مرور، سرُّ MFA، اعتمادُ دفع.

## 8. الإجرائيّاتُ المؤجَّلة — NOT_CONFIGURED (لا اختلاق، لا تخويلَ على وضعيّةِ العميل)

الخادمُ يفرض الأمن؛ **وضعيّةُ العميل ليست تخويلاً** أبداً (spec §Security):

| البند | الحالة |
|---|---|
| **App Attest / DeviceCheck** (iOS) | **NOT_CONFIGURED** — لا واجهةَ تخويلٍ عليها؛ لا يُبنى إثباتٌ ثم يُوثَق به قرارُ صلاحية. موثَّقٌ في `09-testing-security.md`. |
| **Play Integrity** (Android) | **NOT_CONFIGURED** — كسابقتها. |
| **كشفُ الجذر/الجيلبريك (root/jailbreak)** | **NOT_CONFIGURED** — لو أُضيفت لاحقاً فإشارةُ **عرضٍ** لا حاجزُ تخويل (العميلُ يكذب). |
| **البيومترية (Face/Touch ID)** | **app-side فقط** — الخادمُ لا يستقبل قوالبَ حيويّة قط؛ البيومتريةُ تفتح المخزنَ المحليّ للرمز، لا تُصادِق الخادمَ. |
| **WebAuthn في MFA للجوال** | **مؤجَّلٌ** — يُعلَن `totp` حصراً حتى يُبنى دورانُ الـassertion. |

---

**التحقّق:** كلُّ تدفّقٍ هنا مقروءٌ من `MobileAuthController`/`MobileSessionService`/
`StepUp`/`MobileSessionAuth`، مُختبَرٌ في `MobileAuthTest` (٣١) + `MobileAuthContractTest`
(٩) على المحرّكين: دخولٌ صالح/فاشل/موقوف/مقفول/منتهٍ/محصورٌ بعنوان/قفلُ طوارئ/خنق ·
MFA · انتهاءُ الوصول · تدويرٌ · رفضُ الرمز القديم بعد التدوير · إعادةٌ ⇒ إبطالُ عائلةٍ +
حدثُ أمن · خروجٌ/خروجٌ شامل/إلغاء · رفضُ المُبطَلة · **الرموزُ تجزئةٌ لا نصّ** · تصعيدٌ
يُحترَم ثم ينتهي.
