<?php

namespace App\Models;

use App\Traits\Auditable;
use App\Traits\HasUuid;
use App\Traits\HasVersions;
use App\Traits\Searchable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/** ملفات الموظفين */
class Employee extends Model
{
    use HasFactory, HasUuid, SoftDeletes, Auditable, HasVersions, Searchable;

    protected $table = 'employees';
    public const MODULE = 'hr';
    public const DISPLAY = 'name';

    protected $guarded = ['id', 'version', 'created_by'];

    protected $casts = [
        'hired' => 'date',
        'salary' => 'decimal:3',
        'allow' => 'decimal:3',
        'iqama_exp' => 'date',
        'pass_exp' => 'date',
        'leave_bal' => 'decimal:3',
        'asset_ids' => 'array',
        'end_date' => 'date',
        'custom' => 'array',
        'meta' => 'array',
        'archived' => 'boolean',
    ];

    /**
     * **الملفُّ والحساب شخصٌ واحد** — السكّة في App\Support\Staff، والربط هنا
     * لأنه يجب أن يقع من كل باب: النموذج العام، والاستيراد، والتعيين، والسكربت.
     * ما يُترك للمتحكّمات وحدها يُنسى في بابٍ منها.
     */
    protected static function booted(): void
    {
        // إضافةُ ملفٍّ لبريدٍ له حسابٌ حرّ تربطهما فوراً — لا إنشاء ولا سرقةَ مربوط
        static::created(fn (self $e) => \App\Support\Staff::linkByEmail($e));

        static::updated(function (self $e) {
            $was = (string) $e->getOriginal('status');
            $now = (string) $e->status;
            if ($was === $now) return;

            $openBefore = in_array($was, \App\Support\Staff::OPEN, true);
            $openNow = in_array($now, \App\Support\Staff::OPEN, true);

            // انتهت خدمتُه أو أُوقف: يُغلق البابُ فوراً، لا حين يتذكّر أحد
            if ($openBefore && ! $openNow) \App\Support\Staff::closeAccount($e, 'حالة الملف: ' . $now);
            // وعاد: يُبلَّغ من يدير المستخدمين — إعادةُ الوصول قرارٌ لا أثرٌ جانبي
            if (! $openBefore && $openNow) \App\Support\Staff::announceReturn($e);
        });

        static::deleted(fn (self $e) => \App\Support\Staff::closeAccount($e, 'حُذف ملفه الوظيفي'));
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(\App\Models\User::class, 'user_id');
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(\App\Models\Company::class, 'company_id');
    }

    public function manager(): BelongsTo
    {
        return $this->belongsTo(\App\Models\User::class, 'manager_id');
    }
}
