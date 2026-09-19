<?php

/*
|--------------------------------------------------------------------------
| كتالوجُ المزوّدين — وصفٌ لا شيفرة (المرحلة ٢ · W1)
|--------------------------------------------------------------------------
|
| **ما هذا الملفّ:** وصفٌ تصريحيٌّ لكيفيّةِ **إعدادِ** مزوّد — حقولُه ووجهةُ
| كلِّ حقلٍ وتحقّقُه وأسلوبُ اكتشافِ نماذجِه. لا أكثر.
|
| **وما ليس هو:** ليس نسخةً من معرفةِ LiteLLM. لا أسعارَ، ولا قدراتِ نماذج،
| ولا وسائطَ مدعومة، ولا أسماءَ نماذج. تلك كلُّها تُقرأ من البوّابةِ وقتَ
| الحاجة — وقد أثبت W0 لماذا: `ModelInfoBase` يحمل **٧٦ حقلَ تسعير** وأكثرَ من
| ثلاثين عَلَمَ قدرة، تتغيّر مع كلِّ ترقية. فنسخةٌ منها في PHP تتقادم قبل أن
| تُدفَع.
|
| **والمبدأُ الحاكم:** `Hub = Control Plane` · `LiteLLM = AI Gateway`.
| فـHub لا يعرف كيف يتحدّث إلى مزوّد — يعرف ماذا يسأل المستخدمَ عنه، وأين
| يذهب كلُّ جواب. و**أيُّ `if ($provider === '…')` في `app/` نقضٌ لهذا العقد**،
| ويسقطه اختبارٌ صريح.
|
| **الخمسةُ أدناه مراجعُ لا التزامٌ نهائيّ.** غرضُها إثباتُ أنّ محرّكاً واحداً
| يصف أشكالاً مختلفةً بلا فرعٍ شرطيٍّ لمزوّدٍ بعينِه.
|
*/

