<?php

namespace App\Http\Requests;

use App\Rules\Iso8601Date;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class StoreBookingRequest extends FormRequest
{
    /**
     *
     * @return array<string, ValidationRule|array|string>
     */
    public function rules(): array
    {
        return [
            'room_id'   => ['required', 'integer', 'exists:rooms,id'],
            'title'     => ['required', 'string', 'max:255'],
            'starts_at' => ['required', new Iso8601Date()],
            'ends_at'   => ['required', new Iso8601Date(), 'after:starts_at'],
        ];
    }
}
