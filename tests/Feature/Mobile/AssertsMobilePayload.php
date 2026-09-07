<?php

namespace Tests\Feature\Mobile;

/**
 * مساعِداتُ فحصٍ مشتركةٌ لحمولات الطور C (السياق/الإقلاع/المخطّط/الإعدادات).
 *
 * تمشي على شجرة JSON كاملةً — فبرهانُ «لا سرَّ ولا تسريبَ بنيةٍ» يُفحَص على
 * **كل** عقدةٍ لا على الطبقة العليا وحدها (نظيرُ حذرِ CLAUDE.md من القرعة: الفحصُ
 * يمرّ على الكلّ). لا تلمس منطقَ التطبيق — تفحص العقدَ كما يراه العميلُ الأصيل.
 */
trait AssertsMobilePayload
{
    /** مفاتيحُ لا يجوز ظهورُها أبداً في حمولةِ قراءةٍ للجوال — أسرارٌ/رموزٌ حقيقيّة (مطابقةٌ حرفيّةٌ لا جزئيّة كي لا يُخطئ `can_secrets`) */
    protected function forbiddenSecretKeys(): array
    {
        return [
            'token', 'access_token', 'refresh_token', 'access_hash', 'refresh_hash',
            'password', 'password_hash', 'secret', 'api_key', 'private_key',
            'totp_secret', 'totp_secret_cipher', 'client_secret', 'credential',
        ];
    }

    /** مفاتيحُ لا يجوز أن يُسرّبها مخطّطُ الجوال — بنيةٌ فيزيائيّة (INVENTORY §7c: تسريبُ V1Controller.php:33) */
    protected function forbiddenSchemaKeys(): array
    {
        return ['table', 'col'];
    }

    /** كلُّ المفاتيح الترابطيّة الظاهرة في الشجرة (تعاوديّاً) */
    protected function allKeysDeep($node, array &$acc = []): array
    {
        if (is_array($node)) {
            $isList = array_is_list($node);
            foreach ($node as $k => $v) {
                if (! $isList) $acc[] = (string) $k;
                $this->allKeysDeep($v, $acc);
            }
        }

        return $acc;
    }

    /** كلُّ قيمِ العُقد (القِيَم الطرفيّة) نصّاً — لفحصِ ألّا يظهر رمزٌ صريحٌ */
    protected function allScalarsDeep($node, array &$acc = []): array
    {
        if (is_array($node)) {
            foreach ($node as $v) $this->allScalarsDeep($v, $acc);
        } elseif ($node !== null) {
            $acc[] = is_bool($node) ? ($node ? 'true' : 'false') : (string) $node;
        }

        return $acc;
    }

    /** كلُّ القيَم المرتبطةِ بمفتاحٍ بعينِه أينما ورد (تعاوديّاً) — لجمع `key` الحقول مثلاً */
    protected function valuesForKeyDeep($node, string $key, array &$acc = []): array
    {
        if (is_array($node)) {
            $isList = array_is_list($node);
            foreach ($node as $k => $v) {
                if (! $isList && (string) $k === $key) $acc[] = $v;
                $this->valuesForKeyDeep($v, $key, $acc);
            }
        }

        return $acc;
    }

    /** يؤكّد ألّا يظهر أيُّ مفتاحٍ من `$forbidden` في أيّ مكانٍ من الشجرة */
    protected function assertNoKeysDeep(array $forbidden, $payload, string $why): void
    {
        $keys = $this->allKeysDeep($payload);
        foreach ($forbidden as $bad) {
            $this->assertNotContains($bad, $keys, "{$why} — المفتاحُ «{$bad}» ظهر في الحمولة");
        }
    }
}
