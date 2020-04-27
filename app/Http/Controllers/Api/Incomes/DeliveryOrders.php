<?php

namespace App\Http\Controllers\Api\Incomes;

use App\Filters\Income\DeliveryOrder as Filters;
use App\Http\Requests\Income\DeliveryOrder as Request;
use App\Http\Controllers\ApiController;
use App\Models\Income\Customer;
use App\Models\Income\DeliveryOrder;
use App\Models\Income\DeliveryOrderItem;
use App\Models\Income\RequestOrder;
use App\Models\Income\RequestOrderItem;
use App\Traits\GenerateNumber;

class DeliveryOrders extends ApiController
{
    use GenerateNumber;

    public function index(Filters $filters)
    {
        switch (request('mode')) {
            case 'all':
                $delivery_orders = DeliveryOrder::filter($filters)->get();
                break;

            case 'datagrid':
                $delivery_orders = DeliveryOrder::with(['customer','operator','vehicle'])->filter($filters)->orderBy('id', 'DESC')->latest()->get();
                $delivery_orders->each->append(['is_relationship']);
                break;

            default:
                $delivery_orders = DeliveryOrder::with(['created_user','customer','operator','vehicle'])->filter($filters)->orderBy('id', 'DESC')->latest()->collect();
                $delivery_orders->getCollection()->transform(function($item) {
                    $item->append(['reconcile_number','is_relationship', 'summary_items', 'summary_reconciles']);
                    return $item;
                });
                break;
        }

        return response()->json($delivery_orders);
    }

    public function store(Request $request)
    {
        $this->DATABASE::beginTransaction();

        ## Form validate is SAMPLE transaction only
        $request->validate(['transaction' => 'in:SAMPLE']);

        if ($customer = Customer::find($request->get('customer_id'))) {
            $prefix_code = $customer->code ?? "C:$customer->id";
        }
        else $request->validate(['customer_id' => 'not_in:'.$request->get('customer_id')]);

        if(!$request->number) $request->merge([
            'number'=> $this->getNextSJDeliveryNumber(),
            'indexed_number'=> $this->getNextSJDeliveryIndexedNumber($request->get('date'), $prefix_code),
        ]);

        $delivery_order = DeliveryOrder::create($request->input());

        foreach ($request->delivery_order_items as $row) {
            ## create DeliveryOrder items on the Delivery order revision!
            $detail = $delivery_order->delivery_order_items()->create($row);
        }

        $this->DATABASE::commit();
        return response()->json($delivery_order);
    }

    public function show($id)
    {
        $delivery_order = DeliveryOrder::with([
            'created_user',
            'customer',
            'vehicle',
            'request_order',
            'revisions',
            'reason',
            'delivery_order_items.item.unit',
            'delivery_order_items.unit',
            'delivery_order_items.item.item_units',
            'delivery_order_items.request_order_item',
        ])->withTrashed()->findOrFail($id);

        $delivery_order->append(['reconcile_number', 'has_relationship']);

        return response()->json($delivery_order);
    }

    public function update(Request $request, $id)
    {
        if (request('mode') == 'confirmation') return $this->confirmation($id);
        else if (request('mode') == 'revision') return $this->revision($request, $id);
        else if (request('mode') == 'multi-revision') return $this->multiRevision($request, $id);
        else if (request('mode') == 'internal-revision') return $this->internalRevision($request, $id);
        else if (request('mode') == 'reconciliation') return $this->reconciliation($request, $id);
        else if (request('mode') == 'item-encasement') return $this->encasement($request, $id);

        $this->DATABASE::beginTransaction();

        ## Form validate is SAMPLE transaction only
        $request->validate(['transaction' => 'in:SAMPLE']);

        $delivery_order = DeliveryOrder::findOrFail($id);
        $delivery_order->update($request->input());

        ## DISCARD HAS VENDOR EXPERTICE'S REMOVED!
        $collection = collect($request->delivery_order_items);
        foreach ($delivery_order->delivery_order_items as $detail) {
            if (!$collection->contains('id', $detail->id)) {
                $detail->forceDelete();
            }
        }

        foreach ($request->delivery_order_items as $row) {
            ## create DeliveryOrder items on the Delivery order revision!
            $detail = $delivery_order->delivery_order_items()->updateOrCreate(['id' => $row['id']], $row);
        }

        $this->DATABASE::commit();
        return response()->json($delivery_order);
    }

