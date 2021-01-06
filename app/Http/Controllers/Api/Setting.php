<?php
namespace App\Http\Controllers\Api;

use App\Http\Controllers\ApiController;
use Illuminate\Http\Request;

class Setting extends ApiController
{

    public function get()
    {
        return response()->json(setting()->all());
    }

    public function set(Request $request, $name)
    {

        foreach ($request->input() as $key => $value) {
            if (is_array($value) && sizeof($value) == 0) setting()->forget("$name.$key");
            else {
                setting()->set("$name.$key", $value);
            }
        }

        setting()->save();

        return response()->json(setting()->all());
    }

}
