<?php

namespace App\Support\Discovery;

/**
 * عقدُ مزوّد بيانات المنتجات — مزوّدٌ جديد غداً صنفٌ يطبّق هذا لا إعادةُ
 * كتابةٍ للمحرك. `lookup` تعيد حقولاً جزئيةً مُطبَّعةً أو null حين لا يعرف.
 */
interface Provider
{
    /** مفتاحُ المزوّد كما يُذكر في إعداد identity.providers */
    public function key(): string;

    /** اسمُه المعروض */
    public function label(): string;

    /** أيتكفّل بهذا الباركود أصلاً؟ (مزوّد الكتب لا يُسأل عن ثلاجة) */
    public function handles(string $gtin): bool;

    /** رابطُ الطلب — يُمرَّر على حارس SSRF قبل أي نداء */
    public function url(string $gtin): string;

    /**
     * تحويلُ ردّ المزوّد الخام إلى الحقول الموحّدة:
     * name / brand / manufacturer / model / category / origin / image
     * تعيد null حين لا يعرف المزوّدُ الباركود.
     */
    public function parse(array $json): ?array;
}
