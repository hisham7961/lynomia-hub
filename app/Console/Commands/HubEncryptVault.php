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
        $this->info(($this->option('dry') ? 'سيُشفَّر: ' : 'شُفِّر: ') . $n . ' سجل');

        return self::SUCCESS;
    }
}
