<?php

namespace App\Models\Income;

use App\Filters\Filterable;
use App\Models\Model;
use App\Models\WithUserBy;
use Illuminate\Database\Eloquent\SoftDeletes;

class PreDelivery extends Model
{
    use Filterable, SoftDeletes, WithUserBy;

    protected $fillable = [
        'number', 'customer_id', 'description',
        'transaction', 'order_mode', 'rit', 'date', // 'plan_begin_date', 'plan_until_date'
    ];

    protected $appends = ['fullnumber'];

    protected $relationships = [
        'pre_delivery_items.outgoing_verifications'
    ];

    public function pre_delivery_items()
    {
        return $this->hasMany('App\Models\Income\PreDeliveryItem')->withTrashed();
    }

    public function schedules()
    {
        return $this->belongsToMany('App\Models\Transport\ScheduleBoard', 'pre_delivery_schedules')->using('App\Models\Income\PreDeliverySchedule');
    }

    public function incoming_good() {
        return $this->hasOne('App\Models\Warehouse\IncomingGood');
    }

    public function customer()
    {
        return $this->belongsTo('App\Models\Income\Customer');
    }

    public function getSummaryItemsAttribute() {
        return (double) $this->hasMany('App\Models\Income\PreDeliveryItem')->get()->sum('quantity');
    }

    public function getSummaryVerificationsAttribute() {
        return (double) $this->hasMany('App\Models\Income\PreDeliveryItem')->get()->sum(function($item) {
            return (double) ($item->amount_verification / ($item->unit_rate?? 1));
        });
    }

    public function getFullnumberAttribute()
    {
        if ($this->revise_number) return $this->number ." R.". (int) $this->revise_number;

        return $this->number;
    }
}
