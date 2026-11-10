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
            'ends_at'   => ['required', new Iso8601Date()],
        ];
    }

    /**
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'room_id.required'   => 'Поле :attribute обязательно для заполнения.',
            'room_id.integer'    => 'Поле :attribute должно быть целым числом.',
            'room_id.exists'     => 'Выбранная комната не существует.',
            'title.required'     => 'Поле :attribute обязательно для заполнения.',
            'title.string'       => 'Поле :attribute должно быть строкой.',
            'title.max'          => 'Поле :attribute не должно превышать :max символов.',
            'starts_at.required' => 'Поле :attribute обязательно для заполнения.',
            'ends_at.required'   => 'Поле :attribute обязательно для заполнения.',
            'ends_at.after'      => 'Время окончания должно быть позже времени начала.',
        ];
    }

    /**
     *
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'room_id'   => '«Комната»',
            'title'     => '«Название»',
            'starts_at' => '«Время начала»',
            'ends_at'   => '«Время окончания»',
        ];
    }
}
