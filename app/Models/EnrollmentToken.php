<?php

namespace App\Models;

use App\Traits\HasUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * **رمزُ تسجيلِ نقطةٍ طرفية** (Work OS · الطور J · WP-J.1 · §43).
 *
 * انضباطُ `ApiToken` حرفياً: sha256 في القاعدة، والنصُّ الصريح يُعرَض **مرةً
 * واحدةً** لحظةَ السكّ ثم يستحيل استرجاعُه. لمرّةٍ واحدة (`consume` ذرّيّ)،
 * قصيرُ المهلة (`endpoint.enroll_ttl_min`)، مُسنَدٌ لشركةٍ (وموظفٍ اختياراً)
 * لحظةَ السكّ — فالجهازُ يرث إسنادَه من الرمز لا من حمولته.
 */
class EnrollmentToken extends Model
{
    use HasUuid;

    protected $table = 'enrollment_tokens';
    protected $guarded = ['id'];

    protected $casts = [
        'consumed_at' => 'datetime',
        'expires_at' => 'datetime',
    ];

    /**
     * سكُّ رمزٍ جديد — يُعيد `[الموديل, النصَّ الصريح]`؛ النصُّ لا يُخزَّن ولا
     * يُدوَّن، ويُعرَض لمن سكّه مرةً واحدة. المهلةُ من `endpoint.enroll_ttl_min`
     * (افتراضاً ١٥ دقيقة، بأرضيةٍ صلبة ٥ — نمطُ `AccountActivation::issue`).
     */
    public static function mint(string $companyId, ?string $employeeId, string $mintedBy): array
    {
        $plain = 'enr_' . Str::random(40);
        $ttl = max(5, (int) setting('endpoint.enroll_ttl_min', 15));

        $t = static::create([
            'token_hash' => hash('sha256', $plain),
            'company_id' => $companyId,
            'employee_id' => $employeeId,
            'expires_at' => now()->addMinutes($ttl),
            'minted_by' => $mintedBy,
        ]);

        return [$t, $plain];
    }

    /** الرمزُ الحيُّ المطابق لنصٍّ صريح — أو null (مجهولٌ؛ الانتهاءُ يُفحَص عند المستهلِك) */
    public static function findByPlain(string $plain): ?self
    {
        return static::where('token_hash', hash('sha256', $plain))->first();
    }

    /**
     * ادّعاءُ الاستهلاك **ذرّياً** (نمطُ claim في outbox): UPDATE مشروطٌ بأن
     * `consumed_at` ما زال فارغاً — طلبان متزامنان بالرمز نفسِه لا يسجّلان
     * جهازين: واحدٌ يفوز والثاني يُرَدّ 409.
     */
    public function consume(): bool
    {
        $claimed = static::whereKey($this->id)->whereNull('consumed_at')
            ->update(['consumed_at' => now()]);

        return $claimed === 1;
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function minter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'minted_by');
    }
}
