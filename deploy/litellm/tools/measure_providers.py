#!/usr/bin/env python3
"""قياسُ مزوّدي LiteLLM من شيفرةِ الإصدارِ المثبَّت — لا من الذاكرة ولا من التوثيق.

    pip download "litellm==<VERSION>" --no-deps -d /tmp/llm
    unzip -q /tmp/llm/litellm-*.whl -d /tmp/llm/src
    python3 deploy/litellm/tools/measure_providers.py /tmp/llm/src > measured.json

يقرأ هذا السكربتُ مصدرَ LiteLLM قراءةً ساكنة (ast + regex) ولا يستورده،
فلا يحتاج تنصيبَ اعتمادِه ولا يلمس شبكةً ولا سرّاً.
"""
import ast
import json
import os
import re
import sys

# أسماءُ مجلّداتِ التنفيذِ لا تطابق دائماً قيمةَ المزوّد في التعداد.
DIR_ALIASES = {
    "text-completion-openai": "openai", "chatgpt": "chatgpt", "openai_like": "openai_like",
    "custom_openai": "openai", "anthropic_text": "anthropic", "cohere_chat": "cohere",
    "vertex_ai_beta": "vertex_ai", "azure_text": "azure", "sagemaker_chat": "sagemaker",
    "sagemaker_nova": "sagemaker", "ollama_chat": "ollama", "ai21_chat": "ai21",
    "text-completion-codestral": "codestral", "text-completion-inception": "inception",
    "watsonx_text": "watsonx", "meta_llama": "meta_llama", "sap": "sap",
    "nano-gpt": "openai_like", "scx-ai": "openai_like", "vllm": "vllm",
    "litellm_agent": "", "auto_router": "", "dotprompt": "", "custom": "",
}
ENV_RE = re.compile(r'(?:get_secret_str|get_secret|os\.getenv|os\.environ\.get)\(\s*"([A-Z][A-Z0-9_]{2,})"')
# مفاتيحُ `litellm_params` التي يقرؤها المزوّد — وهي حمولةُ الاعتمادِ الحقيقيّةُ
# التي يرسلها Hub، لا أسماءُ البيئةِ التي يقرؤها المزوّدُ من عمليّةِ البوّابة.
PARAM_RE = re.compile(r'(?:litellm_params|optional_params)(?:\.get\(\s*|\[)"([a-z][a-z0-9_]{2,})"')
# ما يُعَدُّ سرّاً: لاحقةٌ سرّيّةٌ صريحةٌ في الاسم. و`_FILE`/`_URL`/`_DIR`
# استثناءٌ مقصود — فمسارُ ملفِ المفتاحِ ليس المفتاح، وعنوانُ إصدارِه ليس سرّاً.
SECRET_RE = re.compile(r'(KEY|TOKEN|SECRET|PASSWORD|CREDENTIALS|PASSPHRASE|APIKEY|JWT|BEARER|PRIVATE)$')


def read(path):
    try:
        with open(path, encoding="utf-8", errors="replace") as fh:
            return fh.read()
    except OSError:
        return ""


def enum_members(src_root):
    src = read(os.path.join(src_root, "litellm/types/utils.py"))
    tree = ast.parse(src)
    out = {}
    for node in ast.walk(tree):
        if isinstance(node, ast.ClassDef) and node.name == "LlmProviders":
            for stmt in node.body:
                if isinstance(stmt, ast.Assign) and isinstance(stmt.value, ast.Constant):
                    out[stmt.value.value] = stmt.targets[0].id
    return out


def chat_config_providers(src_root, names):
    src = read(os.path.join(src_root, "litellm/utils.py"))
    i = src.index("def _build_provider_config_map")
    body = src[i:src.index("\n    @staticmethod", i + 10)]
    consts = set(re.findall(r"LlmProviders\.([A-Z0-9_]+)\s*:", body))
    inv = {v: k for k, v in names.items()}
    return {inv[c] for c in consts if c in inv}


