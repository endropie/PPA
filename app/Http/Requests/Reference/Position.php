<?php

namespace App\Http\Requests\Reference;

use App\Http\Requests\Request;

class Position extends Request
{
    public function authorize()
    {
        return true;
    }

    public function rules()
    {
        // Check if store or update
        $method = $this->getMethod();

        if ($method == 'PATCH' || $method == 'PUT')
        {
            $id = $this->position;
        }
        else $id = null;

        return [
            'name' => ($id ? 'required|string|' : '') .'max:191|unique:positions,NULL,' . $id,
        ];
    }
}
