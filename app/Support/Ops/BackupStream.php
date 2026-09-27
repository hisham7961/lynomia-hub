<?php

namespace App\Support\Ops;

use Illuminate\Support\Facades\Crypt;

/**
 * **كاتبُ النسخة المتدفّق** (TECH_DEBT #7 · F-13).
 *
 * كان `hub:backup` يبني المنشأةَ كلَّها مصفوفةً في الذاكرة ثم يرمّزها سلسلةً
 * واحدة ثم (إن شُفّرت) يشفّرها سلسلةً ثالثة — فذروةُ الذاكرة أضعافُ حجم القاعدة،
 * ونسخةُ منشأةٍ نمت تسقط على `memory_limit` في منتصف الليل ولا نسخةَ بعدها.
 *
 * هنا تُكتب النسخةُ **قطعةً قطعة** إلى الملفّ المؤقّت، والذروةُ ثابتةٌ بحجم القطعة
 * لا بحجم القاعدة — **بالصيغة نفسِها بايتاً ببايت**:
 *
 *   · **صريحة**: الأجزاءُ تُلصَق كما هي — `{` ثمّ المفاتيحُ بترتيبها ثمّ `}`.
 *   · **مشفّرة**: تنتج **حمولةَ `Crypt::encryptString` نفسَها** (AES-CBC + HMAC-SHA256
 *     ثمّ base64 لغلاف JSON) فيفكّها `HubBackup::readFile` و`hub:import` دون أيِّ تغيير:
 *     الـCBC يُسلسَل كتلةً كتلة (متّجهُ القطعة التالية آخرُ كتلةٍ مشفّرة)، والـHMAC
 *     يُغذّى تدريجاً، والـbase64 على حدودٍ من ثلاث بايتات فلا حشوَ في الوسط.
 *     والنصُّ الصريح **لا يلمس القرصَ أبداً** — ولذا لا ملفَّ وسيطاً صريحاً.
 *   · شيفرةٌ أخرى (AEAD كـGCM لا تُسلسَل قطعاً): تُجمَع السلسلةُ ثم تُشفَّر بـ`Crypt`
 *     كما كانت — أقلُّ توفيراً (بلا المصفوفة فقط) لكن الصيغةُ محفوظة.
 *
 * كلُّ كتابةٍ تُفحص طولاً — كتابةٌ ناقصةٌ (قرصٌ امتلأ) فشلٌ لا نسخةٌ مبتورة.
 */
final class BackupStream
{
    /** رمزُ استثناءِ «كتابةٌ ناقصة» — يُفرّقه المستدعي عن استعلامٍ سقط */
    public const WRITE_FAILED = 7013;

    /** حجمُ قطعة التشفير — مضاعفُ ٤٨ (كتلةُ AES ١٦ × حدُّ base64 ٣) */
    private const CHUNK = 48 * 1024;

    /** @var resource|null */
    private $fh;

    private int $bytes = 0;

    private bool $encrypt;

    /** المسارُ المتدفّق (CBC) أم المُجمَّع (شيفرةٌ أخرى) */
    private bool $streamCipher = false;

    private string $plain = '';     // نصٌّ صريحٌ ينتظر اكتمالَ قطعة (أو السلسلةُ كلُّها في المُجمَّع)

    private string $outer = '';     // غلافُ JSON ينتظر حدَّ ثلاث بايتات قبل base64

    private string $cipher = '';

    private string $key = '';

    private string $iv = '';        // متّجهُ القطعة التالية (آخرُ كتلةٍ مشفّرة)

    /** @var \HashContext|null */
    private $mac = null;

    private function __construct(private string $path)
    {
    }

