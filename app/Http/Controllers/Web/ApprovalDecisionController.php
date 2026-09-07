<?php

namespace App\Http\Controllers\Web;

use App\Support\ApprovalResult;
use App\Support\ApprovalService;
use Illuminate\Http\Request;

/**
 * حسم الموافقات المُلزِمة: الاعتماد يُنفّذ العملية المؤجلة (تعديل بالحمولة المخزنة أو حذف)،
 * والرفض يوقفها — وفي الحالين يُشعَر طالب التنفيذ.
 *
 * **المنطقُ في `App\Support\ApprovalService` (Mobile Readiness · الطور D · Critic F2):**
 * المعاملةُ وقفلُ الصفّ وحارسُ التقادم وإعادةُ الحمولة وإشعارُ الطالب صارت في خدمةٍ
 * مشتركةٍ يستدعيها الويبُ والجوالُ معاً — فلا يُنسخ منطقُ الأعمال ولا يُستدعى معالجُ
 * الويب (الذي يعيد 302) من الجوال. وهذا المتحكّم يترجم نتيجةَ الخدمة (`ApprovalResult`)
 * إلى إعادةِ التوجيه ورسالةِ الجلسة **كما كان تماماً** (سلوكُ الويب حرفاً بحرف).
 *
 * يبقى وارثاً `ModuleController` (لا يزال fill/queueApproval يسكنان فيه لباقي المسارات).
 */
class ApprovalDecisionController extends ModuleController
{
    public function approve(string $id)
    {
        return $this->renderDecision(ApprovalService::decide($id, 'approve', auth()->user()));
    }

    public function reject(Request $r, string $id)
    {
        return $this->renderDecision(
            ApprovalService::decide($id, 'reject', auth()->user(), ['note' => $r->input('note')])
        );
    }

    /**
     * ترجمةُ نتيجةِ الخدمة إلى عرضِ الويب — **حرفاً بحرف** كما كان المعالجُ الأصليّ:
     *  · تقادمٌ ⇒ `back()->withErrors` (رسالةُ جلسة، لم يُنفَّذ شيء).
     *  · اعتمادٌ/رفضٌ ⇒ `back()->with('ok', …)` برسالته.
     *  · ممنوعٌ/محسومٌ سلفاً/غيرُ قابلٍ للتنفيذ ⇒ `abort($status, $message)` بالرمز نفسِه.
     */
    protected function renderDecision(ApprovalResult $res)
    {
        return match ($res->code) {
            ApprovalService::VERSION_CONFLICT => back()->withErrors(['approval' => $res->message]),
            ApprovalService::APPROVED,
            ApprovalService::REJECTED         => back()->with('ok', $res->message),
            default                           => abort($res->status, $res->message),
        };
    }
}
