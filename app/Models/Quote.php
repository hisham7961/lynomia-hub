<?php

namespace App\Models;

use App\Traits\Auditable;
use App\Traits\HasUuid;
use App\Traits\HasVersions;
use App\Traits\Searchable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/** عروض الأسعار */
class Quote extends Model
{
    use HasFactory, HasUuid, SoftDeletes, Auditable, HasVersions, Searchable;

    protected $table = 'quotes';
    public const MODULE = 'quotes';
    public const DISPLAY = 'doc_no';

    protected $guarded = ['id', 'version', 'created_by'];

    protected $casts = [
        'date' => 'date',
        'valid' => 'date',
        'amount' => 'decimal:3',
        'tax' => 'decimal:3',
        'total' => 'decimal:3',
        'custom' => 'array',
        'meta' => 'array',
        'archived' => 'boolean',
    ];

    public function client(): BelongsTo
    {
        return $this->belongsTo(\App\Models\Client::class, 'client_id');
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(\App\Models\Project::class, 'project_id');
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(\App\Models\Company::class, 'company_id');
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(\App\Models\User::class, 'owner_id');
    }
}
