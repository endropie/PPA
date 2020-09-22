<?php

namespace App\Http\Controllers\Api\Incomes;

use App\Http\Requests\Income\DeliveryLoad as Request;
use App\Http\Controllers\ApiController;
// use App\Filters\Income\DeliveryLoad as Filters;
use App\Filters\Filter as Filter;
use App\Models\Common\Item;
use App\Models\Income\DeliveryLoad;
use App\Models\Income\RequestOrder;
use App\Models\Income\RequestOrderItem;
use App\Traits\GenerateNumber;

class DeliveryLoads extends ApiController
{
    use GenerateNumber;

    public function index(Filter $filter)
    {
        switch (request('mode')) {
            case 'all':
                $delivery_loads = DeliveryLoad::filter($filter)->latest()->get();
                break;

            case 'datagrid':
                $delivery_loads = DeliveryLoad::with(['customer'])->filter($filter)->latest()->get();
                $delivery_loads->each->append(['is_relationship']);
                break;

            default:
                $delivery_loads = DeliveryLoad::with(['created_user','customer'])->filter($filter)->latest()->collect();
                $delivery_loads->getCollection()->transform(function($item) {
                    $item->append(['is_relationship']);
                    return $item;
                });
                break;
        }

        return response()->json($delivery_loads);
    }

    public function store(Request $request)
    {
        $this->DATABASE::beginTransaction();
        if(!$request->number) $request->merge(['number'=> $this->getNextDeliveryLoadNumber()]);

        $delivery_load = DeliveryLoad::create($request->input());

        $rows = $request->delivery_load_items;
        for ($i=0; $i < count($rows); $i++) {
            // create detail item created!
            $detail = $delivery_load->delivery_load_items()->create($rows[$i]);

            $request->validate(
                ["delivery_load_items.$i.quantity" => "lte:". $detail->maxAmountDetail() ],
                ["delivery_load_items.$i.quantity.lte" => "Maximum ". $detail->maxAmountDetail() ]
            );

            $detail->setLoadVerified();
        }


        if ($delivery_load->transaction == "REGULER" && $delivery_load->order_mode == "ACCUMULATE")
        {
            $this->storeRequestOrder($delivery_load->fresh());
        }
        else {
            $this->storeDeliveryOrder($delivery_load->fresh());
        }

        $this->DATABASE::commit();
        return response()->json($delivery_load);
    }

    public function show($id)
    {
        $delivery_load = DeliveryLoad::with([
            'customer',
            'vehicle',
            'delivery_load_items.item.item_units',
            'delivery_load_items.item.unit',
            'delivery_load_items.unit',
        ])->withTrashed()->findOrFail($id);

        $delivery_load->append(['has_relationship']);

        return response()->json($delivery_load);
    }

    public function destroy($id)
    {
        $this->DATABASE::beginTransaction();

        $delivery_load = DeliveryLoad::findOrFail($id);

        $mode = strtoupper(request('mode') ?? 'DELETED');


        if ($delivery_load->is_relationship) $this->error("Delivery (Load) has RELATIONSHIP, is not allowed to be $mode");

        if ($mode == "VOID") {
            if ($delivery_load->status == 'VOID') $this->error("Delivery (Load) is `$delivery_load->status`, is not allowed to be $mode");
        }
        else {
            if ($delivery_load->status != 'OPEN') $this->error("Delivery (Load) is `$delivery_load->status`, is not allowed to be $mode");
        }

        if($mode == "VOID") {
            $delivery_load->status = "VOID";
            $delivery_load->save();
        }

        foreach ($delivery_load->delivery_load_items as $detail) {

            $detail->delete();
        }

        $delivery_load->delete();

        $this->DATABASE::commit();

        return response()->json(['success' => true]);
    }

