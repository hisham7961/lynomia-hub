# عقدُ `POST /v1/chat/completions` — مقروءاً من LiteLLM v1.101.0

> **مصدرُ كلِّ سطرٍ هنا شيفرةُ الإصدارِ المثبَّتِ لدينا**، لا وثيقةُ OpenAI
> العامّةُ ولا ذاكرةُ نموذج. والمراجعُ بالملفِّ والسطرِ من
> `litellm-1.101.0` كما فُكَّت في W0/C1.
>
> **ولمَ قراءةُ المصدرِ أصلاً والواجهةُ «متوافقةٌ مع OpenAI»؟** لأنّ
> التوافقَ يصدق في الشكلِ العامِّ ويكذب في التفاصيلِ التي يُبنى عليها
> التصنيف: **تجاوزُ نافذةِ السياقِ يعود `400` لا `413`**، و**نفادُ النماذجِ
> الصالحةِ يعود `429` لا `503`**. ومن بنى على الافتراضِ صنَّف انقطاعاً
> حدَّ معدّلٍ — وهو العيبُ الذي تفصله `AskFailures` أصلاً.

---

## ١) المسارُ والطلب

| | |
|---|---|
| المسار | `POST /v1/chat/completions` (وكذلك `/chat/completions`) |
| المرجع | `proxy/proxy_server.py:10644` — أربعةُ مزخرفاتِ `@router.post` |
| التصريح | `Depends(user_api_key_auth)` — ترويسةُ `Authorization: Bearer <مفتاحُ الإدارة>` |
| الجسم | **JSON حرٌّ** يُقرأ بـ`_read_request_body(request)` ولا يمرّ بنموذجِ Pydantic |
| التوثيق في الشيفرة | *«Follows the exact same API spec as OpenAI's Chat API»* |

فلا حقلَ يخصّ مزوّداً في هذا العقد، ولا يعرف Hub اسمَ مزوّدٍ ليبنيَ طلبَه.
والحقولُ التي نُرسلها:

```json
{
  "model": "<اسمُ النموذجِ كما سُجِّل في البوّابة>",
  "messages": [ … ],
  "tools": [ … ],
  "tool_choice": "auto",
  "max_tokens": 700,
  "temperature": 0,
  "stream": false
}
```

**و`stream: false` قرارٌ لا إغفال.** البثُّ يعيد `text/event-stream` تُجمَّع
أجزاؤه في العميل، وطلبُ أداةٍ مُجزّأً على دفعاتٍ يُبنى من `delta.tool_calls`
بفهارسَ — سطحٌ إضافيٌّ للتحليلِ في مسارٍ **مدخلُه غيرُ موثوق**. والجوابُ عندنا
يُعرَض دفعةً واحدةً بعد المصادقةِ على مراجعه، فلا يشتري البثُّ شيئاً.

---

## ٢) الأدواتُ في الطلب

`types/llms/openai.py:994` — `OpenAIChatCompletionToolParam`:

```json
{"type": "function",
 "function": {"name": "…", "description": "…", "parameters": { … }, "strict": false}}
```

و`parameters` مخطَّطُ JSON عاديّ (`ChatCompletionToolParamFunctionChunk:987`:
`name` مطلوبٌ وما عداه اختياريّ).

---

## ٣) تسلسلُ الرسائلِ في دورةِ الأدوات

الدورةُ الواحدةُ ثلاثُ رسائلَ بالترتيب:

| # | الدور | الشكل | المرجع |
|---|---|---|---|
| ١ | `assistant` | `{"role":"assistant","content":null,"tool_calls":[{"id":"…","type":"function","function":{"name":"…","arguments":"<JSON نصّاً>"}}]}` | `types/utils.py:1186` |
| ٢ | `tool` | `{"role":"tool","tool_call_id":"<id الخطوةِ ①>","content":"<نصّ>"}` | `types/llms/openai.py:840` |
| ٣ | `assistant` | الجوابُ النهائيّ | — |