return [

    /*
     |------------------------------------------------------------------
     | ① Azure OpenAI — المرجعُ المركّب (قرارُ المالك · Q1)
     |------------------------------------------------------------------
     |
     | أربعةُ حقولٍ بثلاثةِ سلوكيّاتٍ مختلفة — وهو وحدَه من الخمسةِ يُجبِر
     | المخطَّطَ على التفريق:
     |
     |   `api_key`      سرٌّ      → الاعتماد
     |   `api_base`     **ليس سرّاً** → الاعتماد   ← يُثبِت أنّ الوجهةَ ليست السرّيّة
     |   `api_version`  ليس سرّاً → إعدادُ Hub
     |   `deployment`   ليس سرّاً → إعدادُ Hub
     |
     | ولولا هذا المزوّدُ لأمكن بناءُ «مخطَّطٍ ديناميّ» هو في الحقيقةِ نموذجٌ
     | واحدٌ متنكّر: حقلٌ سرّيٌّ واحدٌ يذهب إلى وجهةٍ واحدة.
     |
     | **واكتشافُ النماذجِ `manual` بدليلٍ مقروء:** `utils.py:7422` في الصورةِ
     | المثبّتة يستثني Azure صراحةً ويُعيد النصَّ النائب `"Azure-LLM"` بدل
     | قائمةِ نماذج. فادّعاءُ اكتشافٍ هنا كذبٌ على الشاشة.
     */
    'azure_openai' => [
        'label'       => 'Azure OpenAI',
        'label_en'    => 'Azure OpenAI',
        'icon'        => 'azure',
        'litellm_key' => 'azure',
        'auth'        => 'api_key_endpoint',
        'discovery'   => 'manual',
        'discovery_note' => 'النشرُ خاصٌّ بالحساب، ولا تُعيد البوّابةُ قائمةَ نماذجٍ لهذا المزوّد — تُضاف النماذجُ يدويّاً.',
        'docs_url'    => 'https://docs.litellm.ai/docs/providers/azure',
        'fields'      => [
            [
                'key'         => 'api_key',
                'label'       => 'مفتاح الواجهة',
                'type'        => 'password',
                'required'    => true,
                'secret'      => true,
                'sends_to'    => 'credential',
                'hint'        => 'من بوّابةِ Azure ← المفاتيحُ ونقطةُ النهاية.',
            ],
            [
                'key'         => 'api_base',
                'label'       => 'نقطة النهاية',
                'type'        => 'text',
                'required'    => true,
                'secret'      => false,
                'sends_to'    => 'credential',
                'placeholder' => 'https://<اسم-المورد>.openai.azure.com',
                'rules'       => ['url', 'starts_with:https://'],
                'hint'        => 'ليست سرّاً — لكنّها جزءٌ من الاعتماد: المفتاحُ صالحٌ لهذه النهايةِ وحدَها.',
            ],
            [
                'key'         => 'api_version',
                'label'       => 'إصدار الواجهة',
                'type'        => 'text',
                'required'    => true,
                'secret'      => false,
                'sends_to'    => 'config',
                'placeholder' => 'YYYY-MM-DD',
                'rules'       => ['regex:/^\d{4}-\d{2}-\d{2}(-preview)?$/'],
                'hint'        => 'في إعدادِ Hub لا في الاعتماد — فتغييرُه لا يستلزم تدويرَ المفتاح.',
            ],
            [
                'key'         => 'deployment',
                'label'       => 'اسم النشر',
                'type'        => 'text',
                'required'    => true,
                'secret'      => false,
                'sends_to'    => 'config',
                'hint'        => 'اسمُ النشرِ لديك — وقد يخالف اسمَ النموذج. يُستعمل لاحقاً في بناءِ معرّفِ النموذج.',
            ],
        ],
    ],

    /*
     |------------------------------------------------------------------
     | ② OpenAI — أبسطُ حالة: سرٌّ واحد
     |------------------------------------------------------------------
     | يُثبِت أنّ المحرّكَ لا يفرض تعقيداً حيث لا تعقيد: حقلٌ إلزاميٌّ واحدٌ
     | وحقلان اختياريّان، بلا `show_if` ولا `options`.
     */
    'openai' => [
        'label'       => 'OpenAI',
        'label_en'    => 'OpenAI',
        'icon'        => 'openai',
        'litellm_key' => 'openai',
        'auth'        => 'api_key',
        'discovery'   => 'catalog',
        'docs_url'    => 'https://docs.litellm.ai/docs/providers/openai',
        'fields'      => [
            [
                'key'      => 'api_key',
                'label'    => 'مفتاح الواجهة',
                'type'     => 'password',
                'required' => true,
                'secret'   => true,
                'sends_to' => 'credential',
            ],
            [
                'key'         => 'api_base',
                'label'       => 'نقطة نهايةٍ بديلة',
                'type'        => 'text',
                'required'    => false,
                'secret'      => false,
                'sends_to'    => 'credential',
                'placeholder' => 'https://api.example.com/v1',
                'rules'       => ['url'],
                'hint'        => 'اتركه فارغاً للنهايةِ الافتراضيّة. يُستعمل لنهايةٍ متوافقة.',
            ],
            [
                'key'      => 'organization',
                'label'    => 'معرّف المنظّمة',
                'type'     => 'text',
                'required' => false,
                'secret'   => false,
                'sends_to' => 'config',
            ],
        ],
    ],

    /*
     |------------------------------------------------------------------
     | ③ Anthropic — مصادقتان بديلتان
     |------------------------------------------------------------------
     | **[C]** `utils.py::validate_environment` يقبل `ANTHROPIC_API_KEY`
     | **أو** `ANTHROPIC_AUTH_TOKEN` — وهما **أسلوبا مصادقةٍ مختلفان** لا
     | اسمان لشيءٍ واحد. فيُمثَّلان بحقلٍ مُوجِّهٍ (`auth_mode`) وحقلين
     | شرطيّين — وهذا هو ما يُمارِس `show_if` ممارسةً حقيقيّة.
     |
     | ولاحظ ما يفرضه المُصادِق: حقلٌ شرطيٌّ **إلزاميّ** لا يُقبَل إلّا إذا
     | كان مُوجِّهُه قائمةً تحوي القيمةَ المشروطة. فتناقضٌ كـ«أظهِر عند
     | `auth_mode = oauth`» و`oauth` ليست في الخيارات ⇒ **يسقط عند التحميل**.
     */
    'anthropic' => [
        'label'       => 'Anthropic',
        'label_en'    => 'Anthropic',
        'icon'        => 'anthropic',
        'litellm_key' => 'anthropic',
        'auth'        => 'api_key',
        'discovery'   => 'catalog',
        'docs_url'    => 'https://docs.litellm.ai/docs/providers/anthropic',
        'fields'      => [
            [
                'key'      => 'auth_mode',
                'label'    => 'أسلوب المصادقة',
                'type'     => 'select',
                'required' => true,
                'secret'   => false,
                'sends_to' => 'config',
                'options'  => ['api_key' => 'مفتاح واجهة', 'auth_token' => 'رمز حامل'],
                'default'  => 'api_key',
            ],
            [
                'key'      => 'api_key',
                'label'    => 'مفتاح الواجهة',
                'type'     => 'password',
                'required' => true,
                'secret'   => true,
                'sends_to' => 'credential',
                'show_if'  => ['auth_mode' => 'api_key'],
            ],
            [
                'key'      => 'auth_token',
                'label'    => 'رمز الحامل',
                'type'     => 'password',
                'required' => true,
                'secret'   => true,
                'sends_to' => 'credential',
                'show_if'  => ['auth_mode' => 'auth_token'],
            ],
        ],
    ],

    /*
     |------------------------------------------------------------------
     | ④ Google Gemini — سرٌّ واحد
     |------------------------------------------------------------------
     | **مراجعةُ «المرادفات» (aliases) — ورفضُها بدليل:**
     |
     | ‏**[C]** المزوّدُ يقبل `GEMINI_API_KEY` أو `GOOGLE_API_KEY`. ويبدو هذا
     | حاجةً إلى خاصّيّةِ `aliases` — **وليس كذلك**: الاسمان **اسما متغيّرَي
     | بيئة**، و‏Hub **لا يمرّ بمتغيّراتِ البيئةِ إطلاقاً**. فهو يُرسل
     | `credential_values` إلى البوّابةِ مباشرةً (W0 · C1)، وفيها **مفتاحٌ
     | منطقيٌّ واحدٌ** هو `api_key`.
     |
     | فـ`aliases` خاصّيّةٌ **نُوقشت ولم تُضَف** — لأنّها تحلّ مشكلةً في طبقةٍ
     | لا يلمسها Hub. وإضافتُها كانت ستكون خاصّيّةً بلا مستهلِك.
     */
    'gemini' => [
        'label'       => 'Google Gemini',
        'label_en'    => 'Google Gemini',
        'icon'        => 'gemini',
        'litellm_key' => 'gemini',
        'auth'        => 'api_key',
        'discovery'   => 'catalog',
        'docs_url'    => 'https://docs.litellm.ai/docs/providers/gemini',
        'fields'      => [
            [
                'key'      => 'api_key',
                'label'    => 'مفتاح الواجهة',
                'type'     => 'password',
                'required' => true,
                'secret'   => true,
                'sends_to' => 'credential',
            ],
        ],
    ],

    /*
     |------------------------------------------------------------------
     | ⑤ OpenRouter — سرٌّ واحد + بياناتٌ وصفيّة
     |------------------------------------------------------------------
     | يُثبِت حقولاً **اختياريّةً تذهب إلى إعدادِ Hub** — بياناتُ هويّةٍ
     | يرسلها المزوّدُ في ترويساتِه، ليست سرّاً ولا جزءاً من الاعتماد.
     */
    'openrouter' => [
        'label'       => 'OpenRouter',
        'label_en'    => 'OpenRouter',
        'icon'        => 'openrouter',
        'litellm_key' => 'openrouter',
        'auth'        => 'api_key',
        'discovery'   => 'catalog',
        'docs_url'    => 'https://docs.litellm.ai/docs/providers/openrouter',
        'fields'      => [
            [
                'key'      => 'api_key',
                'label'    => 'مفتاح الواجهة',
                'type'     => 'password',
                'required' => true,
                'secret'   => true,
                'sends_to' => 'credential',
            ],
            [
                'key'         => 'site_url',
                'label'       => 'عنوان الموقع',
                'type'        => 'text',
                'required'    => false,
                'secret'      => false,
                'sends_to'    => 'config',
                'rules'       => ['url'],
                'hint'        => 'يُرسَل بياناً وصفيّاً للمزوّد — اختياريّ.',
            ],
            [
                'key'      => 'app_name',
                'label'    => 'اسم التطبيق',
                'type'     => 'text',
                'required' => false,
                'secret'   => false,
                'sends_to' => 'config',
            ],
        ],
    ],

];
