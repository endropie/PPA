<?php

Namespace App\Models\Common;

use App\Models\Model;

class ItemStock extends Model
{
   static $stockists = [
      'FM' => 'Fresh Material',
      'WO' => 'Work Order',
      'WIP' => 'Work In Process',
      'FG' => 'Finish Good',
      'NG' => 'Not Good',
      'RET' => 'Not Good Return',
      'VDO' => 'Verification-DO amounable',
      'PDO.REG' => 'REGULER-PDO amounable', // REGDO
      'PDO.RET' => 'RETURN-PDO amounable', // RETDO
      'RDO.REG' => 'REGULER Request-DO amounable',
      'RDO.RET' => 'RETURN Request-DO amounable',
   ];

   protected $fillable = ['item_id', 'stockist', 'total'];

   protected $hidden = ['created_at', 'updated_at'];

   protected $casts = [
       'total' => 'double'
   ];

   public function item()
   {
      return $this->belongsTo('App\Models\Common\Item');
   }

   public static function getStockists() {
      return collect(static::$stockists);
   }

   public static function getValidStockist($code) {
      $enum = static::getStockists();
      if(!$enum->has($code)) {
         abort(500, 'CODE STOCK INVALID!');
      }
      return $code;
    }
}