**و`tool_call_id` إلزاميٌّ ويطابق `id` الذي ولّده النموذج** — لا معرّفَ
يُختَرع. وإن لم يحمل الردُّ معرّفاً ولّدت LiteLLM `uuid4` في
`ChatCompletionMessageToolCall.__init__` (`types/utils.py:1201`)، **فالمعرّفُ
يُؤخَذ من الردِّ بعد التطبيعِ لا من الردِّ الخام**.

**و`arguments` سلسلةُ نصٍّ تحمل JSON، لا كائنُ JSON.** فتحليلُها خطوةٌ
مستقلّةٌ تفشل مستقلّةً: نصٌّ غيرُ صالحٍ = `MALFORMED_TOOL_REQUEST`، لا انهيار.

---

## ٤) الردُّ الناجح

`ModelResponse` (`types/utils.py:2064`) يُسلسَل بـ
`model_dump_with_preserved_fields(result, exclude_unset=True)`
(`proxy_server.py:10737`).

> ⚠️ **`exclude_unset=True` يعني أنّ المفاتيحَ غيرَ المضبوطةِ تغيب من JSON.**
> فلا يُفترَض وجودُ `usage` ولا `tool_calls` ولا `system_fingerprint`. وكلُّ
> قراءةٍ في `AiChat` تمرّ بـ`??` ولا تفترض مفتاحاً.

```json
{"id":"chatcmpl-…","object":"chat.completion","created":1758,"model":"…",
 "choices":[{"index":0,"finish_reason":"tool_calls",
             "message":{"role":"assistant","content":null,"tool_calls":[…]}}],
 "usage":{"prompt_tokens":0,"completion_tokens":0,"total_tokens":0}}
```

### `finish_reason` — **مفرداتٌ مغلقةٌ تُطبّعها البوّابة**

`types/llms/openai.py:2336` تسعُ قيمٍ لا غير:

```
stop · content_filter · function_call · tool_calls · length
guardrail_intervened · eos · finish_reason_unspecified · malformed_function_call
```

و`map_finish_reason` (`litellm_core_utils/core_helpers.py:201`) تترجم إليها
مفرداتِ كلِّ مزوّد: `end_turn`/`STOP`/`COMPLETE` ⇒ `stop` ·
`tool_use` ⇒ `tool_calls` · `max_tokens`/`MAX_TOKENS` ⇒ `length` ·
`SAFETY`/`refusal` ⇒ `content_filter`. **وما لا تعرفه تُرجعه `stop`
وتُسجّل تحذيراً** (سطر ٢٠٤).

**وهذا بالضبطِ ما يجعل Hub غيرَ مرتبطٍ بمزوّد:** المُطبِّعُ عند البوّابةِ لا
عندنا، فقراءةُ `tool_calls`/`length` تصحّ على مزوّدٍ لم يُجرَّب بعد.

و`length` عندنا **ليست جواباً ناجحاً**: بلغ المخرَجُ سقفَه فالجوابُ مبتور.

### `usage`

`types/utils.py:1740` يرث `CompletionUsage`:
`prompt_tokens` · `completion_tokens` · `total_tokens`، وقد يحمل
`cost` و`prompt_tokens_details`/`completion_tokens_details`. **ونأخذ الثلاثةَ
الأولى وحدَها** — وما عداها تفصيلٌ يتغيّر بالإصدارات.

### ترويساتُ الردّ

`common_request_processing.py:1612`:
`x-litellm-call-id` · `x-litellm-model-id` · `x-litellm-model-name` ·
`x-litellm-version` · **`x-litellm-response-cost`**.

فالكلفةُ الحقيقيّةُ تعود **مع الردِّ نفسِه**، ولا تحتاج قراءةَ جدولِ إنفاق.

---

## ٥) الإخفاقُ — الجسمُ والرمزُ

