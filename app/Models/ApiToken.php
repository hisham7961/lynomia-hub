<?php

namespace App\Models;

use App\Traits\HasUuid;
use Illuminate\Database\Eloquent\Model;

/** مفتاح API لمستخدم */
class ApiToken extends Model
{
    use HasUuid;

    protected $table = 'api_tokens';
    protected $guarded = ['id'];
    public $timestamps = false;

    protected $casts = [
        'expires_at'   => 'datetime',
        'last_used_at' => 'datetime',
        'created_at'   => 'datetime',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    /** هل يسمح المفتاح بعملية (v|a|e|d) على وحدة؟ — null أو «*» = كامل صلاحيات المستخدم */
    public function allows(string $module, string $op): bool
    {
        $s = trim((string) $this->scopes);
        if ($s === '' || $s === '*') return true;

        foreach (preg_split('/[،,\s]+/u', $s, -1, PREG_SPLIT_NO_EMPTY) as $part) {
            [$mod, $ops] = array_pad(explode(':', $part, 2), 2, '');
            if ($mod !== $module && $mod !== '*') continue;
            if ($ops === '' || str_contains($ops, $op)) return true;
        }

        return false;
    }

    /** هل عنوان الطالب ضمن القائمة المسموحة؟ — فارغة = من أي مكان. تدعم IP مفرداً أو CIDR */
    public function ipAllowed(?string $ip): bool
    {
        $list = trim((string) $this->allowed_ips);
        if ($list === '' || ! $ip) return $list === '';

        foreach (preg_split('/[،,\s]+/u', $list, -1, PREG_SPLIT_NO_EMPTY) as $entry) {
            if (! str_contains($entry, '/')) {
                if (hash_equals($entry, $ip)) return true;
                continue;
            }
            [$net, $bits] = explode('/', $entry, 2);
            $bits = (int) $bits;
            $ipBin = @inet_pton($ip); $netBin = @inet_pton($net);
            if ($ipBin === false || $netBin === false || strlen($ipBin) !== strlen($netBin)) continue;
            $bytes = intdiv($bits, 8); $rem = $bits % 8;
            if ($bytes && substr($ipBin, 0, $bytes) !== substr($netBin, 0, $bytes)) continue;
            if ($rem && ((ord($ipBin[$bytes]) ^ ord($netBin[$bytes])) >> (8 - $rem)) !== 0) continue;
            return true;
        }

        return false;
    }
}
