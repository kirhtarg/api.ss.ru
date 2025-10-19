<?php

namespace App\Http\Controllers\Api\Public;

use App\Http\Controllers\Controller;
use App\Models\Contact;
use Illuminate\Http\Request;
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
            
            if (!$contact) {
                return response()->json([
                    'success' => false,
                    'message' => 'Основные контакты не найдены'
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
                'data' => $contactData
            ]);
            
        } catch (\Exception $e) {
            
            return response()->json([
                'success' => false,
                'message' => 'Ошибка получения контактов'
            ], 500);
        }
    }

    /**
     * Получить данные контактов для заголовка
     */
    public function headerData()
    {
        try {
            $contact = Contact::with(['addresses', 'phones', 'socials'])
                ->where('is_main', 1)
                ->first();
            
            if (!$contact) {
                return response()->json([
                    'success' => false,
                    'message' => 'Основные контакты не найдены'
                ], 404);
            }
            
            // Получаем основные данные
            $mainAddress = $contact->mainAddress();
            $mainPhone = $contact->mainPhone();
            
            // Формируем данные для заголовка в формате, ожидаемом frontend
            $headerData = [
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
                    'contact_name' => $mainAddress->contact_name
                ] : null,
                'main_phone' => $mainPhone ? [
                    'id' => $mainPhone->id,
                    'phone' => $mainPhone->phone_number,
                    'phone_number' => $mainPhone->phone_number,
                    'phone_name' => $mainPhone->phone_name,
                    'is_main' => $mainPhone->is_main
                ] : null,
                'phones' => $contact->phones->map(function($phone) {
                    return [
                        'id' => $phone->id,
                        'phone' => $phone->phone_number,
                        'phone_number' => $phone->phone_number,
                        'phone_name' => $phone->phone_name,
                        'is_main' => $phone->is_main
                    ];
                }),
                'socials' => $contact->socials->map(function($social) {
                    return [
                        'name' => $social->name,
                        'url' => $social->url,
                        'icon' => $social->icon
                    ];
                })
            ];
            
            return response()->json([
                'success' => true,
                'data' => $headerData
            ]);
            
        } catch (\Exception $e) {
            
            return response()->json([
                'success' => false,
                'message' => 'Ошибка получения данных контактов'
            ], 500);
        }
    }
}
