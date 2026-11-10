<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class DestroyBookingRequest extends FormRequest
{
    public function all($keys = null): array
    {
        $data = parent::all($keys);

        $data['id'] = $this->route('id');

        return $data;
    }

    /**
     *
     * @return array<string, ValidationRule|array|string>
     */
    public function rules(): array
    {
        return [
            'id' => ['required', 'numeric', 'between:1,9223372036854775807'],
        ];
    }
}
