<?php

namespace App\Http\Controllers\Api\Public;

use App\Http\Controllers\Controller;
use App\Models\Contact;
use App\Models\ContactAddress;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class ContactController extends Controller
{
    /**
     * Получить основные контакты магазина
     */
    public function getMain()
    {
        try {
            $contact = Contact::with(['addresses', 'phones', 'socials'])
                ->where('is_main', 1)
                ->first();

            if (! $contact) {
                return response()->json([
                    'success' => false,
                    'message' => 'Основные контакты не найдены',
                ], 404);
            }

            // Получаем основные данные
            $mainAddress = $contact->mainAddress();
            $mainPhone = $contact->mainPhone();

            // Формируем данные для накладной
            $contactData = [
                'name' => $contact->name,
                'short_name' => $contact->short_name,
                'legal_name' => $contact->legal_name,
                'inn' => $contact->inn,
                'ogrn' => $contact->ogrnip, // Используем ogrnip как ogrn
                'kpp' => null, // KPP не хранится в таблице
                'address' => $mainAddress ? $mainAddress->address : null,
                'phone' => $mainPhone ? $mainPhone->phone : null,
                'email' => null, // Email не хранится в таблице contacts
                'legal_address' => $contact->legal_address,
            ];

            return response()->json([
                'success' => true,
                'data' => $contactData,
            ]);

        } catch (\Exception $e) {

            return response()->json([
                'success' => false,
                'message' => 'Ошибка получения контактов',
            ], 500);
        }
    }

    /**
     * Получить данные контактов для заголовка
     */
    public function headerData()
    {
        try {
            $headerData = Cache::remember('public_contacts_header_data', 300, function () {
                $contact = Contact::with(['addresses', 'phones', 'socials.socialType'])
                    ->where('is_main', 1)
                    ->first();

                if (! $contact) {
                    return null;
                }

                // Получаем основные данные
                $mainAddress = $contact->mainAddress();
                $mainPhone = $contact->mainPhone();

                // Формируем данные для заголовка в формате, ожидаемом frontend
                return [
                    'name' => $contact->name,
                    'short_name' => $contact->short_name,
                    'legal_name' => $contact->legal_name,
                    'inn' => $contact->inn,
                    'ogrnip' => $contact->ogrnip,
                    'legal_address' => $contact->legal_address,
                    'address' => $mainAddress ? [
                        'id' => $mainAddress->id,
                        'address' => $mainAddress->address,
                        'address_short' => $mainAddress->address_short,
                        'latitude' => $mainAddress->latitude,
                        'longitude' => $mainAddress->longitude,
                        'howtogo' => $mainAddress->howtogo,
                        'work_mode' => $mainAddress->work_mode,
                        'is_main' => $mainAddress->is_main,
                        'contact_name' => $mainAddress->contact_name,
                    ] : null,
                    'main_phone' => $mainPhone ? [
                        'id' => $mainPhone->id,
                        'phone' => $mainPhone->phone_number,
                        'phone_number' => $mainPhone->phone_number,
                        'phone_name' => $mainPhone->phone_name,
                        'is_main' => $mainPhone->is_main,
                    ] : null,
                    'phones' => $contact->phones->map(function ($phone) {
                        return [
                            'id' => $phone->id,
                            'phone' => $phone->phone_number,
                            'phone_number' => $phone->phone_number,
                            'phone_name' => $phone->phone_name,
                            'is_main' => $phone->is_main,
                        ];
                    })->values()->all(),
                    'social_networks' => $contact->socials->map(function ($social) {
                        return [
                            'id' => $social->id,
                            'id_contact' => $social->id_contact,
                            'social_name' => $social->social_name,
                            'social_url' => $social->social_url,
                            'social_type' => $social->socialType ? [
                                'id' => $social->socialType->id,
                                'social' => $social->socialType->social,
                                'icon' => $social->socialType->icon,
                            ] : null,
                        ];
                    })->values()->all(),
                ];
            });

            if (! $headerData) {
                return response()->json([
                    'success' => false,
                    'message' => 'Основные контакты не найдены',
                ], 404);
            }

            return response()->json([
                'success' => true,
                'data' => $headerData,
            ]);

        } catch (\Exception $e) {

            return response()->json([
                'success' => false,
                'message' => 'Ошибка получения данных контактов',
            ], 500);
        }
    }

    /**
     * Получить адреса для самовывоза
     */
    public function getPickupAddresses()
    {
        try {
            $addresses = ContactAddress::where('is_delivery', true)
                ->with('contact')
                ->orderBy('is_main', 'desc')
                ->orderBy('name')
                ->get()
                ->map(function ($address) {
                    return [
                        'id' => $address->id,
                        'name' => $address->name,
                        'address' => $address->address,
                        'address_short' => $address->address_short,
                        'latitude' => $address->latitude,
                        'longitude' => $address->longitude,
                        'howtogo' => $address->howtogo,
                        'work_mode' => $address->work_mode,
                        'is_main' => $address->is_main,
                        'contact_name' => $address->contact->name ?? null,
                    ];
                });

            return response()->json([
                'success' => true,
                'data' => $addresses,
            ]);

        } catch (\Exception $e) {
            Log::error('Ошибка получения адресов самовывоза: '.$e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Ошибка получения адресов самовывоза',
            ], 500);
        }
    }
}