def modality_map(src_root, names):
    """لكلِّ نمطِ استدعاءٍ في LiteLLM (محادثة/تضمين/صوت/صورة/متجه…) المزوّدون المذكورون فيه.

    القياسُ من متنِ كلِّ `get_provider_<X>_config` مباشرة: أيُّ ثابتِ مزوّدٍ ورد فيه
    فالمزوّدُ مُعالَجٌ في ذلك النمط. طريقةٌ ساكنةٌ محافظة — تُسقِط ما يُبنى ديناميّاً.
    """
    src = read(os.path.join(src_root, "litellm/utils.py"))
    inv = {v: k for k, v in names.items()}
    spans = [(m.group(1), m.start()) for m in re.finditer(r"    def get_provider_([a-z_]+)_config", src)]
    out = {}
    for idx, (kind, start) in enumerate(spans):
        end = spans[idx + 1][1] if idx + 1 < len(spans) else len(src)
        body = src[start:end]
        for const in set(re.findall(r"LlmProviders\.([A-Z0-9_]+)", body)):
            if const in inv:
                out.setdefault(inv[const], set()).add(kind)
    return out


def json_registry(src_root):
    path = os.path.join(src_root, "litellm/llms/openai_like/providers.json")
    return json.loads(read(path)) if os.path.exists(path) else {}


def declared_lists(src_root):
    """القوائمُ المُعلَنةُ صراحةً في `litellm/constants.py`."""
    src = read(os.path.join(src_root, "litellm/constants.py"))
    out = {}
    for name in ("openai_compatible_providers", "openai_text_completion_compatible_providers",
                 "_openai_like_providers"):
        i = src.index(f"{name}: Final[list] = [")
        body = src[i:src.index("\n]", i)]
        out[name] = set(re.findall(r'^\s+"([a-z0-9_\-]+)"', body, re.M))
    return out


def compat_chain(src_root):
    src = read(os.path.join(src_root, "litellm/litellm_core_utils/get_llm_provider_logic.py"))
    body = src[src.index("def _get_openai_compatible_provider_info"):]
    return set(re.findall(r'custom_llm_provider\s*==\s*"([a-z0-9_\-]+)"', body))


def compat_chain_envs(src_root):
    """متغيّراتُ البيئةِ المقروءةُ داخل فرعِ كلِّ مزوّدٍ في سلسلةِ التوافق.

    كثيرٌ من المزوّدين لا يملكون مجلَّدَ تنفيذٍ خاصّاً؛ مفتاحُهم وعنوانُهم
    يُقرآن هنا فقط. فبلا هذا المصدرِ يبدو المزوّدُ بلا اعتمادٍ وهو ليس كذلك.
    """
    src = read(os.path.join(src_root, "litellm/litellm_core_utils/get_llm_provider_logic.py"))
    body = src[src.index("def _get_openai_compatible_provider_info"):]
    marks = [(m.group(1), m.start()) for m in
             re.finditer(r'custom_llm_provider\s*==\s*"([a-z0-9_\-]+)"', body)]
    out, bases = {}, {}
    for idx, (provider, start) in enumerate(marks):
        end = marks[idx + 1][1] if idx + 1 < len(marks) else len(body)
        chunk = body[start:end]
        out.setdefault(provider, set()).update(ENV_RE.findall(chunk))
        # عنوانٌ افتراضيٌّ مكتوبٌ حرفيّاً في الفرع = خدمةٌ مستضافةٌ لها نهايةٌ معروفة.
        # وغيابُه مع قراءةِ `*_API_BASE` = نهايةٌ يملكها المُشغِّل (محلّيّةٌ أو خاصّة).
        found = re.findall(r'"(https?://[^"]+)"', chunk)
        if found:
            bases.setdefault(provider, found[0])
    return out, bases


def static_model_keys(src_root):
    src = read(os.path.join(src_root, "litellm/__init__.py"))
    i = src.index("def _build_models_by_provider")
    body = src[i:src.index("\nmodels_by_provider", i)]
    return set(re.findall(r'^\s+"([a-z0-9_\-]+)"\s*:', body, re.M))


