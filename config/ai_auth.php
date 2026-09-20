<?php

/*
|--------------------------------------------------------------------------
| عائلاتُ المصادقة — أشكالُ الاعتمادِ لا أسماءُ المزوّدين (المرحلة ٢ · إغلاق)
|--------------------------------------------------------------------------
|
| **ما هذا الملفّ:** الأشكالُ المتكرّرةُ التي يقبلها الاعتمادُ عند البوّابة.
| كلُّ عائلةٍ تصف **حقولاً** — اسمَها ووجهتَها وسرّيّتَها وتحقّقَها — ولا تذكر
| مزوّداً واحداً باسمِه. وربطُ المزوّدِ بعائلتِه يعيش في `config/ai_providers.php`
| **مولَّداً من القياس**، لا هنا.
|
| **ولم تُخترَع هذه العائلاتُ — قُرئت.** مصدرُها `CredentialLiteLLMParams` في
| ‏`types/router.py:257` من الإصدارِ المثبَّت (1.101.0): واحدٌ وثلاثون حقلاً
| مُعلَناً، مُجمَّعةً بتعليقاتِ LiteLLM نفسِها — `## AZURE OAUTH ##` و
| `## VERTEX AI ##` و`## AWS BEDROCK / SAGEMAKER ##` و`## IBM WATSONX ##`.
| فمجموعاتُهم هي عائلاتُنا. والتفصيلُ في `docs/ai-hub/20-provider-discovery.md` §٤٫٣.
|
| **ولماذا عائلاتٌ أصلاً؟** لأنّ LiteLLM **لا تكشف** حقولَ كلِّ مزوّد:
| `get_provider_fields()` تُعيد حقولاً لثلاثةٍ من مئةٍ وخمسةٍ وخمسين وتُعيد
| قائمةً فارغةً لما عداهم (`utils.py:6249`). فالنقصُ في هذا الموضعِ وحدَه،
| وهنا يُسَدّ — بعائلاتٍ قليلةٍ لا بكتالوجٍ لمئةٍ وخمسةٍ وخمسين.
|
| **والحقولُ أسماؤها أسماءُ `litellm_params` لا أسماءٌ من عندنا** — فما يُكتب
| في الشاشةِ هو نفسُه ما يُرسَل في `credential_values`، بلا طبقةِ ترجمةٍ تُخطئ.
|
*/

