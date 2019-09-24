<?php

namespace App\Models\Factory;

use Illuminate\Database\Eloquent\SoftDeletes;
use App\Models\Model;
use App\Filters\Filterable;

class WorkProduction extends Model
{
   use Filterable, SoftDeletes;

   protected $fillable = ['number', 'line_id', 'date', 'shift_id', 'worktime', 'oprator_id', 'description'];

   protected $hidden = ['created_at', 'updated_at'];

   public function work_production_items()
   {
      return $this->hasMany('App\Models\Factory\WorkProductionItem')->withTrashed();
   }

   public function line()
   {
      return $this->belongsTo('App\Models\Reference\Line');
   }

   public function shift()
   {
      return $this->belongsTo('App\Models\Reference\Shift');
   }
}