<?php

namespace App\Models;

use App\Traits\Auditable;
use App\Traits\HasUuid;
use App\Traits\HasVersions;
use App\Traits\Searchable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/** التزام عقدي متتبع — بند بمسؤول واستحقاق ومبلغ (CLM م7) */
class ContractObligation extends Model
{
    use HasUuid, SoftDeletes, Auditable, HasVersions, Searchable;

    protected $table = 'contract_obligations';
    public const MODULE = 'obligations';
    public const DISPLAY = 'title';

    protected $guarded = ['id', 'version', 'created_by'];

    protected $casts = [
        'due' => 'date', 'amount' => 'float',
        'tags' => 'array', 'custom' => 'array', 'meta' => 'array',
    ];

    public function contract()
    {
        return $this->belongsTo(Contract::class, 'contract_id');
    }
}
