<?php

namespace App\Http\Controllers;

use App\Models\Contact;
use App\Models\ContactPhone;
use App\Models\ContactAddress;
use App\Models\ContactSocial;
use App\Models\SocialType;
use App\Http\Requests\ContactRequest;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class ContactController extends Controller
{
    /**
     * Получить список всех контактов
     */
    public function index(): JsonResponse
    {
        $contacts = Contact::with(['phones', 'addresses', 'socials.socialType'])
            ->orderBy('name')
            ->get();

        return response()->json([
            'success' => true,
            'data' => $contacts
        ]);
    }

    /**
     * Получить конкретный контакт
     */
    public function show(int $id): JsonResponse
    {
        $contact = Contact::with(['phones', 'addresses', 'socials.socialType'])
            ->find($id);

        if (!$contact) {
            return response()->json([
                'success' => false,
                'message' => 'Контакт не найден'
            ], 404);
        }

        return response()->json([
            'success' => true,
            'data' => $contact
        ]);
    }

    /**
     * Создать новый контакт
     */
    public function store(ContactRequest $request): JsonResponse
    {

        try {
            DB::beginTransaction();

            // Если устанавливаем как главный, сбрасываем главность у других контактов
            if ($request->is_main) {
                Contact::where('is_main', true)->update(['is_main' => false]);
            }

            $contact = Contact::create([
                'name' => $request->name,
                'short_name' => $request->short_name,
                'is_main' => $request->is_main ?? false,
            ]);

            // Добавляем телефоны
            if ($request->has('phones')) {
                foreach ($request->phones as $phoneData) {
                    // Если устанавливаем как главный телефон, сбрасываем главность у других телефонов этого контакта
                    if ($phoneData['is_main'] ?? false) {
                        ContactPhone::where('id_contact', $contact->id)
                            ->where('is_main', true)
                            ->update(['is_main' => false]);
                    }

                    ContactPhone::create([
                        'id_contact' => $contact->id,
                        'phone_name' => $phoneData['phone_name'],
                        'phone_number' => $phoneData['phone_number'],
                        'is_main' => $phoneData['is_main'] ?? false,
                    ]);
                }
            }

            // Добавляем адреса
            if ($request->has('addresses')) {
                foreach ($request->addresses as $addressData) {
                    // Если устанавливаем как главный адрес, сбрасываем главность у других адресов этого контакта
                    if ($addressData['is_main'] ?? false) {
                        ContactAddress::where('id_contact', $contact->id)
                            ->where('is_main', true)
                            ->update(['is_main' => false]);
                    }

                    ContactAddress::create([
                        'id_contact' => $contact->id,
                        'address' => $addressData['address'],
                        'address_short' => $addressData['address_short'] ?? null,
                        'longitude' => $addressData['longitude'] ?? null,
                        'latitude' => $addressData['latitude'] ?? null,
                        'howtogo' => $addressData['howtogo'] ?? null,
                        'is_main' => $addressData['is_main'] ?? false,
                    ]);
                }
            }

            // Добавляем социальные сети
            if ($request->has('socials')) {
                foreach ($request->socials as $socialData) {
                    ContactSocial::create([
                        'id_contact' => $contact->id,
                        'social_type' => $socialData['social_type'],
                        'social_name' => $socialData['social_name'],
                        'social_url' => $socialData['social_url'],
                    ]);
                }
            }

            DB::commit();

            $contact->load(['phones', 'addresses', 'socials.socialType']);

            return response()->json([
                'success' => true,
                'message' => 'Контакт успешно создан',
                'data' => $contact
            ], 201);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Ошибка при создании контакта: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Обновить контакт
     */
    public function update(ContactRequest $request, int $id): JsonResponse
    {
        $contact = Contact::find($id);

        if (!$contact) {
            return response()->json([
                'success' => false,
                'message' => 'Контакт не найден'
            ], 404);
        }

        try {
            DB::beginTransaction();

            // Если устанавливаем как главный, сбрасываем главность у других контактов
            if ($request->is_main) {
                Contact::where('is_main', true)
                    ->where('id', '!=', $contact->id)
                    ->update(['is_main' => false]);
            }

            $contact->update([
                'name' => $request->name,
                'short_name' => $request->short_name,
                'is_main' => $request->is_main ?? false,
            ]);

            // Обновляем телефоны
            if ($request->has('phones')) {
                $contact->phones()->delete();
                foreach ($request->phones as $phoneData) {
                    // Если устанавливаем как главный телефон, сбрасываем главность у других телефонов этого контакта
                    if ($phoneData['is_main'] ?? false) {
                        ContactPhone::where('id_contact', $contact->id)
                            ->where('is_main', true)
                            ->update(['is_main' => false]);
                    }

                    ContactPhone::create([
                        'id_contact' => $contact->id,
                        'phone_name' => $phoneData['phone_name'],
                        'phone_number' => $phoneData['phone_number'],
                        'is_main' => $phoneData['is_main'] ?? false,
                    ]);
                }
            }

            // Обновляем адреса
            if ($request->has('addresses')) {
                $contact->addresses()->delete();
                foreach ($request->addresses as $addressData) {
                    // Если устанавливаем как главный адрес, сбрасываем главность у других адресов этого контакта
                    if ($addressData['is_main'] ?? false) {
                        ContactAddress::where('id_contact', $contact->id)
                            ->where('is_main', true)
                            ->update(['is_main' => false]);
                    }

                    ContactAddress::create([
                        'id_contact' => $contact->id,
                        'address' => $addressData['address'],
                        'address_short' => $addressData['address_short'] ?? null,
                        'longitude' => $addressData['longitude'] ?? null,
                        'latitude' => $addressData['latitude'] ?? null,
                        'howtogo' => $addressData['howtogo'] ?? null,
                        'is_main' => $addressData['is_main'] ?? false,
                    ]);
                }
            }

            // Обновляем социальные сети
            if ($request->has('socials')) {
                $contact->socials()->delete();
                foreach ($request->socials as $socialData) {
                    ContactSocial::create([
                        'id_contact' => $contact->id,
                        'social_type' => $socialData['social_type'],
                        'social_name' => $socialData['social_name'],
                        'social_url' => $socialData['social_url'],
                    ]);
                }
            }

            DB::commit();

            $contact->load(['phones', 'addresses', 'socials.socialType']);

            return response()->json([
                'success' => true,
                'message' => 'Контакт успешно обновлен',
                'data' => $contact
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Ошибка при обновлении контакта: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Удалить контакт
     */
    public function destroy(int $id): JsonResponse
    {
        $contact = Contact::find($id);

        if (!$contact) {
            return response()->json([
                'success' => false,
                'message' => 'Контакт не найден'
            ], 404);
        }

        try {
            $contact->delete();

            return response()->json([
                'success' => true,
                'message' => 'Контакт успешно удален'
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Ошибка при удалении контакта: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Получить типы социальных сетей
     */
    public function getSocialTypes(): JsonResponse
    {
        $socialTypes = SocialType::orderBy('social')->get();

        return response()->json([
            'success' => true,
            'data' => $socialTypes
        ]);
    }

    /**
     * Получить главный адрес для хедера
     */
    public function getMainAddress(): JsonResponse
    {
        $mainContact = Contact::getMainContact();
        
        if (!$mainContact) {
            return response()->json([
                'success' => false,
                'message' => 'Контакты не найдены'
            ], 404);
        }

        $mainAddress = $mainContact->mainAddress();
        
        if (!$mainAddress) {
            return response()->json([
                'success' => false,
                'message' => 'Адреса не найдены'
            ], 404);
        }

        return response()->json([
            'success' => true,
            'data' => [
                'contact_name' => $mainContact->name,
                'address_short' => $mainAddress->address_short,
                'address' => $mainAddress->address,
                'latitude' => $mainAddress->latitude,
                'longitude' => $mainAddress->longitude
            ]
        ]);
    }

    /**
     * Получить главный телефон для хедера
     */
    public function getMainPhone(): JsonResponse
    {
        $mainContact = Contact::getMainContact();
        
        if (!$mainContact) {
            return response()->json([
                'success' => false,
                'message' => 'Контакты не найдены'
            ], 404);
        }

        $mainPhone = $mainContact->mainPhone();
        
        if (!$mainPhone) {
            return response()->json([
                'success' => false,
                'message' => 'Телефоны не найдены'
            ], 404);
        }

        return response()->json([
            'success' => true,
            'data' => [
                'contact_name' => $mainContact->name,
                'phone_name' => $mainPhone->phone_name,
                'phone_number' => $mainPhone->phone_number
            ]
        ]);
    }

    /**
     * Получить все телефоны главного контакта для хедера
     */
    public function getMainContactPhones(): JsonResponse
    {
        $mainContact = Contact::getMainContact();
        
        if (!$mainContact) {
            return response()->json([
                'success' => false,
                'message' => 'Контакты не найдены'
            ], 404);
        }

        $phones = $mainContact->phones()->orderBy('is_main', 'desc')->get();
        
        if ($phones->isEmpty()) {
            return response()->json([
                'success' => false,
                'message' => 'Телефоны не найдены'
            ], 404);
        }

        return response()->json([
            'success' => true,
            'data' => [
                'contact_name' => $mainContact->name,
                'phones' => $phones->map(function ($phone) {
                    return [
                        'id' => $phone->id,
                        'phone_name' => $phone->phone_name,
                        'phone_number' => $phone->phone_number,
                        'is_main' => $phone->is_main
                    ];
                })
            ]
        ]);
    }

    /**
     * Получить все данные контактов для хедера одним запросом
     */
    public function getHeaderData(): JsonResponse
    {
        $mainContact = Contact::getMainContact();
        
        if (!$mainContact) {
            return response()->json([
                'success' => false,
                'message' => 'Контакты не найдены'
            ], 404);
        }

        // Получаем основной адрес
        $mainAddress = $mainContact->mainAddress();
        $addressData = null;
        if ($mainAddress) {
            $addressData = [
                'id' => $mainAddress->id,
                'address' => $mainAddress->address,
                'address_short' => $mainAddress->address_short,
                'latitude' => $mainAddress->latitude,
                'longitude' => $mainAddress->longitude,
                'is_main' => $mainAddress->is_main,
                'contact_name' => $mainContact->name
            ];
            
        }

        // Получаем основной телефон
        $mainPhone = $mainContact->mainPhone();
        $phoneData = null;
        if ($mainPhone) {
            $phoneData = [
                'id' => $mainPhone->id,
                'phone_name' => $mainPhone->phone_name,
                'phone_number' => $mainPhone->phone_number,
                'is_main' => $mainPhone->is_main
            ];
        }

        // Получаем все телефоны
        $phones = $mainContact->phones()->orderBy('is_main', 'desc')->get();
        $phonesData = $phones->map(function ($phone) {
            return [
                'id' => $phone->id,
                'phone_name' => $phone->phone_name,
                'phone_number' => $phone->phone_number,
                'is_main' => $phone->is_main
            ];
        });

        return response()->json([
            'success' => true,
            'data' => [
                'contact_name' => $mainContact->name,
                'address' => $addressData,
                'main_phone' => $phoneData,
                'phones' => $phonesData
            ]
        ]);
    }
}
