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
                // فلا تمنع إجازةً حقيقية ولا تُمنع بها. وصفٌّ قائمٌ بـ date_to فارغ
                // (أُدرج قبل حارس «اليوم الواحد») نهايتُه الفعلية = date_from عبر
                // COALESCE، فلا يفلت من الحجز بمقارنة NULL الساقطة.
                if ($m->emp_id && $m->isAbsence()) {
                    $overlap = static::query()->whereNull('deleted_at')
                        ->where('emp_id', $m->emp_id)
                        ->when($m->id, fn ($q) => $q->where('id', '!=', $m->id))
                        ->whereIn('type', static::deductTypes())
                        ->whereNotIn('status', ['مرفوض', 'ملغى'])
                        ->whereRaw('DATE(COALESCE(date_from, date_to)) <= ?', [$to->toDateString()])
                        ->whereRaw('DATE(COALESCE(date_to, date_from)) >= ?', [$from->toDateString()])
                        ->exists();
                    if ($overlap) {
                        throw \Illuminate\Validation\ValidationException::withMessages(
                            ['from' => 'تتداخل مع إجازة قائمة لنفس الموظف — راجع طلباته أولاً']);
                    }
                }

                // سقف الرصيد: اعتمادُ غيابٍ لم يُخصم بعد لا يتجاوز رصيد الموظف المتاح —
                // كان يهبط تحت الصفر بصمت. (يُفحَص عند الاعتماد الأول فقط، والتعديل
                // بعده لا يُعاد فحصه كي لا يُحظر تحرير طلبٍ خُصم أصلاً.)
                $meta = (array) ($m->meta ?? []);
                if ($m->emp_id && $m->isAbsence() && (string) $m->status === 'معتمد'
                    && empty($meta['balance_deducted'])) {
                    $need = (float) ($m->days ?? 0);
                    $bal = (float) (\App\Models\Employee::whereKey($m->emp_id)->value('leave_bal') ?? 0);
                    if ($need > 0 && $need > $bal) {
                        throw \Illuminate\Validation\ValidationException::withMessages(
                            ['days' => 'الرصيد لا يكفي: المطلوب ' . rtrim(rtrim(number_format($need, 2), '0'), '.')
                                . ' يوماً والمتاح ' . rtrim(rtrim(number_format($bal, 2), '0'), '.')
                                . ' — عدّل الأيام أو رصيد الموظف']);
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
                    self::applyBalance($emp, -$days);
                    $m->meta = $meta + ['balance_deducted' => $days];
                    $m->saveQuietly();
                }
            } elseif (! $approved && ! empty($meta['balance_deducted'])) {
                self::applyBalance($emp, (float) $meta['balance_deducted']);
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
                self::applyBalance($emp, (float) $meta['balance_deducted']);   // العلامة تبقى: إن استُعيد السجل أُعيد الخصم
            }
        });

        // الاستعادة من السلة تُعيد الخصم الذي أعاده الحذف
        static::restored(function (self $m) {
            if (! $m->emp_id || ! ($emp = \App\Models\Employee::find($m->emp_id))) return;
            $meta = (array) ($m->meta ?? []);
            if ((string) $m->status === 'معتمد' && ! empty($meta['balance_deducted'])) {
                self::applyBalance($emp, -(float) $meta['balance_deducted']);
            }
        });
    }

    /**
     * تطبيق دلتا على رصيد إجازة الموظف. اعتمادان متزامنان لنفس الموظف كانا
     * يقرآن الرصيد نفسه ثم يكتبان فيضيع أحد الخصمين (اقرأ-ثم-اكتب بلا قفل).
     * القفل الصفّيّ وإعادة القراءة داخل معاملة يسلسلانهما: كلُّ خصمٍ يُبنى على
     * الرصيد بعد سابقه.
     */
    protected static function applyBalance(\App\Models\Employee $emp, float $delta): void
    {
        \Illuminate\Support\Facades\DB::transaction(function () use ($emp, $delta) {
            $fresh = \App\Models\Employee::whereKey($emp->id)->lockForUpdate()->first();
            if (! $fresh) return;
            $current = (float) ($fresh->leave_bal ?? 0);

            // سقفُ الرصيد يُفحَص في saving بقراءةٍ **غير مقفولة**، فاعتمادان متزامنان
            // لنفس الموظف يمرّان معاً على رصيدٍ قديمٍ كافٍ ثم يخصمان تحت القفل فيهبط
            // الرصيد تحت الصفر. إعادةُ فحص الكفاية تحت القفل تمنع السالب — كنمط
            // حارس المخزون. الاستعادةُ (دلتا موجبة) لا تُحجب أبداً.
            if ($delta < 0 && $current + $delta < 0) {
                throw \Illuminate\Validation\ValidationException::withMessages([
                    'days' => 'الرصيد لا يكفي لهذا الخصم — المتاح '
                        . rtrim(rtrim(number_format($current, 2), '0'), '.')
                        . ' يوماً (قد يكون خُصم لطلبٍ آخر متزامن) — عدّل الأيام أو رصيد الموظف',
                ]);
            }

            $fresh->leave_bal = $current + $delta;
            $fresh->saveQuietly();
            $emp->leave_bal = $fresh->leave_bal;   // اعكس القيمة في النسخة المستدعية
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