    public function destroy($id)
    {
        $this->DATABASE::beginTransaction();

        $delivery_order = DeliveryOrder::findOrFail($id);

        $mode = strtoupper(request('mode') ?? 'DELETED');
        if($delivery_order->is_relationship) $this->error("The data has RELATIONSHIP, is not allowed to be $mode!");
        if($mode == "DELETED" && $delivery_order->status != "OPEN") $this->error("The data $delivery_order->status state, is not allowed to be $mode!");

        foreach ($delivery_order->delivery_order_items as $detail) {
            $request_order_item = $detail->request_order_item;
            $reconcile_item = $detail->reconcile_item;

            $detail->item->distransfer($detail);

            $detail->request_order_item()->dissociate();
            $detail->save();


            if ($request_order_item) {
                $request_order_item->calculate();
                if ($request_order_item->request_order->order_mode == 'ACCUMULATE') {
                    $request_order_item->forceDelete();
                }
            }

            $detail->delete();
            if ($reconcile_item) $reconcile_item->calculate();
        }

        $delivery_order->status = $mode;
        $delivery_order->request_order()->dissociate();
        $delivery_order->save();

        $delivery_order->delete();

        $this->DATABASE::commit();
        return response()->json(['success' => true]);
    }

    public function encasement($request, $id)
    {
        $this->DATABASE::beginTransaction();

        $delivery_order = DeliveryOrder::findOrFail($id);

        if ($delivery_order->status != "OPEN") $this->error("SJDO[$delivery_order->number] has not OPEN state. Update not allowed!");

        $request->validate(['id' => 'required']);

        $delivery_order_item = DeliveryOrderItem::findOrFail($request->id);

        $delivery_order_item->update($request->input());

        $this->DATABASE::commit();
        return $this->show($delivery_order->id);
    }

    public function confirmation($id)
    {
        $this->DATABASE::beginTransaction();

        $delivery_order = DeliveryOrder::findOrFail($id);

        if ($delivery_order->status != "OPEN") $this->error("SJDO[$delivery_order->number] has not OPEN state. Confirmation not allowed!");

        foreach ($delivery_order->delivery_order_items as $detail) {
            if ($detail->request_order_item) $detail->request_order_item->calculate();
        }

        $delivery_order->status = 'CONFIRMED';
        $delivery_order->save();

        if ($delivery_order->request_order) $this->setRequestOrderClosed($delivery_order->request_order);

        $this->DATABASE::commit();
        return $this->show($delivery_order->id);
    }

    public function multiRevision($request, $id)
    {
        $this->DATABASE::beginTransaction();

        $revise = DeliveryOrder::findOrFail($id);
        $request_order = $revise->request_order;

        if($revise->trashed()) $this->error("[". $revise->number ."] is trashed. MANY-REVISION Not alowed!");
        if($revise->is_internal) $this->error("[". $revise->number ."] is INTERNAL. MANY-REVISION Not alowed!");
        if(!$request_order) $this->error("[". $revise->number ."] RequestOrder Failed. MANY-REVISION Not alowed!");
        if($revise->status != 'OPEN') {
            if ($revise->request_order->status == 'CLOSED') $this->error("[". $revise->request_order->number ."] has CLOSED. REVISION Not alowed!");
            if ($revise->reconcile_id) $this->error("[". $revise->number ."] BASE INTERNAL. REVISION Not alowed!");
        }

        ## Remove detail of revision
        foreach ($revise->delivery_order_items as $detail) {
            if ($request_order_item = $detail->request_order_item) {
                if($revise->request_order->order_mode == 'ACCUMULATE') {
                    $request_order_item->item->distransfer($request_order_item);
                    $request_order_item->forceDelete();
                }
            }

            $detail->item->distransfer($detail);
            $detail->request_order_item()->dissociate();
            $detail->save();
            $detail->delete();

            if ($request_order_item) $request_order_item->calculate();
        }

        $request->validate([
            'partitions' => 'required',
            'partitions.*.request_order_id' => 'required',
            'partitions.*.transaction' => 'required',
        ]);

        ## New delivery order of partitions
        foreach ($request->partitions as $key => $partition) {
            ## Auto generate number of revision
            $max = (int) DeliveryOrder::where('number', $request->number)->max('revise_number');

            $request->merge([
                'revise_number'=> ($max + 1),
                'transaction' => $partition['transaction'],
                'description' => $partition['description'],
            ]);

            $delivery_order = DeliveryOrder::create($request->all());
            $request_order = RequestOrder::find($partition['request_order_id']);
            if (!$request_order) $request->validate(["partitions.$key.request_order_id" => "not_in:".$partition["request_order_id"]]);

            $rows = $partition['delivery_order_items'];
            for ($i=0; $i < count($rows); $i++) {
                $row = $rows[$i];

                ## IF "ACCUMULATE" create RequestOrder items on the Delivery order revision!
                if ($request_order->order_mode == 'ACCUMULATE')
                {
                    $request_order_item = $request_order->request_order_items()->create(array_merge($row, ['price' => 0]));
                    ## Setup unit price
                    $request_order_item->price = ($request_order_item->item && $request_order_item->item->price)
                        ? (double) $request_order_item->unit_rate * (double) $request_order_item->item->price : 0;
                    $request_order_item->save();
                }
                else {
                    $request_order_item = RequestOrderItem::find($row['request_order_item_id']);
                }

                ## create DeliveryOrder items on the Delivery order revision!
                $detail = $delivery_order->delivery_order_items()->create($row);
                $detail->item->transfer($detail, $detail->unit_amount, null, 'FG');

                $detail->request_order_item()->associate($request_order_item);
                $detail->save();

                $request_order_item->calculate();

                if($detail->request_order_item) {
                    if(round($detail->request_order_item->amount_delivery) > round($detail->request_order_item->unit_amount)) {
                        $max = round($detail->request_order_item->unit_amount - $detail->request_order_item->amount_delivery);
                        $this->error("Part [". $detail->item->part_name ."] unit maximum '$max'");
                    }
                }
                else $this->error("Part [". $detail->item->part_name ."] relation [#$detail->request_order_item] undifined!");
            }

            $delivery_order->request_order_id = $partition["request_order_id"];
            $delivery_order->outgoing_good_id = $request->outgoing_good_id;
            $delivery_order->revise_id = $revise->id;
            $delivery_order->save();

        }

        $revise->request_order()->dissociate();
        $revise->status = 'REVISED';
        $revise->reason_id = $request->get('reason_id', null);
        $revise->reason_description = $request->get('reason_description', null);
        $revise->save();
        $revise->delete();

        $this->DATABASE::commit();
        return response()->json($delivery_order);
    }