return [

    /*
     |------------------------------------------------------------------
     | ① api_key — الافتراضُ الساحق
     |------------------------------------------------------------------
     | وهو افتراضُ LiteLLM نفسِها لا افتراضُنا: تعليقُها في
     | `utils.py:7330` يقول «litellm standardizes expected provider keys to
     | PROVIDER_API_KEY». فمزوّدٌ بلا استثناءٍ مقيسٍ شكلُه هذا.
     |
     | و`api_base` **اختياريٌّ دائماً** هنا — لأنّ LiteLLM تقبل تجاوزَ العنوانِ
     | عند كلِّ مزوّد. ومَن لا عنوانَ افتراضيَّ له يُرفَع حقلُه إلزاميّاً من
     | خيارِ `require_api_base` المولَّدِ من القياس، لا من سطرٍ هنا.
     */
    'api_key' => [
        'label'    => 'مفتاحُ واجهة',
        'label_en' => 'API key',
        'special'  => false,
        'fields'   => [
            [
                'key' => 'api_key', 'label' => 'مفتاح الواجهة', 'type' => 'password',
                'required' => true, 'secret' => true, 'sends_to' => 'credential',
                'hint' => 'يُرسَل إلى خزنةِ البوّابةِ مشفَّراً ولا يُكتَب في Hub.',
            ],
            [
                'key' => 'api_base', 'label' => 'نقطة النهاية', 'type' => 'text',
                'required' => false, 'secret' => false, 'sends_to' => 'credential',
                'rules' => ['url'],
                'hint' => 'اتركها فارغةً لاستعمالِ نهايةِ المزوّدِ القياسيّة.',
            ],
        ],
    ],

    /*
     |------------------------------------------------------------------
     | ② api_key_endpoint_version — المفتاحُ والنهايةُ وإصدارُ الواجهة
     |------------------------------------------------------------------
     | لمن يقرأ `*_API_VERSION` في شيفرتِه (قياسٌ لا تخمين). والإصدارُ يذهب إلى
     | **إعدادِ Hub** لا إلى الاعتماد: تغييرُه لا يستلزم تدويرَ مفتاح.
     */
    'api_key_endpoint_version' => [
        'label'    => 'مفتاحٌ ونهايةٌ وإصدارُ واجهة',
        'label_en' => 'API key + endpoint + version',
        'special'  => false,
        'fields'   => [
            [
                'key' => 'api_key', 'label' => 'مفتاح الواجهة', 'type' => 'password',
                'required' => true, 'secret' => true, 'sends_to' => 'credential',
            ],
            [
                'key' => 'api_base', 'label' => 'نقطة النهاية', 'type' => 'text',
                'required' => true, 'secret' => false, 'sends_to' => 'credential',
                'rules' => ['url', 'starts_with:https://'],
                'hint' => 'ليست سرّاً — لكنّها جزءٌ من الاعتماد: المفتاحُ صالحٌ لها وحدَها.',
            ],
            [
                'key' => 'api_version', 'label' => 'إصدار الواجهة', 'type' => 'text',
                'required' => true, 'secret' => false, 'sends_to' => 'config',
                'placeholder' => 'YYYY-MM-DD',
                'hint' => 'في إعدادِ Hub لا في الاعتماد — فتغييرُه لا يستلزم تدويرَ المفتاح.',
            ],
        ],
    ],

    /*
     |------------------------------------------------------------------
     | ③ api_key_account — مفتاحٌ ومعرّفُ حساب
     |------------------------------------------------------------------
     | لمن يقرأ `*_ACCOUNT_ID`. ومعرّفُ الحسابِ **ليس سرّاً** — يبقى في Hub
     | ظاهراً قابلاً للتحرير بلا مساسٍ بالمفتاح.
     */
    'api_key_account' => [
        'label'    => 'مفتاحٌ ومعرّفُ حساب',
        'label_en' => 'API key + account id',
        'special'  => false,
        'fields'   => [
            [
                'key' => 'api_key', 'label' => 'مفتاح الواجهة', 'type' => 'password',
                'required' => true, 'secret' => true, 'sends_to' => 'credential',
            ],
            [
                'key' => 'account_id', 'label' => 'معرّف الحساب', 'type' => 'text',
                'required' => true, 'secret' => false, 'sends_to' => 'config',
                'hint' => 'من لوحةِ حسابِك لدى المزوّد — وليس سرّاً.',
            ],
            [
                'key' => 'api_base', 'label' => 'نقطة النهاية', 'type' => 'text',
                'required' => false, 'secret' => false, 'sends_to' => 'credential',
                'rules' => ['url'],
            ],
        ],
    ],

    /*
     |------------------------------------------------------------------
     | ④ api_key_project — مفتاحٌ ونهايةٌ ومساحةُ مشروع
     |------------------------------------------------------------------
     | لمن يقرأ مفتاحَ مشروعٍ أو مستأجِرٍ في `litellm_params` (قياسٌ من
     | `param_keys`). والمشروعُ إعدادٌ لا سرّ.
     */
    'api_key_project' => [
        'label'    => 'مفتاحٌ ومساحةُ مشروع',
        'label_en' => 'API key + project/tenant',
        'special'  => false,
        'fields'   => [
            [
                'key' => 'api_key', 'label' => 'مفتاح الواجهة', 'type' => 'password',
                'required' => true, 'secret' => true, 'sends_to' => 'credential',
            ],
            [
                'key' => 'api_base', 'label' => 'نقطة النهاية', 'type' => 'text',
                'required' => true, 'secret' => false, 'sends_to' => 'credential',
                'rules' => ['url'],
            ],
            [
                'key' => 'project_id', 'label' => 'معرّف المشروع أو المستأجِر', 'type' => 'text',
                'required' => true, 'secret' => false, 'sends_to' => 'config',
            ],
        ],
    ],

    /*
     |------------------------------------------------------------------
     | ⑤ aws_signature — سلسلةُ اعتمادِ AWS
     |------------------------------------------------------------------
     | مجموعةُ `## AWS BEDROCK / SAGEMAKER ##` من `CredentialLiteLLMParams`.
     | و**المنطقةُ إلزاميّةٌ وليست سرّاً**؛ ورمزُ الجلسةِ اختياريٌّ لأنّ اعتمادَ
     | الدورِ المؤقّتَ وحدَه يحتاجه.
     */
    'aws_signature' => [
        'label'    => 'اعتمادُ AWS',
        'label_en' => 'AWS credentials',
        'special'  => true,
        'fields'   => [
            [
                'key' => 'aws_access_key_id', 'label' => 'معرّف مفتاح الوصول', 'type' => 'password',
                'required' => true, 'secret' => true, 'sends_to' => 'credential',
            ],
            [
                'key' => 'aws_secret_access_key', 'label' => 'المفتاح السرّي', 'type' => 'password',
                'required' => true, 'secret' => true, 'sends_to' => 'credential',
            ],
            [
                'key' => 'aws_session_token', 'label' => 'رمز الجلسة', 'type' => 'password',
                'required' => false, 'secret' => true, 'sends_to' => 'credential',
                'hint' => 'للاعتمادِ المؤقّتِ وحدَه — اتركه فارغاً مع مفتاحٍ دائم.',
            ],
            [
                'key' => 'aws_region_name', 'label' => 'المنطقة', 'type' => 'text',
                'required' => true, 'secret' => false, 'sends_to' => 'credential',
                'placeholder' => 'us-east-1',
                'hint' => 'جزءٌ من الاعتماد: التوقيعُ مرتبطٌ بالمنطقة.',
            ],
        ],
    ],

    /*
     |------------------------------------------------------------------
     | ⑥ gcp_service_account — حسابُ خدمةٍ بصيغةِ JSON
     |------------------------------------------------------------------
     | مجموعةُ `## VERTEX AI ##`. وملفُّ الحسابِ **سرٌّ كامل** — يمرّ إلى
     | الخزنةِ ولا يستقرّ في Hub، تماماً كمفتاح.
     */
    'gcp_service_account' => [
        'label'    => 'حسابُ خدمةٍ سحابيّ',
        'label_en' => 'Service account (JSON)',
        'special'  => true,
        'fields'   => [
            [
                'key' => 'vertex_credentials', 'label' => 'ملفّ حساب الخدمة (JSON)', 'type' => 'password',
                'required' => true, 'secret' => true, 'sends_to' => 'credential',
                'rules' => ['json'],
                'hint' => 'الصِق محتوى الملفَّ كاملاً — يُرسَل إلى الخزنةِ ولا يُكتَب عندنا.',
            ],
            [
                'key' => 'vertex_project', 'label' => 'معرّف المشروع', 'type' => 'text',
                'required' => true, 'secret' => false, 'sends_to' => 'config',
            ],
            [
                'key' => 'vertex_location', 'label' => 'المنطقة', 'type' => 'text',
                'required' => true, 'secret' => false, 'sends_to' => 'config',
                'placeholder' => 'us-central1',
            ],
        ],
    ],

    /*
     |------------------------------------------------------------------
     | ⑦ key_pair — توقيعٌ بزوجِ مفاتيح
     |------------------------------------------------------------------
     | لمن يقرأ مفتاحاً خاصّاً وبصمةً ومستأجِراً ومستخدِماً في `litellm_params`.
     | والمفتاحُ الخاصُّ سرٌّ؛ وما عداه معرّفاتُ حسابٍ ظاهرة.
     */
    'key_pair' => [
        'label'    => 'توقيعٌ بزوجِ مفاتيح',
        'label_en' => 'Key-pair signature',
        'special'  => true,
        'fields'   => [
            [
                'key' => 'private_key', 'label' => 'المفتاح الخاصّ', 'type' => 'password',
                'required' => true, 'secret' => true, 'sends_to' => 'credential',
            ],
            [
                'key' => 'fingerprint', 'label' => 'بصمة المفتاح', 'type' => 'text',
                'required' => true, 'secret' => false, 'sends_to' => 'config',
            ],
            [
                'key' => 'tenancy_id', 'label' => 'معرّف المستأجِر', 'type' => 'text',
                'required' => true, 'secret' => false, 'sends_to' => 'config',
            ],
            [
                'key' => 'user_id', 'label' => 'معرّف المستخدِم', 'type' => 'text',
                'required' => true, 'secret' => false, 'sends_to' => 'config',
            ],
            [
                'key' => 'region', 'label' => 'المنطقة', 'type' => 'text',
                'required' => true, 'secret' => false, 'sends_to' => 'config',
            ],
        ],
    ],

    /*
     |------------------------------------------------------------------
     | ⑧ openai_compatible — النهايةُ العامّة
     |------------------------------------------------------------------
     | لنهايةٍ متوافقةٍ مع OpenAI يملكها المُشغِّل: بوّابةٌ محليّةٌ أو مستضافةٌ
     | ذاتيّاً أو مزوّدٌ ليس من الدرجةِ الأولى. **والنهايةُ إلزاميّةٌ هنا**
     | لأنّه لا نهايةَ قياسيّةً يُسقَط إليها، **والمفتاحُ اختياريٌّ** لأنّ
     | البوّاباتِ المحليّةَ كثيراً ما لا تطلبه.
     |
     | **ولا ثقبَ في الحارس:** هذه العائلةُ تمرّ بـ`AiGateway::outboundGate()`
     | كغيرِها — الشروطُ الخمسةُ نفسُها، والتدقيقُ نفسُه، والعزلُ نفسُه.
     */
    'openai_compatible' => [
        'label'    => 'نهايةٌ متوافقةٌ مع OpenAI',
        'label_en' => 'OpenAI-compatible endpoint',
        'special'  => false,
        'fields'   => [
            [
                'key' => 'api_base', 'label' => 'نقطة النهاية', 'type' => 'text',
                'required' => true, 'secret' => false, 'sends_to' => 'credential',
                'rules' => ['url'],
                'placeholder' => 'https://gateway.example.com/v1',
                'hint' => 'تمرّ بحارسِ الصادرِ كأيِّ هدف — بروتوكولٌ مسموحٌ ومضيفٌ غيرُ داخليّ.',
            ],
            [
                'key' => 'api_key', 'label' => 'مفتاح الواجهة', 'type' => 'password',
                'required' => false, 'secret' => true, 'sends_to' => 'credential',
                'hint' => 'اتركه فارغاً إن كانت النهايةُ لا تطلب مفتاحاً.',
            ],
        ],
    ],

];