    /** يفتح الملفَّ المؤقّت للكتابة — أو null إن تعذّر (مسارٌ محجوبٌ أو قرصٌ للقراءة) */
    public static function open(string $path, bool $encrypt): ?self
    {
        $s = new self($path);
        $fh = @fopen($path, 'wb');
        if ($fh === false) return null;
        $s->fh = $fh;
        $s->encrypt = $encrypt;

        if ($encrypt) {
            $cipher = strtolower((string) config('app.cipher', 'aes-256-cbc'));
            if (in_array($cipher, ['aes-128-cbc', 'aes-256-cbc'], true)) {
                $s->streamCipher = true;
                $s->cipher = $cipher;
                $s->key = app('encrypter')->getKey();
                $iv = random_bytes(openssl_cipher_iv_length($cipher));
                $s->iv = $iv;
                $ivB64 = base64_encode($iv);
                $s->mac = hash_init('sha256', HASH_HMAC, $s->key);
                hash_update($s->mac, $ivB64);
                $s->outer('{"iv":"' . $ivB64 . '","value":"');
            }
        }

        return $s;
    }

    /** يُلحق جزءاً من نصّ النسخة */
    public function write(string $part): bool
    {
        if (! $this->encrypt) return $this->raw($part);

        $this->plain .= $part;
        if (! $this->streamCipher) return true;

        $full = intdiv(strlen($this->plain), self::CHUNK) * self::CHUNK;
        if ($full === 0) return true;
        $ok = $this->encryptBlocks(substr($this->plain, 0, $full), false);
        $this->plain = (string) substr($this->plain, $full);

        return $ok;
    }

    /** يُتمّ الملفّ ويغلقه — false إن فشلت كتابةٌ أو تشفير (والمستدعي يحذف الملفّ) */
    public function close(): bool
    {
        $ok = true;
        if ($this->encrypt && $this->streamCipher) {
            // القطعةُ الأخيرة بحشو PKCS#7 كما يحشو `openssl_encrypt` في `Crypt` تماماً
            $ok = $this->encryptBlocks($this->plain, true);
            $this->plain = '';
            if ($ok) {
                $mac = hash_final($this->mac);
                $ok = $this->outer('","mac":"' . $mac . '","tag":""}')
                   && $this->raw(base64_encode($this->outer));
                $this->outer = '';
            }
        } elseif ($this->encrypt) {
            try {
                $payload = Crypt::encryptString($this->plain);
            } catch (\Throwable $e) {
                $payload = null;
            }
            $this->plain = '';
            $ok = $payload !== null && $this->raw($payload);
        }

        $closed = @fclose($this->fh);
        $this->fh = null;

        return $ok && $closed !== false;
    }

    /** تخلٍّ عن ملفٍّ لم يكتمل — يُغلق ويُحذف فلا يبقى نصفُ نسخة */
    public function abort(): void
    {
        if (is_resource($this->fh)) @fclose($this->fh);
        $this->fh = null;
        $this->plain = $this->outer = '';
        @unlink($this->path);
    }

    /** ما كُتب على القرص فعلاً (بايت) */
    public function bytes(): int
    {
        return $this->bytes;
    }

    private function encryptBlocks(string $data, bool $final): bool
    {
        $opts = OPENSSL_RAW_DATA | ($final ? 0 : OPENSSL_ZERO_PADDING);
        $ct = openssl_encrypt($data, $this->cipher, $this->key, $opts, $this->iv);
        if ($ct === false) return false;
        if ($ct !== '') $this->iv = substr($ct, -16);
        // ما عدا الأخيرة مضاعفُ ٤٨ بايتاً فـbase64 أجزائها تتّصل بلا حشو
        $b64 = base64_encode($ct);
        hash_update($this->mac, $b64);

        return $this->outer($b64);
    }

    /** غلافُ JSON يُرمَّز base64 على حدود ثلاث بايتات ويُكتب */
    private function outer(string $s): bool
    {
        $this->outer .= $s;
        $n = intdiv(strlen($this->outer), 3) * 3;
        if ($n < self::CHUNK) return true;
        $ok = $this->raw(base64_encode(substr($this->outer, 0, $n)));
        $this->outer = (string) substr($this->outer, $n);

        return $ok;
    }

    private function raw(string $s): bool
    {
        if ($s === '') return true;
        $w = @fwrite($this->fh, $s);
        if ($w !== strlen($s)) return false;
        $this->bytes += $w;

        return true;
    }
}
