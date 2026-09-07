<?php

namespace App\Support;

use App\Models\Approval;

/**
 * **نتيجةُ حسمِ موافقة** — كائنُ قيمةٍ يعيده `ApprovalService::decide` — Mobile
 * Readiness · الطور D · Critic F2.
 *
 * الحسمُ منطقٌ واحدٌ (معاملةٌ + قفلُ صفٍّ + حارسُ التقادم + إعادةُ الحمولة + إشعارُ
 * الطالب)؛ لكنّ **العرضَ** يختلف: الويبُ إعادةُ توجيهٍ برسالةِ جلسة، والجوالُ ردُّ
 * `Api::*`. فالخدمةُ تعيد هذا الكائنَ المحايد، وكلُّ سطحٍ يترجمه لعرضه — فلا يُستدعى
 * معالجُ الويب من الجوال (يعيد 302/HTML) ولا يُنسخ منطقُ الأعمال.
 */
final class ApprovalResult
{
    public function __construct(
        /** رمزُ النتيجة — أحدُ ثوابت ApprovalService (approved/rejected/version_conflict/forbidden/…) */
        public readonly string $code,
        /** رمزُ HTTP لحالات الخطأ (٤٠٣/٤٠٩/٤٢٢) — 200 للنجاح */
        public readonly int $status = 200,
        /** رسالةٌ عربيّةٌ جاهزةٌ للويب (الجوالُ يصوغ رسالتَه من `code`) */
        public readonly string $message = '',
        /** الطلبُ المحسوم (للنجاح) — أو null */
        public readonly ?Approval $approval = null,
        /** هل تغيّر السجلُّ فعلاً؟ (اعتمادٌ نفّذ تعديلاً/حذفاً فعليّاً — لا مطابقةً صامتة) */
        public readonly bool $did = false,
    ) {}

    /** نتيجةُ نجاحٍ (اعتماد/رفض) */
    public static function ok(string $code, Approval $ap, bool $did, string $message): self
    {
        return new self($code, 200, $message, $ap, $did);
    }

    /** نتيجةُ خطأٍ (ممنوع/محسومٌ سلفاً/تقادمٌ/غيرُ قابلٍ للتنفيذ) */
    public static function fail(string $code, int $status, string $message): self
    {
        return new self($code, $status, $message);
    }

    /** هل حُسم الطلبُ فعلاً (اعتماداً أو رفضاً)؟ — لا خطأٌ ولا تقادم */
    public function decided(): bool
    {
        return in_array($this->code, [ApprovalService::APPROVED, ApprovalService::REJECTED], true);
    }
}
