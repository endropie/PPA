<?php

namespace App\Http\Requests\Income;

use App\Http\Requests\Request;

class Forecast extends Request
{
    public function authorize()
    {
        return true;
    }

    public function rules()
    {
        // Check if store or update
        $method = $this->getMethod();

        if ($method == 'PATCH' || $method == 'PUT') {
            $id = $this->forecast;

            if($this->exists('nodata')) return [];
        }
        else $id = null;

        return [
            'number' => ($id ? 'required|string|' : '') .'max:191|unique:forecasts,NULL,' . $id,
            'period_id' => 'required|exists:forecast_periods,id',
            'customer_id' => 'required',

            'forecast_items.*.item_id' => 'required',

            'forecast_items' =>
            function ($attribute, $value, $fail) {
                if (count($value) == 0) {
                    $fail('Delivery-Items must be select min. 1 item production.');
                }
            },
        ];
    }

    public function messages()
    {
        $msg = 'The field is required!';

        return [
            'forecast_items.*.item_id' => $msg,
        ];
    }
}
