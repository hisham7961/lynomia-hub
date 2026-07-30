<?php

namespace App\Models;

use App\Traits\Auditable;
use App\Traits\HasUuid;
use App\Traits\HasVersions;
use App\Traits\Searchable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class ComplianceItem extends Model
{
    use HasUuid, SoftDeletes, Auditable, HasVersions, Searchable;

    protected $table = 'compliance_items';
    public const MODULE = 'compliance';
    public const DISPLAY = 'title';

    protected $guarded = ['id', 'version', 'created_by'];

    protected $casts = [
        'due' => 'date', 'renewed_at' => 'date',
        'tags' => 'array', 'custom' => 'array', 'meta' => 'array',
        'archived' => 'boolean',
    ];
}