كلُّ استثناءٍ ينتهي إلى `ProxyException` (`proxy/_types.py:3824`) ثمّ إلى
`openai_exception_handler` (`proxy_server.py:1603`):

```json
{"error": {"message":"…","type":"…","param":null,"code":"429"}}
```

وحالةُ HTTP = `int(exc.code)`.

### الرمزُ لكلِّ صنفِ إخفاق — **من `exceptions.py` حرفاً**

| الصنف | HTTP | بادئةُ الرسالة | السطر |
|---|---|---|---|
| `AuthenticationError` | `401` | `litellm.AuthenticationError:` | ١٤٠ |
| `PermissionDeniedError` | `403` | — | ٣٨٥ |
| `NotFoundError` | `404` | `litellm.NotFoundError:` | ١٨٤ |
| `BadRequestError` | `400` | `litellm.BadRequestError:` | ٢٢٨ |
| **`ContextWindowExceededError`** | **`400`** | `litellm.ContextWindowExceededError:` | ٥١٣ · ٥٢٦ |
| `ContentPolicyViolationError` | `400` | `litellm.ContentPolicyViolationError:` | ٦٠٠ |
| `UnprocessableEntityError` | `422` | — | ٣٠٢ |
| `Timeout` | `408` | `litellm.Timeout:` | ٣٤٧ |
| `RateLimitError` | `429` | `litellm.RateLimitError:` | ٤٤٠ |
| `BudgetExceededError` | `429` | — | ٩٧٢ |
| `InternalServerError` | `500` | — | ٧٤٠ |
| `APIConnectionError` | `500` | — | ٨٣٣ |
| `BadGatewayError` | `502` | — | ٦٩٢ |
| `ServiceUnavailableError` | `503` | — | ٦٤٤ |

### **مصيدتان في هذا الجدول**

① **تجاوزُ السياقِ يعود `400` لا رمزاً خاصّاً.** فالرمزُ وحدَه لا يكفي،
   **وبادئةُ الرسالةِ هي ما يفرّق** `ContextWindowExceededError` عن طلبٍ
   فاسدِ الشكل. ومن صنَّف بالرمزِ وحدَه قال «طلبٌ غيرُ صالح» لسؤالٍ كلُّ
   عيبِه أنّه احتاج سياقاً أوسع.

② **`429` ليست دائماً حدَّ معدّل.** `_types.py:3855` يفرض صراحةً:

   ```python
   if "No healthy deployment available" in self.message
      or "No deployments available" in self.message:
       self.code = "429"
   ```

   أي أنّ **نفادَ النماذجِ الصالحةِ — وهو انقطاعُ خدمةٍ — يُعاد بالرمزِ نفسِه
   الذي يعني «أبطئ»**. ولو صُنِّف حدَّ معدّلٍ لقيل للمستخدمِ «انتظر قليلاً
   ثمّ أعِد السؤال» عن عطلٍ لا يُصلحه الانتظار. فيُقرأ المتنُ:
   `PROVIDER_FAILURE` لا `RATE_LIMITED`.

و`retry-after` تُضبَط عند تهدئةِ الموجِّه (`common_request_processing.py:3366`)
فتُقرأ إن وُجدت.

---

## ٦) ما يُبنى على هذا العقد

| البند | الموضع |
|---|---|
| بناءُ الطلبِ وتحليلُ الردِّ والترويسات | `app/Support/AiChat.php` |
| خريطةُ الرمزِ والمتنِ إلى `AskFailures` | `AiChat::classify()` |
| تسلسلُ `assistant`→`tool` بـ`tool_call_id` | `app/Support/LiteLlmAskGenerator.php` |
| لقطاتُ ردٍّ مطابقةٌ للعقد | `tests/Feature/AskHub/LiteLlmFixtures.php` |

**ولا طلبَ حقيقيٌّ في هذه الدفعة.** كلُّ ما سبق مُختبَرٌ بـ`Http::fake()`
بلقطاتٍ مبنيّةٍ على هذا الملفّ.
