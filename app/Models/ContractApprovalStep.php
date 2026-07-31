<?php

namespace App\Models;

use App\Traits\HasUuid;
use Illuminate\Database\Eloquent\Model;

/** مرحلة موافقة داخلية على طلب توقيع عقد — قبل تسليمه للموقّعين */
class ContractApprovalStep extends Model
{
    use HasUuid;

    protected $table = 'contract_approval_steps';
    protected $guarded = ['id'];
    protected $casts = ['decided_at' => 'datetime'];

    public function request()
    {
        return $this->belongsTo(SignRequest::class, 'request_id');
    }

    public function approver()
    {
        return $this->belongsTo(User::class, 'approver_id');
    }
}
