<?php

namespace App\Models;

use App\Traits\HasUuid;
use Illuminate\Database\Eloquent\Model;

/** موقّع في طلب توقيع — رمزه الخاص وترتيبه ودوره وأثره الكامل */
class ContractSigner extends Model
{
    use HasUuid;

    protected $table = 'contract_signers';
    protected $guarded = ['id'];
    protected $hidden = ['otp_code'];
    protected $casts = ['signed_at' => 'datetime', 'otp_expires_at' => 'datetime'];

    public function request()
    {
        return $this->belongsTo(SignRequest::class, 'request_id');
    }
}
