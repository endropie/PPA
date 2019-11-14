<?php

namespace App\Models\Factory;

use Illuminate\Database\Eloquent\SoftDeletes;
use App\Models\Model;
use App\Models\WithUserBy;
use App\Models\withStateable;
use App\Filters\Filterable;

class WorkOrder extends Model
{
    use Filterable, SoftDeletes, WithUserBy, withStateable;

    protected $fillable = [
        'number', 'line_id', 'date', 'shift_id', 'stockist_from', 'description',
    ];

    protected $relationships = [
        'work_order_items.packing_items',
        'work_order_items.work_order_item_lines.work_production_items',
    ];

    protected $hidden = ['created_at', 'updated_at'];

    public function work_order_items() {
        return $this->hasMany('App\Models\Factory\WorkOrderItem')->withTrashed();
    }

    public function work_order_item_lines() {
        return $this->hasManyThrough('App\Models\Factory\WorkOrderItemLine', 'App\Models\Factory\WorkOrderItem')
                    ->withTrashed();
    }

    public function line() {
        return $this->belongsTo('App\Models\Reference\Line');
    }

    public function shift() {
        return $this->belongsTo('App\Models\Reference\Shift');
    }

    public function getTotalAmountAttribute() {
        return (double) $this->hasMany('App\Models\Factory\WorkOrderItem')->get()->sum('unit_amount');
    }

    public function getTotalProductionAttribute() {
        return (double) $this->hasMany('App\Models\Factory\WorkOrderItem')->sum('amount_process');
    }

    public function getTotalPackingAttribute() {
        return (double) $this->hasMany('App\Models\Factory\WorkOrderItem')->sum('amount_packing');
    }

    public function getHasProductedAttribute() {
        $row = $this->stateable->where('state', 'PRODUCTED')->last();
        return $row ?? null;
    }

    public function getHasPackedAttribute() {
        $row = $this->stateable->where('state', 'PACKED')->last();
        return $row ?? null;
    }
}
