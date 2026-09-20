# مصفوفةُ تغطيةِ المزوّدين — **مولَّدةٌ لا تُحرَّر**

> تُولَّد بـ`php artisan hub:ai-coverage` من `config/ai_providers.php`،
> وتلك تُولَّد من قياسِ مصدرِ LiteLLM. **وتحريرُ هذا الملفِّ بيدٍ يُمحى**
> عند أوّلِ توليد؛ والتصحيحُ موضعُه قاعدةٌ في `AiProviderRegistry`.

## الخلاصة

| القياس | العدد |
|--------|------|
| إصدارُ LiteLLM المقيس | `1.101.0` |
| المزوّدون كلُّهم | 155 |
| **القابلون للإعدادِ من Hub** | **126** |

| الحالة | العدد | معناها |
|--------|------|--------|
| `SUPPORTED` | 79 | مدعومٌ كاملاً |
| `SUPPORTED_WITH_MANUAL_MODEL` | 39 | مدعومٌ بنماذجَ تُضاف يدويّاً |
| `SUPPORTED_WITH_SPECIAL_AUTH` | 8 | مدعومٌ بمصادقةٍ خاصّة |
| `NOT_CONFIGURABLE_FROM_HUB` | 29 | غيرُ قابلٍ للإعدادِ من Hub |

### عائلاتُ المصادقةِ على القابلِ للإعداد

| العائلة | العدد | الوصف |
|---------|------|-------|
| `api_key` | 110 | مفتاحُ واجهة |
| `aws_signature` | 5 | اعتمادُ AWS |
| `api_key_endpoint_version` | 3 | مفتاحٌ ونهايةٌ وإصدارُ واجهة |
| `api_key_project` | 3 | مفتاحٌ ومساحةُ مشروع |
| `api_key_account` | 2 | مفتاحٌ ومعرّفُ حساب |
| `gcp_service_account` | 2 | حسابُ خدمةٍ سحابيّ |
| `key_pair` | 1 | توقيعٌ بزوجِ مفاتيح |

### أوضاعُ اكتشافِ النماذجِ على القابلِ للإعداد

| الوضع | العدد |
|-------|------|
| `live` | 24 |
| `catalog` | 60 |
| `manual` | 42 |

## الاستثناءاتُ بأسبابِها

**ولا سطرَ هنا معناه «لم يُضَف بعد».** كلُّ صفٍّ سببُه تقنيٌّ مقروءٌ من
قياسِ المصدر: إمّا أنّ المزوّدَ لا سطحَ محادثةٍ له في البوّابةِ أصلاً،
وإمّا أنّ مصادقتَه لا تُدخَل من شاشةٍ البتّة.

- **ليس مزوّدَ إكمالٍ نصيّ — لا سطحَ محادثةٍ له في البوّابة** (7): `a2a_agent` · `auto_router` · `cursor` · `dotprompt` · `humanloop` · `langfuse` · `litellm_agent`
- **مصادقتُه تدفّقُ جهازٍ تفاعليٌّ يكتب ملفَّ رمزٍ على مضيفِ البوّابة — ولا اعتمادَ ساكنٌ يُدخَل من شاشة** (1): `github_copilot`
- **وسيطُه تحريرُ صور وتوليدُ صور لا المحادثة** (3): `black_forest_labs` · `recraft` · `stability`
- **وسيطُه تركيبُ كلام لا المحادثة** (1): `aws_polly`
- **وسيطُه تضمين وإعادةُ ترتيب لا المحادثة** (3): `infinity` · `jina_ai` · `voyage`
- **وسيطُه تنويعُ صور وإكمالُ نصّ لا المحادثة** (1): `topaz`
- **وسيطُه توليدُ صور لا المحادثة** (1): `fal_ai`
- **وسيطُه توليدُ صور وتركيبُ كلام وفيديو لا المحادثة** (1): `runwayml`
- **وسيطُه قراءةٌ ضوئيّة لا المحادثة** (1): `reducto`
- **وسيطُه مخزنُ متّجهات لا المحادثة** (5): `milvus` · `mongodb` · `pg_vector` · `s3_vectors` · `valkey`
- **وسيطُه ملفّات وواجهةُ ردود لا المحادثة** (1): `manus`
- **وسيطُه نسخُ صوت لا المحادثة** (3): `deepgram` · `nvidia_riva` · `soniox`
- **وسيطُه نسخُ صوت وتركيبُ كلام لا المحادثة** (1): `elevenlabs`

