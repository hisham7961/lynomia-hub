# مثبِّتات وكيل النقاط الطرفية (Windows MSI · macOS PKG)

> **مسارُ التصحيح §6.** هندسةُ التغليف والتوزيع للوكيل — مع **توقيعٍ صادقٍ (C15):**
> لا Windows Authenticode ولا Apple Developer ID/notarization مُهيّأةٌ اليوم، فكلُّ
> المثبِّتات **UNSIGNED DEVELOPMENT BUILD** بلا ادّعاء. التحقّقُ عبر SHA-256 المنشورة.

## البنية

```
agent/packaging/
├── signing-status.sh        ← تقريرُ حالةِ التوقيع الصادق (مصدرُ الحقيقة لـCI والاختبار)
├── windows/
│   ├── lynomia-agent.wxs     ← بيانُ WiX: تثبيتُ الوكيل خدمةً (غيرُ موقَّع)
│   └── build-msi.sh          ← بناءُ MSI (candle/light) + توقيعُ Authenticode صادق (NOT_CONFIGURED)
└── macos/
    ├── lynomia-agent.plist   ← launchd للوكيل
    ├── scripts/postinstall   ← تسجيلُ الخدمة بعد التثبيت
    └── build-pkg.sh          ← بناءُ PKG (pkgbuild/productbuild) + Developer ID + notarization صادق (NOT_CONFIGURED)
```

## قنواتُ التوقيع الثلاث — الحالةُ الراهنة

| القناة | السرُّ المطلوب | الحالة |
|---|---|---|
| Windows Authenticode (OV/EV) | `WINDOWS_CODESIGN_PFX` | **NOT_CONFIGURED** |
| macOS Developer ID | `APPLE_DEVELOPER_ID_P12` | **NOT_CONFIGURED** |
| macOS notarization (notarytool) | `APPLE_NOTARY_KEY` | **NOT_CONFIGURED** |

`signing-status.sh` يقرأ هذه الأسرارَ ويُعلن NOT_CONFIGURED حين تغيب — **ولا يزعم
«signed»/«notarized» أبداً** بلا شهادةٍ حاضرةٍ وتحقّقٍ فعليّ. حين تُضبَط الأسرارُ
مستقبلاً يُوصَل خطُّ التوقيع في `build-msi.sh`/`build-pkg.sh` ويُتحقَّق منه، ثمّ تُقرّ
حالةُ الإصدار `signed`/`notarized` عبر البابَين الشرعيّين في نموذج `EndpointRelease`
(`attestVerifiedSignature`/`attestVerifiedNotarization`) — لا نموذجَ ويبٍ ولا CI يبلغهما خلسة.

## البناءُ المحليّ (تطويراً)

- **Windows:** على مضيفٍ فيه WiX Toolset: `sh windows/build-msi.sh <path-to-exe> <version>`.
- **macOS:** على مضيفِ ماك: `sh macos/build-pkg.sh <path-to-binary> <version>`.

كلا السكربتين **يتحقّقان من وجود الأدوات** ويخرجان بلطفٍ (بلا فشلٍ زائف) حيث لا
تتوفّر — فيمرّان على منصّةِ CI الأخرى دون تمثيل. والتوقيعُ خطوةٌ منفصلةٌ صريحةٌ لا
تُشغَّل إلا بشهادةٍ حاضرة.

## المبدأ

المثبِّتُ راحةُ نشرٍ لا سلطةُ ثقة: **سلطةُ الثقة هي SHA-256 المنشورة** (يتحقّق منها
`update.Apply` قبل أيّ تبديل)، لا توقيعُ المثبِّت. فالمثبِّتُ غيرُ الموقَّع صادقٌ ما دام
معه checksum يُقارَن — ولا «موقَّع» زائفٌ يُوهم ثقةً لا وجودَ لها.
