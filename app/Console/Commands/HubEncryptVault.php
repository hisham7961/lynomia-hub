<?php

namespace App\Console\Commands;

use App\Models\VaultSecret;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

/** ترحيل لمرة واحدة: تشفير أسرار الخزنة القديمة المخزَّنة نصاً صريحاً */
class HubEncryptVault extends Command
{
    protected $signature = 'hub:encrypt-vault {--dry : عرض العدد دون كتابة}';
    protected $description = 'تشفير أسرار الخزنة غير المشفّرة (بيانات قديمة)';

    public function handle(): int
    {
        $n = 0;
        foreach (DB::table('vault_secrets')->whereNotNull('secret_cipher')->where('secret_cipher', '!=', '')->get(['id', 'secret_cipher']) as $row) {
            try { Crypt::decryptString($row->secret_cipher); continue; }   // مشفّر أصلاً
            catch (\Throwable $e) {}
            $n++;
            if (! $this->option('dry')) {
                DB::table('vault_secrets')->where('id', $row->id)
                    ->update(['secret_cipher' => Crypt::encryptString($row->secret_cipher)]);
            }
        }

        $v = $this->option('dry') ? 0 : $this->scrubSnapshots();

        $this->info(($this->option('dry') ? 'سيُشفَّر: ' : 'شُفِّر: ') . $n . ' سجل'
            . ($v ? " · مُسح النصُّ الصريح من {$v} لقطة إصدار" : ''));

        return self::SUCCESS;
    }

    /**
     * **والنصُّ الصريح في اللقطات**: `record_versions` تحفظ لقطةَ كل تعديل
     * كاملةً — بما فيها `secret_cipher` قبل التعمية. فتشفيرُ الجدول وحده يترك
     * السرَّ مكشوفاً في تاريخِه، وفي **كل نسخةٍ احتياطية** تُؤخذ بعده: أمانٌ
     * مُعلَنٌ لا يقع. تُنزَع القيمةُ من اللقطة ويُترك أثرُ حجبٍ صريح
     * (‏`restoreVersion` تُبقي القيمةَ الحالية عند غياب المفتاح فلا يضيع شيء).
     */
    protected function scrubSnapshots(): int
    {
        if (! \Illuminate\Support\Facades\Schema::hasTable('record_versions')) return 0;

        $n = 0;
        DB::transaction(function () use (&$n) {
            DB::table('record_versions')->where('module', 'vault')->orderBy('id')
                ->chunkById(200, function ($rows) use (&$n) {
                    foreach ($rows as $v) {
                        $snap = json_decode((string) $v->snapshot, true);
                        if (! is_array($snap) || ! array_key_exists('secret_cipher', $snap)) continue;
                        if (($snap['secret_cipher'] ?? null) === '••• محجوب •••') continue;
                        $snap['secret_cipher'] = '••• محجوب •••';
                        DB::table('record_versions')->where('id', $v->id)
                            ->update(['snapshot' => json_encode($snap, JSON_UNESCAPED_UNICODE)]);
                        $n++;
                    }
                });
        });

        return $n;
    }
}
