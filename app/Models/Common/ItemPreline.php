<?php

namespace App\Models\Common;

use App\Models\Model;

class ItemPreline extends Model
{
   protected $fillable = ['line_id', 'ismain', 'note'];

   protected $hidden = ['created_at', 'updated_at'];

   protected $relationships = ['incoming_good_items'];

   public function item()
   {
      return $this->belongsTo('App\Models\Common\Item');
   }

   public function line()
   {
      return $this->belongsTo('App\Models\Reference\Line');
   }
}