## المصفوفةُ كاملةً

| المزوّد (اسمُ البوّابة) | الحالة | المصادقة | اكتشافُ النماذج | ملاحظة |
|---|---|---|---|---|
| `ai21` | `SUPPORTED` | `api_key` | `catalog` |  |
| `aiml` | `SUPPORTED` | `api_key` | `catalog` |  |
| `amazon_nova` | `SUPPORTED` | `api_key` | `catalog` |  |
| `anthropic` | `SUPPORTED` | `api_key` | `live` |  |
| `anthropic_text` | `SUPPORTED` | `api_key` | `live` |  |
| `assemblyai` | `SUPPORTED` | `api_key` | `catalog` |  |
| `azure` | `SUPPORTED` | `api_key_endpoint_version` | `catalog` |  |
| `azure_ai` | `SUPPORTED` | `api_key_endpoint_version` | `live` |  |
| `azure_text` | `SUPPORTED` | `api_key_endpoint_version` | `live` |  |
| `baseten` | `SUPPORTED` | `api_key` | `catalog` |  |
| `cerebras` | `SUPPORTED` | `api_key` | `catalog` |  |
| `chatgpt` | `SUPPORTED` | `api_key` | `catalog` |  |
| `clarifai` | `SUPPORTED` | `api_key` | `catalog` |  |
| `cloudflare` | `SUPPORTED` | `api_key_account` | `catalog` |  |
| `codestral` | `SUPPORTED` | `api_key` | `catalog` |  |
| `cohere` | `SUPPORTED` | `api_key` | `live` |  |
| `cohere_chat` | `SUPPORTED` | `api_key` | `live` |  |
| `cometapi` | `SUPPORTED` | `api_key` | `catalog` |  |
| `custom_openai` | `SUPPORTED` | `api_key` | `live` |  |
| `darkbloom` | `SUPPORTED` | `api_key` | `catalog` |  |
| `dashscope` | `SUPPORTED` | `api_key` | `catalog` |  |
| `databricks` | `SUPPORTED` | `api_key` | `catalog` |  |
| `datarobot` | `SUPPORTED` | `api_key` | `catalog` |  |
| `deepinfra` | `SUPPORTED` | `api_key` | `catalog` |  |
| `deepseek` | `SUPPORTED` | `api_key` | `catalog` |  |
| `featherless_ai` | `SUPPORTED` | `api_key` | `catalog` |  |
| `fireworks_ai` | `SUPPORTED` | `api_key` | `live` |  |
| `friendliai` | `SUPPORTED` | `api_key` | `catalog` |  |
| `galadriel` | `SUPPORTED` | `api_key` | `catalog` |  |
| `gemini` | `SUPPORTED` | `api_key` | `live` |  |
| `gigachat` | `SUPPORTED` | `api_key` | `live` |  |
| `gradient_ai` | `SUPPORTED` | `api_key` | `catalog` |  |
| `groq` | `SUPPORTED` | `api_key` | `catalog` |  |
| `heroku` | `SUPPORTED` | `api_key` | `catalog` |  |
| `huggingface` | `SUPPORTED` | `api_key` | `catalog` |  |
| `hyperbolic` | `SUPPORTED` | `api_key` | `catalog` |  |
| `inception` | `SUPPORTED` | `api_key` | `catalog` |  |
| `lambda_ai` | `SUPPORTED` | `api_key` | `catalog` |  |
| `lemonade` | `SUPPORTED` | `api_key` | `live` |  |
| `litellm_proxy` | `SUPPORTED` | `api_key` | `live` |  |
| `maritalk` | `SUPPORTED` | `api_key` | `catalog` |  |
| `meta_llama` | `SUPPORTED` | `api_key` | `catalog` |  |
| `minimax` | `SUPPORTED` | `api_key` | `catalog` |  |
| `mistral` | `SUPPORTED` | `api_key` | `catalog` |  |
| `modelscope` | `SUPPORTED` | `api_key` | `catalog` |  |
| `moonshot` | `SUPPORTED` | `api_key` | `catalog` |  |
| `morph` | `SUPPORTED` | `api_key` | `catalog` |  |
| `nebius` | `SUPPORTED` | `api_key` | `catalog` |  |
| `nlp_cloud` | `SUPPORTED` | `api_key` | `catalog` |  |
| `novita` | `SUPPORTED` | `api_key` | `catalog` |  |
| `nscale` | `SUPPORTED` | `api_key` | `catalog` |  |
| `nvidia_nim` | `SUPPORTED` | `api_key` | `catalog` |  |
| `ollama` | `SUPPORTED` | `api_key` | `live` |  |
| `ollama_chat` | `SUPPORTED` | `api_key` | `live` |  |
| `openai` | `SUPPORTED` | `api_key` | `live` |  |
| `openrouter` | `SUPPORTED` | `api_key` | `catalog` |  |
| `ovhcloud` | `SUPPORTED` | `api_key` | `catalog` |  |
| `perplexity` | `SUPPORTED` | `api_key` | `catalog` |  |
| `petals` | `SUPPORTED` | `api_key` | `catalog` |  |
| `publicai` | `SUPPORTED` | `api_key` | `catalog` |  |
| `qwen_ai_platform` | `SUPPORTED` | `api_key` | `catalog` |  |
| `qwencloud` | `SUPPORTED` | `api_key` | `catalog` |  |
| `replicate` | `SUPPORTED` | `api_key` | `catalog` |  |
| `sambanova` | `SUPPORTED` | `api_key` | `catalog` |  |
| `snowflake` | `SUPPORTED` | `api_key_account` | `catalog` |  |
| `tencent` | `SUPPORTED` | `api_key` | `catalog` |  |
| `text-completion-codestral` | `SUPPORTED` | `api_key` | `catalog` |  |
| `text-completion-inception` | `SUPPORTED` | `api_key` | `catalog` |  |
| `text-completion-openai` | `SUPPORTED` | `api_key` | `live` |  |
| `together_ai` | `SUPPORTED` | `api_key` | `catalog` |  |
| `v0` | `SUPPORTED` | `api_key` | `catalog` |  |
| `vercel_ai_gateway` | `SUPPORTED` | `api_key` | `live` |  |
| `vllm` | `SUPPORTED` | `api_key` | `live` |  |
| `volcengine` | `SUPPORTED` | `api_key` | `catalog` |  |
| `wandb` | `SUPPORTED` | `api_key` | `catalog` |  |
| `watsonx` | `SUPPORTED` | `api_key_project` | `live` |  |
| `watsonx_text` | `SUPPORTED` | `api_key_project` | `live` |  |
| `xai` | `SUPPORTED` | `api_key` | `live` |  |
| `zai` | `SUPPORTED` | `api_key` | `catalog` |  |
| `a2a` | `SUPPORTED_WITH_MANUAL_MODEL` | `api_key` | `manual` |  |
| `ai21_chat` | `SUPPORTED_WITH_MANUAL_MODEL` | `api_key` | `manual` |  |
| `aiohttp_openai` | `SUPPORTED_WITH_MANUAL_MODEL` | `api_key` | `manual` |  |
| `apertis` | `SUPPORTED_WITH_MANUAL_MODEL` | `api_key` | `manual` |  |
| `bytez` | `SUPPORTED_WITH_MANUAL_MODEL` | `api_key` | `manual` |  |
| `charity_engine` | `SUPPORTED_WITH_MANUAL_MODEL` | `api_key` | `manual` |  |
| `chutes` | `SUPPORTED_WITH_MANUAL_MODEL` | `api_key` | `manual` |  |
| `cognition` | `SUPPORTED_WITH_MANUAL_MODEL` | `api_key` | `manual` |  |
| `compactifai` | `SUPPORTED_WITH_MANUAL_MODEL` | `api_key` | `manual` |  |
| `custom` | `SUPPORTED_WITH_MANUAL_MODEL` | `api_key` | `manual` |  |
| `docker_model_runner` | `SUPPORTED_WITH_MANUAL_MODEL` | `api_key` | `manual` |  |
| `empower` | `SUPPORTED_WITH_MANUAL_MODEL` | `api_key` | `manual` |  |
| `gdc` | `SUPPORTED_WITH_MANUAL_MODEL` | `api_key` | `manual` |  |
| `github` | `SUPPORTED_WITH_MANUAL_MODEL` | `api_key` | `manual` |  |
| `helicone` | `SUPPORTED_WITH_MANUAL_MODEL` | `api_key` | `manual` |  |
| `hosted_vllm` | `SUPPORTED_WITH_MANUAL_MODEL` | `api_key` | `manual` |  |
| `langflow` | `SUPPORTED_WITH_MANUAL_MODEL` | `api_key` | `manual` |  |
| `langgraph` | `SUPPORTED_WITH_MANUAL_MODEL` | `api_key` | `manual` |  |
| `libertai` | `SUPPORTED_WITH_MANUAL_MODEL` | `api_key` | `manual` |  |
| `llamafile` | `SUPPORTED_WITH_MANUAL_MODEL` | `api_key` | `manual` |  |
| `lm_studio` | `SUPPORTED_WITH_MANUAL_MODEL` | `api_key` | `manual` |  |
| `meta` | `SUPPORTED_WITH_MANUAL_MODEL` | `api_key` | `manual` |  |
| `nano-gpt` | `SUPPORTED_WITH_MANUAL_MODEL` | `api_key` | `manual` |  |
| `neosantara` | `SUPPORTED_WITH_MANUAL_MODEL` | `api_key` | `manual` |  |
| `oobabooga` | `SUPPORTED_WITH_MANUAL_MODEL` | `api_key` | `manual` |  |
| `openai_like` | `SUPPORTED_WITH_MANUAL_MODEL` | `api_key` | `manual` |  |
| `parasail` | `SUPPORTED_WITH_MANUAL_MODEL` | `api_key` | `manual` |  |
| `pinstripes` | `SUPPORTED_WITH_MANUAL_MODEL` | `api_key` | `manual` |  |
| `poe` | `SUPPORTED_WITH_MANUAL_MODEL` | `api_key` | `manual` |  |
| `predibase` | `SUPPORTED_WITH_MANUAL_MODEL` | `api_key_project` | `manual` |  |
| `ragflow` | `SUPPORTED_WITH_MANUAL_MODEL` | `api_key` | `manual` |  |
| `sap` | `SUPPORTED_WITH_MANUAL_MODEL` | `api_key` | `manual` |  |
| `scaleway` | `SUPPORTED_WITH_MANUAL_MODEL` | `api_key` | `manual` |  |
| `scx-ai` | `SUPPORTED_WITH_MANUAL_MODEL` | `api_key` | `manual` |  |
| `synthetic` | `SUPPORTED_WITH_MANUAL_MODEL` | `api_key` | `manual` |  |
| `tensormesh` | `SUPPORTED_WITH_MANUAL_MODEL` | `api_key` | `manual` |  |
| `triton` | `SUPPORTED_WITH_MANUAL_MODEL` | `api_key` | `manual` |  |
| `xiaomi_mimo` | `SUPPORTED_WITH_MANUAL_MODEL` | `api_key` | `manual` |  |
| `xinference` | `SUPPORTED_WITH_MANUAL_MODEL` | `api_key` | `manual` |  |
| `bedrock` | `SUPPORTED_WITH_SPECIAL_AUTH` | `aws_signature` | `live` |  |
| `bedrock_mantle` | `SUPPORTED_WITH_SPECIAL_AUTH` | `aws_signature` | `catalog` |  |
| `oci` | `SUPPORTED_WITH_SPECIAL_AUTH` | `key_pair` | `catalog` |  |
| `sagemaker` | `SUPPORTED_WITH_SPECIAL_AUTH` | `aws_signature` | `manual` |  |
| `sagemaker_chat` | `SUPPORTED_WITH_SPECIAL_AUTH` | `aws_signature` | `manual` |  |
| `sagemaker_nova` | `SUPPORTED_WITH_SPECIAL_AUTH` | `aws_signature` | `manual` |  |
| `vertex_ai` | `SUPPORTED_WITH_SPECIAL_AUTH` | `gcp_service_account` | `live` |  |
| `vertex_ai_beta` | `SUPPORTED_WITH_SPECIAL_AUTH` | `gcp_service_account` | `live` |  |
| `a2a_agent` | `NOT_CONFIGURABLE_FROM_HUB` | — | — | ليس مزوّدَ إكمالٍ نصيّ — لا سطحَ محادثةٍ له في البوّابة |
| `auto_router` | `NOT_CONFIGURABLE_FROM_HUB` | — | — | ليس مزوّدَ إكمالٍ نصيّ — لا سطحَ محادثةٍ له في البوّابة |
| `aws_polly` | `NOT_CONFIGURABLE_FROM_HUB` | — | — | وسيطُه تركيبُ كلام لا المحادثة |
| `black_forest_labs` | `NOT_CONFIGURABLE_FROM_HUB` | — | — | وسيطُه تحريرُ صور وتوليدُ صور لا المحادثة |
| `cursor` | `NOT_CONFIGURABLE_FROM_HUB` | — | — | ليس مزوّدَ إكمالٍ نصيّ — لا سطحَ محادثةٍ له في البوّابة |
| `deepgram` | `NOT_CONFIGURABLE_FROM_HUB` | — | — | وسيطُه نسخُ صوت لا المحادثة |
| `dotprompt` | `NOT_CONFIGURABLE_FROM_HUB` | — | — | ليس مزوّدَ إكمالٍ نصيّ — لا سطحَ محادثةٍ له في البوّابة |
| `elevenlabs` | `NOT_CONFIGURABLE_FROM_HUB` | — | — | وسيطُه نسخُ صوت وتركيبُ كلام لا المحادثة |
| `fal_ai` | `NOT_CONFIGURABLE_FROM_HUB` | — | — | وسيطُه توليدُ صور لا المحادثة |
| `github_copilot` | `NOT_CONFIGURABLE_FROM_HUB` | — | — | مصادقتُه تدفّقُ جهازٍ تفاعليٌّ يكتب ملفَّ رمزٍ على مضيفِ البوّابة — ولا اعتمادَ ساكنٌ يُدخَل من شاشة |
| `humanloop` | `NOT_CONFIGURABLE_FROM_HUB` | — | — | ليس مزوّدَ إكمالٍ نصيّ — لا سطحَ محادثةٍ له في البوّابة |
| `infinity` | `NOT_CONFIGURABLE_FROM_HUB` | — | — | وسيطُه تضمين وإعادةُ ترتيب لا المحادثة |
| `jina_ai` | `NOT_CONFIGURABLE_FROM_HUB` | — | — | وسيطُه تضمين وإعادةُ ترتيب لا المحادثة |
| `langfuse` | `NOT_CONFIGURABLE_FROM_HUB` | — | — | ليس مزوّدَ إكمالٍ نصيّ — لا سطحَ محادثةٍ له في البوّابة |
| `litellm_agent` | `NOT_CONFIGURABLE_FROM_HUB` | — | — | ليس مزوّدَ إكمالٍ نصيّ — لا سطحَ محادثةٍ له في البوّابة |
| `manus` | `NOT_CONFIGURABLE_FROM_HUB` | — | — | وسيطُه ملفّات وواجهةُ ردود لا المحادثة |
| `milvus` | `NOT_CONFIGURABLE_FROM_HUB` | — | — | وسيطُه مخزنُ متّجهات لا المحادثة |
| `mongodb` | `NOT_CONFIGURABLE_FROM_HUB` | — | — | وسيطُه مخزنُ متّجهات لا المحادثة |
| `nvidia_riva` | `NOT_CONFIGURABLE_FROM_HUB` | — | — | وسيطُه نسخُ صوت لا المحادثة |
| `pg_vector` | `NOT_CONFIGURABLE_FROM_HUB` | — | — | وسيطُه مخزنُ متّجهات لا المحادثة |
| `recraft` | `NOT_CONFIGURABLE_FROM_HUB` | — | — | وسيطُه تحريرُ صور وتوليدُ صور لا المحادثة |
| `reducto` | `NOT_CONFIGURABLE_FROM_HUB` | — | — | وسيطُه قراءةٌ ضوئيّة لا المحادثة |
| `runwayml` | `NOT_CONFIGURABLE_FROM_HUB` | — | — | وسيطُه توليدُ صور وتركيبُ كلام وفيديو لا المحادثة |
| `s3_vectors` | `NOT_CONFIGURABLE_FROM_HUB` | — | — | وسيطُه مخزنُ متّجهات لا المحادثة |
| `soniox` | `NOT_CONFIGURABLE_FROM_HUB` | — | — | وسيطُه نسخُ صوت لا المحادثة |
| `stability` | `NOT_CONFIGURABLE_FROM_HUB` | — | — | وسيطُه تحريرُ صور وتوليدُ صور لا المحادثة |
| `topaz` | `NOT_CONFIGURABLE_FROM_HUB` | — | — | وسيطُه تنويعُ صور وإكمالُ نصّ لا المحادثة |
| `valkey` | `NOT_CONFIGURABLE_FROM_HUB` | — | — | وسيطُه مخزنُ متّجهات لا المحادثة |
| `voyage` | `NOT_CONFIGURABLE_FROM_HUB` | — | — | وسيطُه تضمين وإعادةُ ترتيب لا المحادثة |

## كيف يُضاف مزوّدٌ مستقبلاً

**في الحالةِ الغالبة: لا شيء.** مزوّدٌ جديدٌ في ترقيةِ البوّابةِ يظهر في
`GET /model/settings`، ويسقط إلى العائلةِ الافتراضيّةِ `api_key` فيُنتج
مدخلَ كتالوجٍ صالحاً بلا سطرِ شيفرةٍ ولا سطرِ إعداد. ويكفي **تحديثُ
قائمةِ المزوّدين** من شاشةِ المزوّدين.

وإن كان شكلُه مختلفاً عن الافتراض:

1. أعِد القياسَ على الإصدارِ الجديد:
   `python3 deploy/litellm/tools/measure_providers.py <مصدر> > deploy/litellm/measured/litellm-<نسخة>.json`
2. `php artisan hub:ai-provider-map` ثمّ `php artisan hub:ai-coverage`.
3. إن لم تُصِبه قاعدةٌ قائمة، **أضِف قاعدةً نمطيّةً** في
   `AiProviderRegistry::classify()` — لا سطراً باسمِه.
4. وإن كان شكلُه فريداً حقّاً، أضِف **عائلةً** في `config/ai_auth.php`.

