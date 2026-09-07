<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * **سجلُّ أجهزة النقاط الطرفية** — Work OS · الطور J · WP-J.1 · §43.
 *
 * صفٌّ لكلّ جهازِ شركةٍ (Windows/macOS) سجّل نفسَه بالتسجيل اللاتماثليّ: الخادمُ
 * يخزّن **المفتاحَ العامَّ PEM فقط** — الخاصُّ يُولَّد على الجهاز ولا يصل هنا أبداً
 * (عقدُ التوقيع في docblock ‏`App\Support\Es256`، والفرضُ في `EndpointSignature`).
 *
 * **دلالاتُ الأعمدة الحاكمة:**
 *  • `device_uuid` هويّةُ الجهاز كما يعلنها وكيلُه (فريدة) — و`id` (uuid) هو
 *    المعرّفُ الخادميّ الذي يسافر في `X-Endpoint-Id`.
 *  • `public_key`/`pubkey_fp` يكتبهما مسارُ التسجيل وحدَه (نموذجُ `EndpointDevice`
 *    يشتقّ البصمةَ من المفتاح نفسِه فلا تفترقان) — مقفولان عن CRUD العامّ.
 *  • `status` (active|suspended|locked|retired) **قائمةُ سماحٍ في النموذج لا DB
 *    enum** (درسُ C10) — `string` بعرضٍ معلَنٍ حرفياً يحرسه `hub_col_widths()`.
 *  • `posture` وضعيّةٌ **صادقة** (WP-J.3): قراءةٌ منعها النظامُ تُخزَّن
 *    'not-configured' لا 'active' — لا ادّعاءَ حمايةٍ زائفاً (C15).
 *  • `hw` جردُ عتادٍ يكتبه heartbeat (WP-J.2) — **لا حقولَ مراقبةٍ فيه أبداً**
 *    (مُصادِقُ الابتلاع يرفضها 422 خادمياً).
 *  • ‏`employee_id` حسابُ المستخدم الحامل (نمطُ `stations.current_employee_id`)،
 *    و`asset_id`/`station_id` روابطُ سِكّة `(module, record_id)` القائمة.
 *  • `policy_id` سياسةُ USB/الوضعيّة (جدولُها في WP-J.2) — nullable حتى تهبط.
 *
 * **ليست `user_devices`**: تلك أجهزةُ جلساتِ المتصفّح (بصمةُ دخول) — هذه أجهزةُ
 * أسطولٍ بمفاتيحَ لا تماثليّة. لا دمجَ ولا لمسَ للأولى.
 *
 * **الفهارس على المسار الساخن:** أسطولُ الشركة `(company_id)`، وأجهزةُ موظفٍ
 * `(employee_id)` (تبويبُ 360 في WP-J.3)، وربطُ أصلٍ `(asset_id)`، وفرزُ الحالة
 * `(status)`، والصامتُ عن النبض `(last_heartbeat_at)`.
 *
 * وحدةُ سجلٍّ (`endpoints` في `config/hub.php`) فتحمل أعمدةَ الوحدة القياسية
 * (custom/meta/version/archived) — وتُدرَج في `HubBackup::RAW_TABLES` صراحةً
 * (نمطُ stations) كي لا يُستعاد النظامُ بأسطولٍ بلا مفاتيحَ فيصمتُ كلُّ وكيل.
 *
 * إنشاءٌ محروس (add-if-not-exists)، كتلةُ `Schema::create` حرفيّةٌ واحدة —
 * `hub_col_widths()` يقرأ مصدرَ الهجرات فلا عمودَ داخل حلقة.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('endpoint_devices')) {
            Schema::create('endpoint_devices', function (Blueprint $t) {
                $t->uuid('id')->primary();
                $t->string('device_uuid', 64)->unique();      // هويّةُ الوكيل — تُتحقَّق صيغتُها عند التسجيل
                $t->uuid('company_id')->nullable();           // تُسنَد من رمز التسجيل لا من الحمولة
                $t->uuid('employee_id')->nullable();          // حسابُ الحامل (users)
                $t->uuid('asset_id')->nullable();             // ربطُ الأصل الجرديّ
                $t->uuid('station_id')->nullable();           // ربطُ المقعد
                $t->string('hostname', 120);                  // يقصّه الكاتب بـmb_substr(120)
                $t->string('os', 20);                         // windows|macos|linux — allowlist عند التسجيل
                $t->json('hw')->nullable();                   // جردُ عتاد (WP-J.2) — لا مراقبةَ أبداً
                $t->string('agent_version', 30)->nullable();  // يقصّه الكاتب بـmb_substr(30)
                $t->text('public_key')->nullable();           // PEM **عامّ فقط** — النموذج يرفض أيّ مادةٍ خاصة
                $t->string('pubkey_fp', 64)->nullable()->unique(); // sha256 hex لبايتات DER — يشتقّه النموذج
                $t->string('status', 12)->default('active');  // active|suspended|locked|retired — allowlist في النموذج (C10)
                $t->json('posture')->nullable();              // وضعيّةٌ صادقة (WP-J.3) — denied = not-configured
                $t->uuid('policy_id')->nullable();            // سياسةُ WP-J.2 (endpoint_policies)
                $t->timestamp('last_heartbeat_at')->nullable();
                $t->json('custom')->nullable();               // الحقولُ المخصَّصة (ModuleController)
                $t->json('meta')->nullable();
                $t->integer('version')->default(1);           // القفلُ التفاؤليّ (HasVersions)
                $t->boolean('archived')->default(false)->index();
                $t->uuid('created_by')->nullable();
                $t->uuid('updated_by')->nullable();
                $t->timestamps();
                $t->softDeletes();

                $t->index('company_id', 'endpoint_devices_company_idx');           // أسطولُ الشركة
                $t->index('employee_id', 'endpoint_devices_employee_idx');         // أجهزةُ موظف (360)
                $t->index('asset_id', 'endpoint_devices_asset_idx');               // ربطُ الأصل
                $t->index('status', 'endpoint_devices_status_idx');                // فرزُ الحالة
                $t->index('last_heartbeat_at', 'endpoint_devices_heartbeat_idx');  // الصامتُ عن النبض
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('endpoint_devices');
    }
};
