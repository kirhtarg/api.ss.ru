<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ContactRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => 'required|string|max:255',
            'short_name' => 'nullable|string|max:255',
            'is_main' => 'nullable|boolean',
            'legal_name' => 'nullable|string|max:500',
            'inn' => 'nullable|string|max:20',
            'ogrnip' => 'nullable|string|max:20',
            'legal_address' => 'nullable|string|max:1000',
            'phones' => 'nullable|array',
            'phones.*.phone_name' => 'required_with:phones|string|max:255',
            'phones.*.phone_number' => 'required_with:phones|string|max:255',
            'addresses' => 'nullable|array',
            'addresses.*.address' => 'required_with:addresses|string',
            'addresses.*.address_short' => 'nullable|string|max:255',
            'addresses.*.longitude' => 'nullable|numeric|between:-180,180',
            'addresses.*.latitude' => 'nullable|numeric|between:-90,90',
            'addresses.*.howtogo' => 'nullable|string',
            'addresses.*.work_mode' => 'nullable|string',
            'addresses.*.is_main' => 'nullable|boolean',
            'socials' => 'nullable|array',
            'socials.*.social_type' => 'required_with:socials|exists:social_types,id',
            'socials.*.social_name' => 'required_with:socials|string|max:255',
            'socials.*.social_url' => 'required_with:socials|string|url|max:500',
        ];
    }

    /**
     * Get custom messages for validator errors.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'name.required' => 'Название контакта обязательно для заполнения',
            'name.max' => 'Название контакта не должно превышать 255 символов',
            'short_name.max' => 'Краткое название не должно превышать 255 символов',
            'legal_name.max' => 'Наименование юридического лица не должно превышать 500 символов',
            'inn.max' => 'ИНН не должен превышать 20 символов',
            'ogrnip.max' => 'ОГРНИП не должен превышать 20 символов',
            'legal_address.max' => 'Юридический адрес не должен превышать 1000 символов',
            'phones.*.phone_name.required_with' => 'Название телефона обязательно при указании телефонов',
            'phones.*.phone_name.max' => 'Название телефона не должно превышать 255 символов',
            'phones.*.phone_number.required_with' => 'Номер телефона обязателен при указании телефонов',
            'phones.*.phone_number.max' => 'Номер телефона не должен превышать 255 символов',
            'addresses.*.address.required_with' => 'Адрес обязателен при указании адресов',
            'addresses.*.address_short.max' => 'Краткий адрес не должен превышать 255 символов',
            'addresses.*.longitude.numeric' => 'Долгота должна быть числом',
            'addresses.*.longitude.between' => 'Долгота должна быть в диапазоне от -180 до 180',
            'addresses.*.latitude.numeric' => 'Широта должна быть числом',
            'addresses.*.latitude.between' => 'Широта должна быть в диапазоне от -90 до 90',
            'socials.*.social_type.required_with' => 'Тип социальной сети обязателен при указании социальных сетей',
            'socials.*.social_type.exists' => 'Выбранный тип социальной сети не существует',
            'socials.*.social_name.required_with' => 'Название социальной сети обязательно при указании социальных сетей',
            'socials.*.social_name.max' => 'Название социальной сети не должно превышать 255 символов',
            'socials.*.social_url.required_with' => 'URL социальной сети обязателен при указании социальных сетей',
            'socials.*.social_url.url' => 'URL социальной сети должен быть валидным',
            'socials.*.social_url.max' => 'URL социальной сети не должен превышать 500 символов',
        ];
    }
}
