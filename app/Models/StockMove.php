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

/** حركات المخزون */
class StockMove extends Model
{
    use HasFactory, HasUuid, SoftDeletes, Auditable, HasVersions, Searchable;

    protected $table = 'stock_moves';
    public const MODULE = 'stockmv';
    public const DISPLAY = 'doc_no';

    protected $guarded = ['id', 'version', 'created_by'];

    protected $casts = [
        'qty' => 'decimal:3',
        'date' => 'date',
        'custom' => 'array',
        'meta' => 'array',
        'archived' => 'boolean',
    ];

    public function item(): BelongsTo
    {
        return $this->belongsTo(\App\Models\StockItem::class, 'item_id');
    }

    public function by(): BelongsTo
    {
        return $this->belongsTo(\App\Models\User::class, 'by_id');
    }
}