    public function storeRequestOrder($delivery_load)
    {

        $request_order = RequestOrder::where('customer_id', $delivery_load->customer_id)
            ->where('order_mode', $delivery_load->order_mode)
            ->where('status', 'OPEN')
            ->whereMonth('date', substr($delivery_load->date, 5, 2))
            ->whereYear('date', substr($delivery_load->date, 0, 4))
            ->oldest()->first();

        if (!$request_order) {
            $request_order = new RequestOrder;
            $request_order->date  = $delivery_load->date;
            $request_order->customer_id = $delivery_load->customer_id;
            $request_order->order_mode   = $delivery_load->order_mode;
            $request_order->transaction   = 'REGULER';

            $begin = \Carbon::parse($delivery_load->date)->startOfMonth()->format('Y-m-d');
            $until = \Carbon::parse($delivery_load->date)->endOfMonth()->format('Y-m-d');
            $request_order->description   = "ACCUMULATE P/O. $begin - $until";
            // For model update
            if (!$request_order->id) {
                $request_order->number = $this->getNextRequestOrderNumber($delivery_load->date);
            }
            $request_order->save();
        }

        $prefix_code = $delivery_load->customer->code ?? "C$delivery_load->customer_id";

        $delivery_order = $delivery_load->delivery_orders()->create([
            'number' => $this->getNextSJDeliveryNumber($delivery_load->date),
            'indexed_number' => $this->getNextSJDeliveryIndexedNumber($delivery_load->date, $prefix_code),
            'transaction' =>  $delivery_load->transaction,
            'customer_id' => $delivery_load->customer_id,
            'customer_name' => $delivery_load->customer_name,
            'customer_phone' => $delivery_load->customer_phone,
            'customer_address' => $delivery_load->customer_address,
            'description' => $delivery_load->description,
            'customer_note' => $delivery_load->customer_note,
            'date' => $delivery_load->date,
            'vehicle_id' => $delivery_load->vehicle_id,
            'rit' => $delivery_load->rit,
        ]);

        $rows = $delivery_load->delivery_load_items
            ->groupBy('item_id')
            ->map(function ($group) {
                return [
                    'item_id' => $group[0]->item_id,
                    'quantity' => $group->sum('unit_amount'),
                    'price' => $group[0]->item->price,
                    'unit_id' => $group[0]->item->unit_id,
                    'unit_rate' => 1,
                ];
            });

        foreach ($rows as $row) {
            $detail = $request_order->request_order_items()->create($row);

            ## Setup unit price
            $detail->price = ($detail->item && $detail->item->price)
                ? $detail->unit_rate * $detail->item->price : 0;
            $detail->save();

            $delivery_order_item = $delivery_order->delivery_order_items()->create($row);
            $delivery_order_item->request_order_item()->associate($detail);
            $delivery_order_item->save();

            $delivery_order_item->item->transfer($delivery_order_item, $delivery_order_item->unit_amount, null, 'FG');
        }

        $delivery_order->delivery_order_items->each->calculate();
        $delivery_order->request_order()->associate($request_order);
        $delivery_order->save();
    }

