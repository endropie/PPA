<?php
namespace App\Models\Warehouse;

use Illuminate\Database\Eloquent\SoftDeletes;
use App\Models\Model;
use App\Models\WithUserBy;
use App\Filters\Filterable;
use App\Traits\HasCommentable;

class IncomingGood extends Model
{
    use Filterable, SoftDeletes, WithUserBy, HasCommentable;

    protected $fillable = [
        'number', 'registration', 'date', 'time', 'transaction', 'order_mode',
        'customer_id', 'reference_number', 'reference_date',
        'vehicle_id', 'rit', 'description',
        'revise_number', 'indexed_number'
    ];

    protected $appends = ['fullnumber'];

    protected $relationships = [
        'delivery_task',
        'request_order_closed',
        'request_order.delivery_orders' => 'delivery_orders'
    ];

    public function incoming_good_items()
    {
        return $this->hasMany('App\Models\Warehouse\IncomingGoodItem')->withTrashed();
    }

    public function incoming_validations()
    {
        return $this->hasMany('App\Models\Warehouse\IncomingValidation');

    }

    public function request_order() {
        return $this->belongsTo('App\Models\Income\RequestOrder');
    }

    public function request_order_closed() {
        return $this->belongsTo('App\Models\Income\RequestOrder', 'request_order_id')->where('status', 'CLOSED');
    }

    public function delivery_task () {
        return $this->hasOne('App\Models\Income\DeliveryTask');
    }

    public function customer()
    {
        return $this->belongsTo('App\Models\Income\Customer');
    }

    public function getFullnumberAttribute()
    {
        if ($this->revise_number) return $this->number ." R.". (int) $this->revise_number;

        return $this->number;
    }
}