    public function revision($request, $id)
    {
        $this->DATABASE::beginTransaction();

        $revise = DeliveryOrder::findOrFail($id);
        $request_order = $revise->request_order;

        if($revise->trashed()) $this->error("[". $revise->number ."] is trashed. REVISION Not alowed!");

        if($revise->status != 'OPEN') {
            if ($revise->is_internal) $this->error("[". $revise->number ."] not OPEN. REVISION Not alowed!");
            else {
                if ($revise->request_order->status == 'CLOSED') $this->error("[". $revise->request_order->number ."] has CLOSED. REVISION Not alowed!");
                if ($revise->reconcile_id) $this->error("[". $revise->number ."] BASE INTERNAL. REVISION Not alowed!");
            }
        }

        ## Remove detail of revision
        foreach ($revise->delivery_order_items as $detail) {
            if (!$revise->is_internal) {
                if($revise->request_order->order_mode == 'ACCUMULATE') {
                    $request_order_item = $detail->request_order_item;
                    $request_order_item->item->distransfer($request_order_item);
                    $request_order_item->forceDelete();
                }
                else {
                    $request_order_item = $detail->request_order_item;
                    $detail->save();
                    $request_order_item->calculate();
                }
            }

            $detail->item->distransfer($detail);
            $detail->request_order_item()->dissociate();
            $detail->save();
            $detail->delete();
        }

        ## Auto generate number of revision
        if ($request->number) {
            $max = (int) DeliveryOrder::withTrashed()
                ->selectRaw('MAX(revise_number * 1) AS N')
                ->where('number', $request->number)
                ->get()->max('N');

            $request->merge(['revise_number'=> ($max + 1)]);
        }

        $delivery_order = DeliveryOrder::create($request->all());

        if (!$revise->is_internal) {
            $request_order = RequestOrder::find($request['request_order_id']);
            if (!$request_order) $request->validate(["request_order_id" => "not_in:".$request["request_order_id"]]);
        }

        $rows = $request->delivery_order_items;
        for ($i=0; $i < count($rows); $i++) {
            $row = $rows[$i];

            if (!$revise->is_internal) {
                ## IF "ACCUMULATE" create RequestOrder items on the Delivery order revision!
                if($request_order->order_mode == 'ACCUMULATE') {
                    $request_order_item = $request_order->request_order_items()->create(array_merge($row, ['price' => 0]));
                    ## Setup unit price
                    $request_order_item->price = ($request_order_item->item && $request_order_item->item->price)
                        ? (double) $request_order_item->unit_rate * (double) $request_order_item->item->price : 0;
                    $request_order_item->save();
                }
                else {
                    $request_order_item = RequestOrderItem::find($row['request_order_item_id']);
                }
            }

            ## create DeliveryOrder items on the Delivery order revision!
            $detail = $delivery_order->delivery_order_items()->create($row);
            $detail->item->transfer($detail, $detail->unit_amount, null, 'FG');

            // $PDO = $delivery_order->transaction == "RETURN" ? 'PDO.RET' : 'PDO.REG';
            // $detail->item->transfer($detail, $detail->unit_amount, null, $PDO);
            // $detail->item->transfer($detail, $detail->unit_amount, null, 'VDO');

            if (!$revise->is_internal) {
                $detail->request_order_item()->associate($request_order_item);
                $detail->save();

                if($detail->request_order_item) {
                    if(round($detail->request_order_item->amount_delivery) > round($detail->request_order_item->unit_amount)) {
                        $max = round($detail->request_order_item->unit_amount - $detail->request_order_item->amount_delivery);
                        $this->error("Part [". $detail->item->part_name ."] unit maximum '$max'");
                    }
                }
                else $this->error("Part [". $detail->item->part_name ."] relation [#$detail->request_order_item] undifined!");

                $detail->request_order_item->calculate();
            }

        }

        if (!$revise->is_internal) $delivery_order->request_order_id = $request->request_order_id;

        $delivery_order->outgoing_good_id = $request->outgoing_good_id;
        $delivery_order->revise_id = $revise->id;
        $delivery_order->save();

        $revise->request_order()->dissociate();
        $revise->status = 'REVISED';
        $revise->reason_id = $request->get('reason_id', null);
        $revise->reason_description = $request->get('reason_description', null);
        $revise->save();
        $revise->delete();

        $this->DATABASE::commit();
        return response()->json($delivery_order);
    }

