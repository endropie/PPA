<?php

namespace App\Models\Factory;

use App\Models\Model;
use App\Filters\Filterable;

class WorkinProduction extends Model
{
   use Filterable;
   
   protected $fillable = ['number', 'line_id', 'date', 'shift_id', 'worktime', 'description'];

   protected $hidden = ['created_at', 'updated_at'];

   public function workin_production_items()
   {
      return $this->hasMany('App\Models\Factory\WorkinProductionItem');
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