def tree_envs(src_root):
    """كلُّ اسمِ متغيّرِ بيئةٍ يُقرأ في الشجرة كلِّها، مرّةً واحدة.

    مصدرُ الدليلِ الرابع: بعضُ المزوّدين (replicate · nlp_cloud) لا مجلَّدَ لهم
    ولا فرعَ في سلسلةِ التوافق — مفتاحُهم يُقرأ في `main.py` أو في مساعدٍ بعيد.
    والاصطلاحُ الذي تُعلنه LiteLLM نفسُها في `_infer_valid_provider_from_env_vars`
    هو `{PROVIDER}_API_KEY`، فالنسبةُ بالسابقةِ نسبةٌ إلى اصطلاحِهم لا إلى ظنّي.
    """
    names = set()
    for base, _dirs, files in os.walk(os.path.join(src_root, "litellm")):
        for f in files:
            if f.endswith(".py"):
                names |= set(ENV_RE.findall(read(os.path.join(base, f))))
    return names


def by_prefix(provider, names):
    """أسماءُ البيئةِ التي تبدأ ببادئةِ المزوّدِ باصطلاحِ LiteLLM."""
    prefixes = {provider.upper().replace("-", "_"), provider.upper().replace("_", "").replace("-", "")}
    return {n for n in names if any(n == p or n.startswith(p + "_") for p in prefixes)}


def valid_models_exclusions(src_root):
    """مزوّدون يستثنيهم `get_valid_models` صراحةً فلا يُعيد لهم قائمةَ نماذج.

    استثناءٌ مكتوبٌ في الشيفرة — لا استنتاج: يُعيد نصّاً نائباً بدل الأسماء.
    """
    src = read(os.path.join(src_root, "litellm/utils.py"))
    i = src.index("def get_valid_models(")
    body = src[i:src.index("\ndef ", i + 10)]
    return set(re.findall(r'provider\s*==\s*"([a-z0-9_\-]+)"\s*:\s*\n\s+valid_models\.append', body))


def dir_for(provider, src_root):
    alias = DIR_ALIASES.get(provider, provider)
    if alias == "":
        return None
    path = os.path.join(src_root, "litellm/llms", alias)
    return alias if os.path.isdir(path) else None


def scan_dir(src_root, name):
    envs, params, text = set(), set(), []
    root = os.path.join(src_root, "litellm/llms", name)
    for base, _dirs, files in os.walk(root):
        for f in files:
            if f.endswith(".py"):
                body = read(os.path.join(base, f))
                text.append(body)
                envs |= set(ENV_RE.findall(body))
                params |= set(PARAM_RE.findall(body))
    return envs, params, "\n".join(text)