    public function storeDeliveryOrder($delivery_load)
    {
        $list = []; $over=[];
        $request_order_items = RequestOrderItem::whereRaw('(quantity * unit_rate) > amount_delivery')
            ->whereHas('request_order', function ($query) use ($delivery_load) {
                $order_mode = $delivery_load->transaction == 'RETURN' ? 'NONE' : $delivery_load->order_mode;
                return $query->where('status', 'OPEN')
                    ->where('order_mode', $order_mode)
                    ->where('transaction', $delivery_load->transaction)
                    ->where('customer_id', $delivery_load->customer_id)
                    ->where('date', '<=', $delivery_load->date)
                    ->whereRaw("$delivery_load->date <= IFNULL(actived_date, '$delivery_load->date') ");
            })->get();

        $request_order_items = $request_order_items
            ->map(function ($detail) {
                $detail->sortin = $detail->request_order->date ." ". $detail->request_order->created_at;
                return $detail;
            })
            ->sortBy('sortin');

        $outer = $delivery_load->delivery_load_items
            ->groupBy('item_id')
            ->map(function ($group) {
                return $group->sum('unit_amount');
            });

        foreach ($request_order_items as $detail) {
            if (isset($outer[$detail->item_id])) {

                $max_amount = $outer[$detail->item_id];
                $unit_amount = $detail->unit_amount - $detail->amount_delivery;
                $unit_amount = ($max_amount < $unit_amount ? $max_amount : $unit_amount);

                $outer[$detail->item_id] -= $unit_amount;

                if ($unit_amount > 0) {
                    $RO = $detail->request_order_id;
                    $DTL = $detail->id;

                    $list[$RO][$DTL] = [
                        'item_id' => $detail->item_id,
                        'quantity' => $unit_amount,
                        'price' => $detail->item->price ?? 0,
                        'unit_id' => $detail->item->unit_id,
                        'unit_rate' => 1,
                    ];
                }
            }
        }

        foreach ($outer as $key => $amount) {
            if (round($amount) > 0) {
                $item = Item::find($key);
                $label = ($item->part_number ?? $key);
                $this->error("OVER OUTGOING BY PO [$label:$amount]" . $outer);
            }
        }

        if (sizeof($over)) {
            $prefix_code = $delivery_load->customer->code ?? "C$delivery_load->customer_id";
            $delivery_order = $delivery_load->delivery_orders()->create([
                'number' => $this->getNextSJInternalNumber($delivery_load->date),
                'indexed_number' => $this->getNextSJDeliveryIndexedNumber($delivery_load->date, $prefix_code),
                'transaction' =>  $delivery_load->transaction,
                'customer_id' => $delivery_load->customer_id,
                'customer_name' => $delivery_load->customer_name,
                'customer_phone' => $delivery_load->customer_phone,
                'customer_address' => $delivery_load->customer_address,
                'customer_note' => $delivery_load->customer_note,
                'description' => $delivery_load->description,
                'date' => $delivery_load->date,
                'vehicle_id' => $delivery_load->vehicle_id,
                'rit' => $delivery_load->rit,
            ]);

            foreach ($over as $key => $amount) {
                $item = Item::find($key);
                $detail = $delivery_order->delivery_order_items()->create([
                    'item_id' => $item->id,
                    'quantity' => $amount,
                    'price' => $item->price ?? 0,
                    'unit_id' => $item->unit->id,
                    'unit_rate' => 1
                ]);
                $detail->item->transfer($detail, $detail->unit_amount, null, 'FG');
                $detail->save();
            }
        }

        foreach ($list as $RO => $rows) {
            $request_order = RequestOrder::findOrFail($RO);
            $prefix_code = $delivery_load->customer->code ?? "C$delivery_load->customer_id";

            $delivery_order = $delivery_load->delivery_orders()->create([
                'number' => $this->getNextSJDeliveryNumber($delivery_load->date),
                'indexed_number' => $this->getNextSJDeliveryIndexedNumber($delivery_load->date, $prefix_code),
                'transaction' =>  $delivery_load->transaction,
                'customer_id' => $delivery_load->customer_id,
                'customer_name' => $delivery_load->customer_name,
                'customer_phone' => $delivery_load->customer_phone,
                'customer_address' => $delivery_load->customer_address,
                'customer_note' => $delivery_load->customer_note,
                'description' => $delivery_load->description,
                'date' => $delivery_load->date,
                'vehicle_id' => $delivery_load->vehicle_id,
                'rit' => $delivery_load->rit,
            ]);

            foreach ($rows as $DTL => $row) {
                $request_order_item = RequestOrderItem::find($DTL);
                $detail = $delivery_order->delivery_order_items()->create($row);
                $detail->item->transfer($detail, $detail->unit_amount, null, 'FG');

                $detail->request_order_item()->associate($request_order_item);
                $detail->save();
                $request_order_item->calculate();
            }

            $delivery_order->request_order()->associate($request_order);
            $delivery_order->save();
        }
    }
}