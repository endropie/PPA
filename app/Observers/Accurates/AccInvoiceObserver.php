<?php

namespace App\Observers\Accurates;

use App\Models\Income\AccInvoice as Model;

class AccInvoiceObserver
{

    public function pushing(Model $model, $record)
    {
        $mode = $model->customer->invoice_mode;
        $serviceModel = (boolean) ($record['is_model_service'] ?? false);

        $detailItems = $model->delivery_items
        ->sortBy(function ($detail) {
            return $detail['item']['code'];
        })
        ->groupBy('item_id')
        ->values();

        $detailItems = $detailItems->map(function ($details, $key) use ($mode, $serviceModel, $detailItems) {

            $quantity = collect($details)->sum('quantity');
            $detail = $details->first();

            $detailName = $detail->item->part_name;
            $subnameMode = setting()->get('item.subname_mode', null);
            $subnameLabel = setting()->get('item.subname_label', null);
            $detailNotes = !$subnameMode ? null : (string) $subnameLabel ." ". $detail->item->part_subname;

            $unit = ucfirst($detail->item->unit->code);

            $useTax1 = (boolean) $detail->item->customer->with_ppn;
            $useTax3 = (boolean) $detail->item->customer->with_pph;

            if ($detail->item->part_name != $detail->item->part_number) $detailName .= " (".$detail->item->part_number.")";

            $senService = (double) ($detail->item->customer->sen_service) / 100;

            $price = (double) round($detail->item->price, 7);

            if ($mode == 'SUMMARY') {

                if ($detail->item->customer->exclude_service) {
                    $v = (100 + $detail->item->customer->sen_service) / 100;
                    $priceMaterial = round(ceil($price / $v * 10000) / 10000, 7);
                    $priceService  = round($price - $priceMaterial, 7);
                }
                else {
                    $priceMaterial = round(ceil($price * (1 - $senService) * 10000) / 10000, 7);
                    $priceService  = round($price - $priceMaterial, 7);
                }

                return [
                    "detailItem[$key].itemNo" => 'ITEM-MATERIAL',
                    "detailItem[$key].detailName" => (string) $detailName,
                    "detailItem[$key].detailNotes" => $detailNotes,
                    "detailItem[$key].quantity" => (double) $quantity,
                    "detailItem[$key].unitPrice" => (double) $priceMaterial,
                    "detailItem[$key].itemUnitName" => (string) $unit,
                    "SUMMARY_JASA" => (double) ($quantity * $priceService)
                ];
            }
            else if ($mode == 'DETAIL') {
                $length = $detailItems->count();
                $senService = (double) ($detail->item->customer->sen_service / 100);
                $priceMaterial = round(ceil($price * (1 - $senService) * 10000) / 10000, 7);
                $priceService  = round($price - $priceMaterial, 7);

                return [

                    "detailItem[$key].itemNo" => 'ITEM-MATERIAL',
                    "detailItem[$key].detailName" => (string) "[MATERIAL] ". $detailName,
                    "detailItem[$key].detailNotes" => $detailNotes,
                    "detailItem[$key].quantity" => (double) $quantity,
                    "detailItem[$key].unitPrice" => (double) $priceMaterial,
                    "detailItem[$key].itemUnitName" => (string) $unit,

                    "detailItem[". ($key+$length) ."].itemNo" => 'ITEM-JASA',
                    "detailItem[". ($key+$length) ."].detailName" => (string) "[JASA] ". $detailName,
                    "detailItem[". ($key+$length) ."].detailNotes" => $detailNotes,
                    "detailItem[". ($key+$length) ."].quantity" => (double) $quantity,
                    "detailItem[". ($key+$length) ."].unitPrice" => (double) $priceService,
                    "detailItem[". ($key+$length) ."].itemUnitName" => (string) $unit,
                ];
            }
            else if ($mode == 'SEPARATE') {
                $senService = (double) ($detail->item->customer->sen_service / 100);
                $priceMaterial = round(ceil($price * (1 - $senService) * 10000) / 10000, 7);
                $priceService  = round($price - $priceMaterial, 7);
                $detailPrice = $serviceModel ? $priceService : $priceMaterial;
                $detailNo = ($serviceModel ? 'ITEM-JASA' : 'ITEM-MATERIAL');
                $detailName = ($serviceModel ? '[JASA] ' : '[MATERIAL] '). $detailName;

                return [
                    "detailItem[$key].itemNo" => $detailNo,
                    "detailItem[$key].detailName" => (string) $detailName,
                    "detailItem[$key].detailNotes" => $detailNotes,
                    "detailItem[$key].quantity" => (double) $quantity,
                    "detailItem[$key].unitPrice" => (double) ($detailPrice),
                    "detailItem[$key].itemUnitName" => (string) $unit,
                ];
            }
            else if ($mode == 'JOIN') {
                return [
                    "detailItem[$key].itemNo" => 'ITEM-JASA',
                    "detailItem[$key].detailName" => (string) $detailName,
                    "detailItem[$key].detailNotes" => $detailNotes,
                    "detailItem[$key].quantity" => (double) $quantity,
                    "detailItem[$key].unitPrice" => (double) $price,
                    "detailItem[$key].itemUnitName" => (string) $unit,
                ];
            }
            else {
                abort(501, "Invoice mode [". $detail->item->customer->invoice_mode ."] failed" );
            }
        });

        if ($mode == 'SUMMARY')
        {
            $key = $detailItems->count();
            $sum = (double) $detailItems->sum('SUMMARY_JASA');
            $detailItems = $detailItems->push([
                "detailItem[$key].itemNo" => 'ITEM-JASA-TOTAL',
                "detailItem[$key].detailName" => 'JASA Part',
                "detailItem[$key].quantity" => 1,
                "detailItem[$key].unitPrice" => (double) $sum,
            ]);
        }

        $detailItems = $detailItems->collapse()->toArray();

        if ($branchId = env('ACCURATE_BRANCH_ID', null))
        {
            $record = array_merge($record, ['branchId' => $branchId]);
        }

        $record = array_merge($record, [
            // "saveAsStatusType" => "UNAPPROVED",
            // "paymentTermName" => "net 30",
        ]);

        return array_merge($record, $detailItems);
    }
}
