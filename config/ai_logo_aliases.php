<?php

/*
|--------------------------------------------------------------------------
| أسماءُ الشعاراتِ حيث لا يطابق المفتاحُ اسمَ العلامة — **مكتوبةٌ بيدٍ ومُراجَعة**
|--------------------------------------------------------------------------
|
| مفاتيحُ LiteLLM تصف **مساراً تقنيّاً** لا علامةً تجاريّة: `sagemaker_chat`
| و`aws_polly` و`s3_vectors` ثلاثةُ مسارات لشركةٍ واحدة، و`codestral` نموذجٌ
| لشركةٍ اسمُها غيرُه. فالمطابقةُ الآليّةُ بالاسمِ تُصيب ستّاً وخمسين وتترك
| الباقي.
|
| **وهذا الملفُّ لا يُولَّد.** كلُّ سطرٍ فيه قرارٌ بشريّ: «هذا المفتاحُ يخصّ
| هذه الشركةَ فعلاً». ولذلك قاعدتُه الحاكمة:
|
| > **لا يُكتَب سطرٌ إلّا إذا كان الشعارُ شعارَ صاحبِ المفتاحِ نفسِه أو
| > شعارَ الشركةِ الأمِّ التي يتبعها المنتجُ صراحةً.**
|
| **ولمَ هذه القاعدةُ بهذه الصرامة؟** لأنّ الخطأَ هنا ليس «شعارٌ ناقص» بل
| **شعارُ شركةٍ أخرى على بطاقةِ مزوّد** — وهو أسوأُ من الحرفين اللذين كانا:
| الحرفانِ يقولان «لا شعارَ لي»، والشعارُ الخطأُ **يكذب بثقة**. فما شكَّ فيه
| قارئٌ يُترَك بلا سطر، ويسقط إلى العلامةِ الحرفيّةِ بلا ضرر.
|
| والمفاتيحُ هنا **مفاتيحُ Hub** (`AiProviderRegistry::hubKey`): صغيرةٌ
| وشرطاتُها سفليّة.
|
*/

return [

    // ── صِيَغٌ تقنيّةٌ لمزوّدٍ واحد: نفسُ الشركةِ ونفسُ الشعار ──
    'ai21_chat'                 => 'ai21',
    'anthropic_text'            => 'anthropic',
    'azure_text'                => 'azure',
    'cohere_chat'               => 'cohere',
    'ollama_chat'               => 'ollama',
    'text_completion_openai'    => 'openai',
    'chatgpt'                   => 'openai',
    'hosted_vllm'               => 'vllm',
    'vertex_ai_beta'            => 'vertexai',
    'bedrock_mantle'            => 'bedrock',

    // ── لاحقةُ `_ai` وأخواتُها: الاسمُ التجاريُّ بلا اللاحقة ──
    'fireworks_ai'              => 'fireworks',
    'together_ai'               => 'together',
    'jina_ai'                   => 'jina',
    'fal_ai'                    => 'fal',
    'lambda_ai'                 => 'lambda',
    'featherless_ai'            => 'featherless',
    'friendliai'                => 'friendli',
    'runwayml'                  => 'runway',
    'topaz'                     => 'topazlabs',

    // ── منتجاتٌ تحت شركةٍ أمٍّ مُعلَنة ──
    // Codestral نموذجٌ من Mistral، و«‏text-completion» صيغتُه التكميليّة
    'codestral'                 => 'mistral',
    'text_completion_codestral' => 'mistral',
    'text_completion_inception' => 'inception',
    // NIM وRiva منصّتا NVIDIA
    'nvidia_nim'                => 'nvidia',
    'nvidia_riva'               => 'nvidia',
    // Llama نماذجُ Meta
    'meta_llama'                => 'meta',
    // FLUX من Black Forest Labs، وشعارُها في المجموعةِ باسمِها المختصر
    'black_forest_labs'         => 'bfl',
    // SageMaker وPolly وS3 خدماتُ AWS
    'sagemaker'                 => 'aws',
    'sagemaker_chat'            => 'aws',
    'sagemaker_nova'            => 'aws',
    'aws_polly'                 => 'aws',
    's3_vectors'                => 'aws',
    // Nova نماذجُ أمازون، ولها شعارُها الخاصُّ في المجموعة
    'amazon_nova'               => 'nova',
    // watsonx منصّةُ IBM
    'watsonx'                   => 'ibm',
    'watsonx_text'              => 'ibm',
    // DBRX نموذجُ Databricks وشعارُه شعارُها
    'databricks'                => 'dbrx',
    // DashScope خدمةُ نماذجِ علي بابا السحابيّة
    'dashscope'                 => 'alibabacloud',
    // Qwen نماذجُ علي بابا، ومنصّتاها تحملان الاسمَ نفسَه
    'qwen_ai_platform'          => 'qwen',
    'qwencloud'                 => 'qwen',
    // بوّابةُ Vercel للذكاء
    'vercel_ai_gateway'         => 'vercel',

    /*
     * ── **وما لا سطرَ له هنا مقصودٌ لا منسيّ** ──
     *
     * `custom` و`custom_openai` و`openai_like` و`aiohttp_openai` **أوضاعٌ
     * عامّةٌ متوافقةُ الواجهة** لا شركات: نهايتُها قد تكون خادماً محلّيّاً أو
     * أيَّ مزوّدٍ في العالم. ووضعُ شعارِ OpenAI عليها **يُسمّي صاحباً لا
     * وجودَ له** — فتبقى بعلامتِها الحرفيّة.
     *
     * و`litellm_proxy` و`litellm_agent` و`auto_router` مساراتٌ داخلُ البوّابةِ
     * نفسِها لا مزوّدون. وما عداها شركاتٌ **لا شعارَ لها في المجموعة**، فتسقط
     * إلى الحرفين بلا ادّعاء.
     */
];