    public function reconciliation($request, $id)
    {
        $this->DATABASE::beginTransaction();

        $request->validate([
            "reconcile_id" => "required",
            "delivery_order_items.*.request_order_item_id" => "required",
            "delivery_order_items.*.item_id" => "required",
        ]);

        $reconcile = DeliveryOrder::findOrFail($id);
        $request_order = RequestOrder::find($request->request_order["id"]);
        $prefix_code = $reconcile->customer->code ?? "C$reconcile->customer_id";

        ## Auto generate number of reconciliation
        $request->merge([
            'number'=> $this->getNextSJDeliveryNumber($request->get('date')),
            'indexed_number'=> $this->getNextSJDeliveryIndexedNumber($request->get('date'), $prefix_code)
        ]);

        $delivery_order = DeliveryOrder::create($request->all());

        $rows = $request->delivery_order_items;
        for ($i=0; $i < count($rows); $i++) {
            $row = $rows[$i];

            ## create DeliveryOrder items on the Delivery order revision!
            $detail = $delivery_order->delivery_order_items()->create($row);

            if ($request_order_item = RequestOrderItem::find($row['request_order_item_id'])) {
                $detail->request_order_item()->associate($request_order_item);
                $detail->save();
                $detail->request_order_item->calculate();
            }
            else $this->error("Reconcile [". ($detail->item->part_name ?? "#$detail->id") ."] DETAIL (".$row['request_order_item_id'].") Failed!");

            if ($reconcile_item = $detail->getReconcileItem($reconcile)) {
                $detail->reconcile_item_id = $reconcile_item->id;
                $detail->save();
                $detail->reconcile_item->calculate();
            }
            else $this->error("Reconcile [". ($detail->item->part_name ?? "#$detail->id") ."] Failed!");
        }

        $delivery_order->request_order_id = $request_order->id;
        $delivery_order->reconcile_id = $reconcile->id;
        $delivery_order->status = $reconcile->status;
        $delivery_order->save();

        $this->DATABASE::commit();
        return response()->json($delivery_order);
    }

    private function setRequestOrderClosed($request_order)
    {
        $unconfirm = $request_order->delivery_orders->filter(function($delivery) {
            return $delivery->status != "CONFIRMED";
        });

        $delivered = round($request_order->total_unit_amount) == round($request_order->total_unit_delivery);

        if ($request_order->order_mode == "NONE" && $unconfirm->count() == 0 && $delivered) {
            $request_order->status = 'CLOSED';
            $request_order->save();
        }
    }
}