def main(src_root):
    names = enum_members(src_root)
    chat = chat_config_providers(src_root, names)
    modalities = modality_map(src_root, names)
    declared = declared_lists(src_root)
    chain_envs, chain_bases = compat_chain_envs(src_root)
    no_listing = valid_models_exclusions(src_root)
    all_envs = tree_envs(src_root)
    jreg = json_registry(src_root)
    compat = compat_chain(src_root)
    statics = static_model_keys(src_root)

    rows = {}
    for provider in names:
        d = dir_for(provider, src_root)
        envs, params, text = scan_dir(src_root, d) if d else (set(), set(), "")
        # ثلاثةُ مصادرَ للدليل: مجلَّدُ التنفيذ، وفرعُه في سلسلةِ التوافق، وسجلُّ JSON.
        # **دليلٌ مباشرٌ ودليلٌ واسع، ولكلٍّ استعمالُه.**
        # المباشرُ (مجلَّدُ المزوّدِ وفرعُه في سلسلةِ التوافقِ وسجلُّ JSON) عاليُ
        # الثقة: يخصُّ هذا المزوّدَ وحدَه. والواسعُ يضيف نسبةً بالبادئة، وهي
        # تلتقط أحياناً متغيّراتِ مزوّدٍ آخرَ يشاركه البادئة. فاختيارُ العائلةِ
        # يُبنى على المباشرِ وحدَه، ورفعُ الإلزامِ على الاثنين معاً.
        direct = set(envs) | chain_envs.get(provider, set())
        envs = direct | by_prefix(provider, all_envs)
        for key in ("api_key_env", "api_base_env"):
            value = jreg.get(provider, {}).get(key)
            if value:
                envs.add(value)
        rows[provider] = {
            "const": names[provider],
            "dir": d,
            # المحادثةُ مدعومةٌ إن كان له صنفُ إعدادٍ في الخريطة، أو كان مزوّدَ JSON
            # (فـget_provider_chat_config يسقط إليه)، أو كان azure المُعالَجَ قبل الخريطة.
            # المحادثةُ مدعومةٌ إن كان له صنفُ إعدادٍ في الخريطة، أو كان مزوّدَ JSON
            # (فـget_provider_chat_config يسقط إليه)، أو كان azure المُعالَجَ قبل الخريطة،
            # أو كان في قائمةِ التوافقِ المُعلَنةِ في constants.py.
            "chat_config": (provider in chat or provider in jreg or provider == "azure"
                            or provider in declared["openai_compatible_providers"]
                            or provider in declared["_openai_like_providers"]),
            "declared_openai_compatible": provider in declared["openai_compatible_providers"],
            "declared_openai_like": provider in declared["_openai_like_providers"],
            "declared_text_completion": provider in declared["openai_text_completion_compatible_providers"],
            "modalities": sorted(modalities.get(provider, ())),
            "json_registry": provider in jreg,
            "json_api_key_env": jreg.get(provider, {}).get("api_key_env"),
            "json_base_url": jreg.get(provider, {}).get("base_url"),
            "default_api_base": jreg.get(provider, {}).get("base_url") or chain_bases.get(provider),
            "openai_compatible_chain": provider in compat,
            "static_models": provider in statics,
            "live_discovery": bool(d) and "def get_models(" in text and provider not in no_listing,
            "excluded_from_listing": provider in no_listing,
            "aws_base": bool(d) and "BaseAWSLLM" in text,
            "vertex_base": bool(d) and ("VertexBase" in text or "VertexLLM" in text),
            "reads_api_version": sorted(e for e in envs if e.endswith("_API_VERSION")),
            "env_secret": sorted(e for e in envs if SECRET_RE.search(e)),
            "env_config": sorted(e for e in envs if not SECRET_RE.search(e)),
            "env_direct_secret": sorted(e for e in direct if SECRET_RE.search(e)),
            "env_direct_config": sorted(e for e in direct if not SECRET_RE.search(e)),
            # المفاتيحُ التي تخصُّ هذا المزوّدَ وحدَه (تحمل اسمَه) — ما عداها عامٌّ.
            "param_keys": sorted(k for k in params
                                 if k.startswith(provider.replace("-", "_").split("_")[0])),
        }

    # النسخةُ من بيانات الحزمة نفسها (dist-info) لا من _version.py — فذاك يقرؤها وقتَ التشغيل.
    version = "unknown"
    for entry in os.listdir(src_root):
        if entry.startswith("litellm-") and entry.endswith(".dist-info"):
            meta = read(os.path.join(src_root, entry, "METADATA"))
            m = re.search(r"^Version:\s*(\S+)", meta, re.M)
            if m:
                version = m.group(1)
    return {
        "litellm_version": version,
        "provider_count": len(names),
        "chat_capable": sum(1 for r in rows.values() if r["chat_config"]),
        "json_registry_count": len(jreg),
        "live_discovery_count": sum(1 for r in rows.values() if r["live_discovery"]),
        "providers": rows,
    }


if __name__ == "__main__":
    print(json.dumps(main(sys.argv[1] if len(sys.argv) > 1 else "/tmp/llm/src"),
                     ensure_ascii=False, indent=1, sort_keys=True))
