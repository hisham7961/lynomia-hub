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

/** الإجازات والطلبات */
class LeaveRequest extends Model
{
    use HasFactory, HasUuid, SoftDeletes, Auditable, HasVersions, Searchable;

    protected $table = 'leave_requests';
    public const MODULE = 'leaves';
    public const DISPLAY = 'type';

    protected $guarded = ['id', 'version', 'created_by'];

    protected $casts = [
        'date_from' => 'date',
        'date_to' => 'date',
        'days' => 'decimal:3',
        'custom' => 'array',
        'meta' => 'array',
        'archived' => 'boolean',
    ];

    /**
     * محرك الإجازات — كان الرصيد رقماً ساكناً والحالات نصوصاً بلا أثر:
     *  - التواريخ تُفحص (النهاية قبل البداية تُرفض) والتداخل مع إجازة قائمة يُرفض.
     *  - الأيام تُشتق آلياً من أيام العمل (عطلة الأسبوع من الإعدادات) إن تُركت فارغة.
     *  - الاعتماد يخصم من رصيد الموظف، والإلغاء/الرفض بعده يعيده — idempotent عبر meta.
     */
    /** أنواع الطلبات التي تُعدّ غياباً — تُخصم من الرصيد وتحجز التواريخ */
    public static function deductTypes(): array
    {
        return (array) config('hub.leave.deduct_types', ['إجازة سنوية', 'إجازة مرضية', 'إجازة طارئة']);
    }

    /** أهذا الطلب غيابٌ فعليّ؟ ما دونه (إذن/عمل عن بعد/سلفة/شهادة) لا يمسّ الرصيد */
    public function isAbsence(): bool
    {
        return in_array((string) $this->type, static::deductTypes(), true);
    }

    protected static function booted(): void
    {
        static::saving(function (self $m) {
            // يومٌ واحد بلا «إلى»: النهاية = البداية — وإلا فلت من الاشتقاق وحارس التداخل كليّاً
            if ($m->date_from && blank($m->date_to)) {
                $m->date_to = $m->date_from;
            }
            if ($m->date_from && $m->date_to) {
                $from = \Illuminate\Support\Carbon::parse($m->date_from);
                $to = \Illuminate\Support\Carbon::parse($m->date_to);
                if ($to->lt($from)) {
                    throw \Illuminate\Validation\ValidationException::withMessages(
                        ['to' => 'نهاية الإجازة قبل بدايتها — راجع التاريخين']);
                }
                if (blank($m->days) || (float) $m->days <= 0) {
                    $m->days = hub_workdays($from, $to);
                }
                // التداخل يُفحص بين الغيابات فقط: «شهادة راتب» أو «سلفة» لا تحجز يوماً،
                // فلا تمنع إجازةً حقيقية ولا تُمنع بها.
                if ($m->emp_id && $m->isAbsence()) {
                    $overlap = static::query()->whereNull('deleted_at')
                        ->where('emp_id', $m->emp_id)
                        ->when($m->id, fn ($q) => $q->where('id', '!=', $m->id))
                        ->whereIn('type', static::deductTypes())
                        ->whereNotIn('status', ['مرفوض', 'ملغى'])
                        ->whereDate('date_from', '<=', $to->toDateString())
                        ->whereDate('date_to', '>=', $from->toDateString())
                        ->exists();
                    if ($overlap) {
                        throw \Illuminate\Validation\ValidationException::withMessages(
                            ['from' => 'تتداخل مع إجازة قائمة لنفس الموظف — راجع طلباته أولاً']);
                    }
                }
            }
        });

        // مزامنة الرصيد: الخصم عند الاعتماد، والاستعادة عند الإلغاء/الرفض — idempotent
        // عبر meta['balance_deducted']، ومحصورٌ بالغيابات فقط.
        $syncBalance = function (self $m) {
            if (! $m->emp_id || ! ($emp = \App\Models\Employee::find($m->emp_id))) return;
            $meta = (array) ($m->meta ?? []);
            $approved = (string) $m->status === 'معتمد' && $m->isAbsence();

            if ($approved && empty($meta['balance_deducted'])) {
                $days = (float) ($m->days ?? 0);
                if ($days > 0) {
                    $emp->leave_bal = (float) ($emp->leave_bal ?? 0) - $days;
                    $emp->saveQuietly();
                    $m->meta = $meta + ['balance_deducted' => $days];
                    $m->saveQuietly();
                }
            } elseif (! $approved && ! empty($meta['balance_deducted'])) {
                $emp->leave_bal = (float) ($emp->leave_bal ?? 0) + (float) $meta['balance_deducted'];
                $emp->saveQuietly();
                unset($meta['balance_deducted']);
                $m->meta = $meta ?: null;
                $m->saveQuietly();
            }
        };
        static::created($syncBalance);
        static::updated($syncBalance);

        // الحذف (الناعم لا يطلق updated) يعيد أي خصمٍ قائم — وإلا بقي يتيماً بلا سجل،
        // وقُبلت إجازةٌ جديدة فوق التواريخ نفسها فخُصم الغياب الواحد مرتين.
        static::deleted(function (self $m) {
            if (! $m->emp_id || ! ($emp = \App\Models\Employee::find($m->emp_id))) return;
            $meta = (array) ($m->meta ?? []);
            if (! empty($meta['balance_deducted'])) {
                $emp->leave_bal = (float) ($emp->leave_bal ?? 0) + (float) $meta['balance_deducted'];
                $emp->saveQuietly();   // العلامة تبقى: إن استُعيد السجل أُعيد الخصم
            }
        });

        // الاستعادة من السلة تُعيد الخصم الذي أعاده الحذف
        static::restored(function (self $m) {
            if (! $m->emp_id || ! ($emp = \App\Models\Employee::find($m->emp_id))) return;
            $meta = (array) ($m->meta ?? []);
            if ((string) $m->status === 'معتمد' && ! empty($meta['balance_deducted'])) {
                $emp->leave_bal = (float) ($emp->leave_bal ?? 0) - (float) $meta['balance_deducted'];
                $emp->saveQuietly();
            }
        });
    }

    public function emp(): BelongsTo
    {
        return $this->belongsTo(\App\Models\Employee::class, 'emp_id');
    }

    public function mgr(): BelongsTo
    {
        return $this->belongsTo(\App\Models\User::class, 'mgr_id');
    }
}
