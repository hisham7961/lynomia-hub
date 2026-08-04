# n8n — التنصيب والربط مع Lynomia Hub

n8n محرّكُ سير عملٍ (workflow automation) مفتوح المصدر يعمل **خدمةً منفصلة** (Node.js)
بجوار نظام Lynomia Hub (PHP). لا يُدمَج في كود PHP — يُنصَّب على خادمٍ يشغّل Docker
(VPS/مخصّص) ثمّ يُربَط بالنظام عبر الويبهوك في الاتجاهين.

> إن كانت استضافتُك **PHP مشتركة لا تشغّل Docker/Node**، فـn8n لا يعمل عليها — استعمل
> **الويبهوك الوارد + المسارات الآلية (flows)** داخل النظام بدلاً منه.

## ١) التنصيب (على VPS يشغّل Docker)

```bash
cd deploy/n8n
cp .env.example .env      # حرّر: N8N_HOST, N8N_ENCRYPTION_KEY, POSTGRES_PASSWORD
docker compose up -d
docker compose logs -f n8n    # انتظر «Editor is now accessible»
```

n8n يستمع على `127.0.0.1:5678` فقط (لا يُكشف علناً). وجّه نطاقاً فرعيّاً إليه عبر
reverse proxy (nginx/Caddy) مع شهادة TLS. مثال Caddy:

```
n8n.yourdomain.com {
    reverse_proxy 127.0.0.1:5678
}
```

افتح `https://n8n.yourdomain.com` وأنشئ حساب المالك أول مرة.

## ٢) الربط مع Lynomia Hub

في النظام: **مركز التكامل ← n8n**، ضع رابط مثيلك (`https://n8n.yourdomain.com`)
فيظهر في المركز، ومفتاح n8n API (اختياري، من إعدادات n8n) للحالة.

### النظام → n8n (تشغيل سير عملٍ من حدثٍ في النظام)
- في n8n: أنشئ Workflow ببداية **Webhook node**، وانسخ رابطه.
- في النظام: **مركز التكامل ← Webhooks صادرة**، أضف اشتراكاً لذلك الرابط على الحدث المطلوب.
- كلُّ حدثٍ (عقد وُقّع، فاتورة، مهمة…) يبثّ إلى n8n بتوقيع HMAC، فينطلق سير العمل.

### n8n → النظام (إدخال بياناتٍ أو تشغيل فعلٍ في النظام)
- في النظام: **مركز التكامل ← الويبهوك الوارد**، أنشئ نقطةً وانسخ رابطها وسرّها.
- في n8n: عقدة **HTTP Request** إلى `https://yourhub.com/hook/{token}` بجسم JSON،
  وأضف ترويسة `X-Hub-Signature: sha256=<hmac(body, secret)>` (عقدة Crypto → HMAC SHA256).

هكذا يتدفّق العمل بين النظامَين في الاتجاهين — n8n للأتمتة المرئية، والنظام مصدرَ
الأحداث ووجهةَ الأفعال. كلاهما تحت سيطرتك، على خادمك.

## ٣) التحديث والنسخ الاحتياطي

```bash
docker compose pull && docker compose up -d      # تحديث n8n
docker compose exec n8n_db pg_dump -U n8n n8n > n8n_backup_$(date +%F).sql   # نسخة
```

البيانات في مُجلّدَي Docker: `n8n_data` (سير العمل والاعتمادات) و`n8n_db_data` (القاعدة).
