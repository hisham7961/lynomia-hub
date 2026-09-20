<?php

/*
|--------------------------------------------------------------------------
| خريطةُ شعاراتِ المزوّدين — **مولَّدةٌ لا تُحرَّر**
|--------------------------------------------------------------------------
|
| تُولَّد بـ`php artisan hub:ai-logos --src=<مجلَّدُ أيقونات>` من مفاتيحِ
| `config/ai_providers.php` والأسماءِ المكتوبةِ بيدٍ في
| `config/ai_logo_aliases.php`. **وتحريرُها بيدٍ يُمحى عند أوّلِ توليد.**
|
| الملفّاتُ نفسُها في `public/img/ai/providers`، وبوّابةُ
| `php artisan hub:ai-logos --check` تُسقط البناءَ إن انحرفت الخريطةُ عنها.
|
| **والإسنادُ والترخيصُ في `docs/ai-hub/26-provider-logos.md`** — وهو
| ليس تفصيلاً إداريّاً: الشعاراتُ علاماتٌ تجاريّةٌ لأصحابِها.
|
| ومزوّدٌ لا سطرَ له هنا **لا يبقى فارغاً**: `AiProviderRegistry::mark()`
| تُولّد له علامةً حرفيّةً من اسمِه. وهؤلاء بلا شعارٍ اليوم:
|
| a2a · a2a_agent · aiml · aiohttp_openai · auto_router
| bytez · charity_engine · chutes · clarifai · cognition
| compactifai · custom · custom_openai · darkbloom · datarobot
| deepgram · docker_model_runner · dotprompt · empower · galadriel
| gdc · gigachat · gradient_ai · helicone · heroku
| humanloop · infinity · langflow · lemonade · libertai
| litellm_agent · litellm_proxy · llamafile · maritalk · milvus
| mongodb · nano_gpt · neosantara · nlp_cloud · nscale
| oci · oobabooga · openai_like · ovhcloud · petals
| pg_vector · pinstripes · predibase · publicai · ragflow
| reducto · sap · scaleway · scx_ai · soniox
| synthetic · tensormesh · triton · valkey · wandb
*/

return [

    'source' => [
        'generator' => 'php artisan hub:ai-logos',
        'providers' => 95,
        'files'     => 74,
        'missing'   => 60,
    ],

    'logos' => [
        'ai21'                       => 'ai21.svg',
        'ai21_chat'                  => 'ai21.svg',
        'amazon_nova'                => 'nova-color.svg',
        'anthropic'                  => 'anthropic.svg',
        'anthropic_text'             => 'anthropic.svg',
        'apertis'                    => 'apertis-color.svg',
        'assemblyai'                 => 'assemblyai-color.svg',
        'aws_polly'                  => 'aws-color.svg',
        'azure'                      => 'azure-color.svg',
        'azure_ai'                   => 'azureai-color.svg',
        'azure_text'                 => 'azure-color.svg',
        'baseten'                    => 'baseten.svg',
        'bedrock'                    => 'bedrock-color.svg',
        'bedrock_mantle'             => 'bedrock-color.svg',
        'black_forest_labs'          => 'bfl.svg',
        'cerebras'                   => 'cerebras-color.svg',
        'chatgpt'                    => 'openai.svg',
        'cloudflare'                 => 'cloudflare-color.svg',
        'codestral'                  => 'mistral-color.svg',
        'cohere'                     => 'cohere-color.svg',
        'cohere_chat'                => 'cohere-color.svg',
        'cometapi'                   => 'cometapi-color.svg',
        'cursor'                     => 'cursor.svg',
        'dashscope'                  => 'alibabacloud-color.svg',
        'databricks'                 => 'dbrx-color.svg',
        'deepinfra'                  => 'deepinfra-color.svg',
        'deepseek'                   => 'deepseek-color.svg',
        'elevenlabs'                 => 'elevenlabs.svg',
        'fal_ai'                     => 'fal-color.svg',
        'featherless_ai'             => 'featherless-color.svg',
        'fireworks_ai'               => 'fireworks-color.svg',
        'friendliai'                 => 'friendli.svg',
        'gemini'                     => 'gemini-color.svg',
        'github'                     => 'github.svg',
        'github_copilot'             => 'githubcopilot.svg',
        'groq'                       => 'groq.svg',
        'hosted_vllm'                => 'vllm-color.svg',
        'huggingface'                => 'huggingface-color.svg',
        'hyperbolic'                 => 'hyperbolic-color.svg',
        'inception'                  => 'inception.svg',
        'jina_ai'                    => 'jina.svg',
        'lambda_ai'                  => 'lambda.svg',
        'langfuse'                   => 'langfuse-color.svg',
        'langgraph'                  => 'langgraph-color.svg',
        'lm_studio'                  => 'lmstudio.svg',
        'manus'                      => 'manus.svg',
        'meta'                       => 'meta-color.svg',
        'meta_llama'                 => 'meta-color.svg',
        'minimax'                    => 'minimax-color.svg',
        'mistral'                    => 'mistral-color.svg',
        'modelscope'                 => 'modelscope-color.svg',
        'moonshot'                   => 'moonshot.svg',
        'morph'                      => 'morph-color.svg',
        'nebius'                     => 'nebius.svg',
        'novita'                     => 'novita-color.svg',
        'nvidia_nim'                 => 'nvidia-color.svg',
        'nvidia_riva'                => 'nvidia-color.svg',
        'ollama'                     => 'ollama.svg',
        'ollama_chat'                => 'ollama.svg',
        'openai'                     => 'openai.svg',
        'openrouter'                 => 'openrouter-color.svg',
        'parasail'                   => 'parasail.svg',
        'perplexity'                 => 'perplexity-color.svg',
        'poe'                        => 'poe-color.svg',
        'qwen_ai_platform'           => 'qwen-color.svg',
        'qwencloud'                  => 'qwen-color.svg',
        'recraft'                    => 'recraft.svg',
        'replicate'                  => 'replicate.svg',
        'runwayml'                   => 'runway.svg',
        's3_vectors'                 => 'aws-color.svg',
        'sagemaker'                  => 'aws-color.svg',
        'sagemaker_chat'             => 'aws-color.svg',
        'sagemaker_nova'             => 'aws-color.svg',
        'sambanova'                  => 'sambanova-color.svg',
        'snowflake'                  => 'snowflake-color.svg',
        'stability'                  => 'stability-color.svg',
        'tencent'                    => 'tencent-color.svg',
        'text_completion_codestral'  => 'mistral-color.svg',
        'text_completion_inception'  => 'inception.svg',
        'text_completion_openai'     => 'openai.svg',
        'together_ai'                => 'together-color.svg',
        'topaz'                      => 'topazlabs.svg',
        'v0'                         => 'v0.svg',
        'vercel_ai_gateway'          => 'vercel.svg',
        'vertex_ai'                  => 'vertexai-color.svg',
        'vertex_ai_beta'             => 'vertexai-color.svg',
        'vllm'                       => 'vllm-color.svg',
        'volcengine'                 => 'volcengine-color.svg',
        'voyage'                     => 'voyage-color.svg',
        'watsonx'                    => 'ibm.svg',
        'watsonx_text'               => 'ibm.svg',
        'xai'                        => 'xai.svg',
        'xiaomi_mimo'                => 'xiaomimimo.svg',
        'xinference'                 => 'xinference-color.svg',
        'zai'                        => 'zai.svg',
    ],

];
