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

        if ($a = $this->countPlainInAudits()) {
            $this->warn("⚠ بقي نصٌّ صريحٌ في {$a} قيد تدقيق — ولا يُمحى: "
                . '`before`/`after` من أعمدة بصمة سلسلة التدقيق، فتعديلُهما يكسر السلسلةَ '
                . 'نفسَها. **العلاجُ تدويرُ تلك الأسرار** (تغييرُ قيمتها عند مصدرها) لا محوُ أثرها.');
        }

        return self::SUCCESS;
    }

    /**
     * عدُّ قيود التدقيق التي ما زالت تحمل سرّاً صريحاً — **بلاغٌ لا محو.**
     *
     * `before`/`after` من `AuditEntry::SEALED`، فأيُّ تعديلٍ عليهما يُبطل بصمةَ
     * القيد ويقطع السلسلة — أي يُتلف الضمانَ لإخفاء أثرِ خرقه، وهو أسوأُ من
     * الخرق. فالحدُّ هنا: يُقال للمشغّل ما وقع ويُوجَّه إلى العلاج الصحيح.
     * والمصدرُ نفسُه مسدود منذ v2.335: `Auditable::auditRedact` تُبدّل قيمةَ
     * عمودِ السرّ ببصمةٍ قبل الكتابة، فلا يدخل السجلَّ صريحٌ جديد.
     */
    protected function countPlainInAudits(): int
    {
        if (! \Illuminate\Support\Facades\Schema::hasTable('audits')) return 0;

        $n = 0;
        DB::table('audits')->where('module', 'vault')->orderBy('id')
            ->select(['id', 'before', 'after'])
            ->chunkById(500, function ($rows) use (&$n) {
                foreach ($rows as $r) {
                    foreach (['before', 'after'] as $side) {
                        $d = json_decode((string) ($r->{$side} ?? ''), true);
                        $val = is_array($d) ? (string) ($d['secret_cipher'] ?? '') : '';
                        if ($val === '' || str_starts_with($val, 'sha256:')) continue;
                        try { Crypt::decryptString($val); continue; } catch (\Throwable $e) {}
                        $n++;
                        break;   // القيدُ يُعدّ مرةً واحدة
                    }
                }
            });

        return $n;
    }

    /**
     * **والنصُّ الصريح في اللقطات**: `record_versions` تحفظ لقطةَ كل تعديل
     * كاملةً — بما فيها `secret_cipher` قبل التعمية. فتشفيرُ الجدول وحده يترك
     * السرَّ مكشوفاً في تاريخِه، وفي **كل نسخةٍ احتياطية** تُؤخذ بعده: أمانٌ
     * مُعلَنٌ لا يقع.
     *
     * وحدَّان يحكمان العلاج، وكلاهما تعلَّمناه من عيبٍ وقع هنا:
     *
     *  ١) **الصريحُ وحده يُمسح.** لقطةٌ تحمل نصّاً **مشفَّراً** ليست تسريباً،
     *     ومسحُها يُتلف تاريخاً سليماً بلا مقابل.
     *  ٢) **يُنزَع المفتاح ولا يُستبدَل بعلامة.** `HasVersions::restoreVersion`
     *     تفعل `fill($snapshot)` — فقيمةٌ نائبة تُكتب سرّاً حقيقيّاً عند
     *     الاستعادة، فيُدمَّر السرُّ بضغطةٍ واحدة. وغيابُ المفتاح يُبقي القيمةَ
     *     الحالية كما هي، وهو المطلوب بالضبط.
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

                        $val = (string) ($snap['secret_cipher'] ?? '');
                        if ($val === '') continue;
                        // مشفَّرٌ أصلاً: تاريخٌ سليم لا يُمَسّ
                        try { Crypt::decryptString($val); continue; } catch (\Throwable $e) {}

                        unset($snap['secret_cipher']);
                        DB::table('record_versions')->where('id', $v->id)
                            ->update(['snapshot' => json_encode($snap, JSON_UNESCAPED_UNICODE)]);
                        $n++;
                    }
                });
        });

        return $n;
    }
}
