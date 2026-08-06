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

/** قيود اليومية */
class JournalEntry extends Model
{
    use HasFactory, HasUuid, SoftDeletes, Auditable, HasVersions, Searchable;

    protected $table = 'journal_entries';
    public const MODULE = 'entries';
    public const DISPLAY = 'doc_no';

    protected $guarded = ['id', 'version', 'created_by'];

    protected $casts = [
        'date' => 'date',
        'odoo_id' => 'decimal:3',
        'custom' => 'array',
        'meta' => 'array',
        'archived' => 'boolean',
    ];

    /**
     * مولِّدٌ داخليّ موثوق يبني قيداً مرحَّلاً ثمّ سطورَه (قيدُ الدفعة الآليّ في
     * `FinController::pay` وقيدُ الرواتب) — يرفع الرايةَ حول كتلته فقط، على نمط
     * `StockMove::$posting`. لا يرفعها مسارُ مستخدمٍ أبداً.
     */
    public static bool $posting = false;

    /**
     * القيد المُرحَّل مقفل: يُعكس بقيدٍ جديد ولا يُعدَّل ولا يُحذف — على كل المنافذ.
     *
     * وحارسُ **الدخول** إلى «مرحّل» (v2.314): الحارسُ القديم يفحص
     * `getOriginal('state')` على `updating/deleting`، فيمنع الخروجَ من «مرحّل»
     * لا الدخولَ إليه. وحقلُ `state` قائمةٌ منسدلة في نموذج الوحدة العام، فقيدٌ
     * غيرُ موزون كان يُرحَّل بـ`PUT /m/entries/{id}` أو يُنشأ «مرحّلاً» بلا سطرٍ
     * واحد — ثمّ **يُقفل أبداً**: لا تعديلَ ولا حذفَ ولا إصلاحَ في التطبيق كلّه،
     * ودفترٌ مختلٌّ دائم. بينما `EntryController::post` يرفض بـ422 لعدم التوازن.
     * الآن الشرطُ في النموذج نفسه فيسدّ الأبوابَ الخمسة دفعةً واحدة (النموذج
     * العام، والإنشاء، وسحبُ الكانبان، وAPI، وأيُّ منفذٍ لاحق).
     */
    protected static function booted(): void
    {
        $guard = function (self $m) {
            if ($m->getOriginal('state') === 'مرحّل') {
                throw \Illuminate\Validation\ValidationException::withMessages(
                    ['state' => 'القيد المُرحَّل مقفل — أنشئ قيد عكسٍ بدلاً من تعديله أو حذفه']);
            }
        };
        static::updating($guard);
        static::deleting($guard);

        static::saving(function (self $m) {
            if (static::$posting) return;                      // المولّد الداخلي يبني سطورَه بعدُ
            if ($m->state !== 'مرحّل' || $m->getOriginal('state') === 'مرحّل') return;

            $sum = \App\Models\JournalLine::where('entry_id', $m->getKey())
                ->selectRaw('COALESCE(SUM(debit),0) d, COALESCE(SUM(credit),0) c')->first();
            $debit = round((float) ($sum->d ?? 0), 3);
            $credit = round((float) ($sum->c ?? 0), 3);

            if ($debit <= 0 || $debit !== $credit) {
                throw \Illuminate\Validation\ValidationException::withMessages(['state' =>
                    'لا يُرحَّل قيدٌ لا يوازن: مدين ' . number_format($debit, 3)
                    . ' ≠ دائن ' . number_format($credit, 3) . ' — أضف سطورَه أولاً']);
            }
        });
    }

    public function lines()
    {
        return $this->hasMany(\App\Models\JournalLine::class, 'entry_id');
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(\App\Models\Project::class, 'project_id');
    }

    public function fin(): BelongsTo
    {
        return $this->belongsTo(\App\Models\FinDocument::class, 'fin_id');
    }
}
