<?php

namespace App\Models;

use App\Traits\HasUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * **سجلُّ تفعيلِ حساب العميل** (Work OS · الطور B · WP-B.1 · §12).
 *
 * صفٌّ واحدٌ لكلِّ تفعيل: يحمل تجزيءَ الرمزِ الخام في الرابط (`token_hash`)،
 * وتجزيءَ الرمزِ السداسيّ ومهلتَه وعدّادَ محاولاته، ولحظةَ استهلاكه. النمطُ
 * الأمنيُّ هو نفسُه سابقةَ OTP التوقيع الإلكتروني (EsignController::sendOtp/unlock:
 * hash + expire + OutboxMessage + single-use) — **لا محرّكَ رموزٍ ثانٍ**.
 *
 * ── القاعدةُ الحاكمة (§12 · قواعدُ أمن الطور B): لا كلمةَ سرٍّ تُولَّد/تُرسَل/
 * تُخزَّن صريحةً أبداً. الحسابُ يُنشأ بلا كلمةِ سرٍّ صالحةٍ للدخول (تجزيءُ عشوائيٍّ
 * لا يعرفه أحد)، والعميلُ يضعها بنفسه في خطوةِ الوضع. أيُّ نصٍّ في OutboxMessage
 * يحمل رابطاً ورمزاً فقط — لا كلمةَ سرّ. `WorkOsActivationTest` يمسح الصادرَ
 * ويتأكّد.
 */
class AccountActivation extends Model
{
    use HasUuid;

    protected $table = 'account_activations';

    /** سقفُ محاولاتِ الرمز الخاطئة قبل حرقِ الصفّ (على نمطِ قفلِ الدخول) */
    public const MAX_OTP_ATTEMPTS = 5;

    protected $guarded = ['id'];

    protected $casts = [
        'otp_expires_at' => 'datetime',
        'consumed_at' => 'datetime',
        'attempts' => 'integer',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /** انتهت مهلةُ الرمز؟ (المهلةُ = عمرُ التفعيل كلِّه) */
    public function isExpired(): bool
    {
        return $this->otp_expires_at !== null && now()->gt($this->otp_expires_at);
    }

    /**
     * السجلُّ الحيُّ لرمزٍ خام: غيرُ مُستهلَك، بتجزيءِ الرمز المطابق. يُبحَث
     * بالتجزيء لا بالخام (SHA-256 hex) — نظيرُ `api_tokens.token_hash`.
     */
    public static function pendingByToken(string $token): ?self
    {
        $token = trim($token);
        if ($token === '') return null;

        return static::query()
            ->where('token_hash', hash('sha256', $token))
            ->whereNull('consumed_at')
            ->orderBy('id')
            ->first();
    }

    /**
     * **الإصدارُ الواحد** (يستدعيه B.3 دعوةً وB.4 توفيراً آليّاً — لا مسارَ ثانٍ):
     * يُنشئ صفَّ تفعيلٍ لمستخدمِ عميلٍ قائم + رمزاً سداسيّاً + رسالةَ صادرٍ تحمل
     * الرابطَ والرمز. يُعيد `[self, token]` حيث `token` هو الرمزُ الخامُّ الذي في
     * الرابط — **لا يُخزَّن صريحاً** (يُجزَّأ في العمود)، والمستدعي لا يحتاجه إلا
     * إن أراد بناءَ الرابط بنفسه. الرمزُ السداسيّ لا يخرج إلا في نصِّ الرسالة.
     */
    public static function issue(User $user): array
    {
        $token = Str::random(48);                   // الرمزُ الخامُّ في الرابط
        $otp = (string) random_int(100000, 999999); // رمزُ التحقّق السداسيّ
        $ttl = max(5, (int) setting('portal.activation_ttl_min', 60));

        $act = static::create([
            'user_id' => $user->id,
            'email' => hub_fit((string) $user->email, 190),
            'token_hash' => hash('sha256', $token),
            'otp_hash' => Hash::make($otp),
            'otp_expires_at' => now()->addMinutes($ttl),
            'attempts' => 0,
        ]);

        $app = (string) setting('app.name', config('app.name'));
        $url = route('activate.show', $token);
        // نصٌّ يحمل الرابطَ والرمزَ فقط — **لا كلمةَ سرٍّ** (لا وجودَ لها أصلاً بعدُ)
        OutboxMessage::create([
            'kind' => 'account_activation', 'channel' => 'mail', 'target' => $user->email,
            'text' => 'تفعيلُ حسابك في «' . Str::limit($app, 60) . '»: افتح الرابط ' . $url
                . ' وأدخِل رمزَ التحقّق ' . $otp . ' — صالحٌ ' . $ttl . ' دقيقة. ثم ضع كلمةَ سرّك بنفسك.'
                . ' لا تُشارك هذا الرمزَ مع أحد.',
            'state' => 'queued', 'created_at' => now(),
        ]);

        return [$act, $token];
    }

    /**
     * **توفيرُ حسابِ عميلٍ جديد + إصدارُ تفعيلِه** — النقطةُ الوحيدة التي تُنشئ
     * مستخدمَ عميلٍ بلا كلمةِ سرّ (C8): كلمةٌ عشوائيّةٌ لا يعرفها أحدٌ فلا يُدخَل
     * بها، و`password_changed_at` فارغةٌ (لم يضع العميلُ كلمتَه بعد) = «غيرُ
     * مُفعَّل». الأعمدةُ الحسّاسةُ مفروضةٌ هنا فلا يُمرِّرها المستدعي:
     * account_type=client دائماً، والكلمةُ عشوائيّة. يُعيد `[User, self]`.
     */
    public static function provisionClient(array $attrs): array
    {
        $user = User::create(array_merge(
            ['status' => 'نشط'],
            $attrs,
            [
                // حدُّ الحساب الصلب (SF-1) — لا يُستنتج ولا يُمرَّر
                'account_type' => 'client',
                // بلا كلمةِ سرٍّ صالحة: تجزيءُ عشوائيٍّ لا يعرفه أحد (يُحظر توليدُها/إرسالُها)
                'password' => Str::random(64),
                // «غيرُ مُفعَّل» — يُملأ لحظةَ يضع العميلُ كلمتَه في خطوةِ الوضع
                'password_changed_at' => null,
            ]
        ));

        [$act] = static::issue($user);

        return [$user, $act];
    }
}
