<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Setting;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;

class SettingController extends Controller
{
    /**
     * Получить все настройки
     */
    public function index()
    {
        try {
            $settings = Setting::orderBy('group')->orderBy('key')->get();

            // Группируем настройки по группам
            $groupedSettings = [];
            foreach ($settings as $setting) {
                $group = $setting->group ?: 'general';
                if (!isset($groupedSettings[$group])) {
                    $groupedSettings[$group] = [];
                }
                $groupedSettings[$group][] = $setting;
            }

            return response()->json([
                'success' => true,
                'data' => $groupedSettings
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Ошибка получения настроек: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Создать новую настройку
     */
    public function store(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'key' => 'required|string|max:255|unique:settings,key',
                'name' => 'nullable|string|max:255',
                'type' => 'required|string|in:string,text,textarea,number,boolean,color,image,json',
                'group' => 'nullable|string|max:255',
                'description' => 'nullable|string|max:1000',
                'value' => 'nullable|string',
                'image_width' => 'nullable|integer|min:1',
                'image_height' => 'nullable|integer|min:1'
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Ошибка валидации',
                    'errors' => $validator->errors()
                ], 422);
            }

            $setting = Setting::create([
                'key' => $request->key,
                'name' => $request->name,
                'type' => $request->type,
                'group' => $request->group ?: 'general',
                'description' => $request->description,
                'value' => $request->value,
                'image_width' => $request->image_width,
                'image_height' => $request->image_height
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Настройка успешно создана',
                'data' => $setting
            ], 201);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Ошибка создания настройки: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Обновить настройку
     */
    public function update(Request $request, $id)
    {
        try {
            $setting = Setting::find($id);

            if (!$setting) {
                return response()->json([
                    'success' => false,
                    'message' => 'Настройка не найдена'
                ], 404);
            }

            // Создаем правила валидации только для переданных полей
            $validationRules = [];
            $updateData = [];

            // Проверяем, какие поля переданы и добавляем соответствующие правила
            if ($request->has('key')) {
                $validationRules['key'] = 'required|string|max:255|unique:settings,key,' . $id;
                $updateData['key'] = $request->key;
            }

            if ($request->has('name')) {
                $validationRules['name'] = 'nullable|string|max:255';
                $updateData['name'] = $request->name;
            }

            if ($request->has('type')) {
                $validationRules['type'] = 'required|string|in:string,text,textarea,number,boolean,color,image,json';
                $updateData['type'] = $request->type;
            }

            if ($request->has('group')) {
                $validationRules['group'] = 'nullable|string|max:255';
                $updateData['group'] = $request->group;
            }

            if ($request->has('description')) {
                $validationRules['description'] = 'nullable|string|max:1000';
                $updateData['description'] = $request->description;
            }

            if ($request->has('value')) {
                $validationRules['value'] = 'nullable';
                $updateData['value'] = $request->value;
            }

            if ($request->has('image_width')) {
                $validationRules['image_width'] = 'nullable|integer|min:1';
                $updateData['image_width'] = $request->image_width;
            }

            if ($request->has('image_height')) {
                $validationRules['image_height'] = 'nullable|integer|min:1';
                $updateData['image_height'] = $request->image_height;
            }

            // Если нет данных для обновления, возвращаем ошибку
            if (empty($updateData)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Нет данных для обновления'
                ], 422);
            }

            // Валидируем только переданные поля
            $validator = Validator::make($request->all(), $validationRules);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Ошибка валидации',
                    'errors' => $validator->errors()
                ], 422);
            }

            // Обновляем только переданные поля
            $setting->update($updateData);

            return response()->json([
                'success' => true,
                'message' => 'Настройка успешно обновлена',
                'data' => $setting
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Ошибка обновления настройки: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Удалить настройку
     */
    public function destroy($id)
    {
        try {
            $setting = Setting::find($id);

            if (!$setting) {
                return response()->json([
                    'success' => false,
                    'message' => 'Настройка не найдена'
                ], 404);
            }

            $setting->delete();

            return response()->json([
                'success' => true,
                'message' => 'Настройка успешно удалена'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Ошибка удаления настройки: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Загрузить изображение для настройки
     */
    public function uploadImage(Request $request, $id)
    {
        try {
            $setting = Setting::find($id);

            if (!$setting) {
                return response()->json([
                    'success' => false,
                    'message' => 'Настройка не найдена'
                ], 404);
            }

            if ($setting->type !== 'image') {
                return response()->json([
                    'success' => false,
                    'message' => 'Эта настройка не предназначена для изображений'
                ], 422);
            }

            $validator = Validator::make($request->all(), [
                'image' => 'required|image|mimes:jpeg,png,jpg,gif,webp|max:5120', // 5MB максимум
                'width' => 'nullable|integer|min:1',
                'height' => 'nullable|integer|min:1'
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Ошибка валидации',
                    'errors' => $validator->errors()
                ], 422);
            }

            $file = $request->file('image');

            // Создаем уникальное имя файла
            $filename = 'setting_' . $setting->id . '_' . time() . '.' . $file->getClientOriginalExtension();

            // Путь для сохранения
            $path = 'images/settings/' . $filename;

            // Создаем директорию, если её нет
            if (!Storage::disk('public')->exists('images/settings')) {
                Storage::disk('public')->makeDirectory('images/settings');
            }

            // Сохраняем файл
            Storage::disk('public')->putFileAs('images/settings', $file, $filename);

            // Удаляем старое изображение, если оно есть
            if ($setting->value && $setting->value !== 'default-image.png') {
                if (Storage::disk('public')->exists($setting->value)) {
                    Storage::disk('public')->delete($setting->value);
                }
            }

            // Получаем размеры изображения
            $imagePath = storage_path('app/public/' . $path);
            $imageDimensions = null;

            if (file_exists($imagePath)) {
                $imageInfo = getimagesize($imagePath);
                if ($imageInfo) {
                    $imageDimensions = [
                        'width' => $imageInfo[0],
                        'height' => $imageInfo[1]
                    ];
                }
            }

            // Если пользователь задал желаемые размеры, изменяем размер изображения при загрузке
            $requestedWidth = $request->input('width');
            $requestedHeight = $request->input('height');

            if ($requestedWidth && $requestedHeight) {
                try {
                    // Изменяем размер изображения
                    $this->resizeImageFile($imagePath, $requestedWidth, $requestedHeight);

                    $imageDimensions = [
                        'width' => $requestedWidth,
                        'height' => $requestedHeight
                    ];
                } catch (\Exception $e) {
                    // Если изменение размера не удалось, используем оригинальные размеры
                    Log::warning('Не удалось изменить размер изображения: ' . $e->getMessage());

                    if (file_exists($imagePath)) {
                        $imageInfo = getimagesize($imagePath);
                        if ($imageInfo) {
                            $imageDimensions = [
                                'width' => $imageInfo[0],
                                'height' => $imageInfo[1]
                            ];
                        }
                    }
                }
            }

            // Обновляем значение настройки и размеры
            $updateData = ['value' => $path];
            if ($imageDimensions) {
                $updateData['image_width'] = $imageDimensions['width'];
                $updateData['image_height'] = $imageDimensions['height'];
            }

            $setting->update($updateData);

            $message = 'Изображение успешно загружено';
            if ($requestedWidth && $requestedHeight) {
                $message .= " с размерами {$requestedWidth}×{$requestedHeight}px";
            }

            return response()->json([
                'success' => true,
                'message' => $message,
                'data' => [
                    'id' => $setting->id,
                    'value' => $path,
                    'image_url' => URL::asset('storage/' . $path),
                    'image_width' => $imageDimensions['width'] ?? null,
                    'image_height' => $imageDimensions['height'] ?? null,
                    'requested_width' => $requestedWidth,
                    'requested_height' => $requestedHeight
                ]
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Ошибка загрузки изображения: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Изменить размер изображения
     */
    private function resizeImageFile($imagePath, $width, $height)
    {
        try {
            // Проверяем, что расширение GD установлено
            if (!extension_loaded('gd')) {
                throw new \Exception('Расширение GD не установлено. Установите php-gd для работы с изображениями.');
            }

            // Получаем информацию об изображении
            $imageInfo = getimagesize($imagePath);
            if (!$imageInfo) {
                throw new \Exception('Не удалось получить информацию об изображении');
            }

            $originalWidth = $imageInfo[0];
            $originalHeight = $imageInfo[1];
            $mimeType = $imageInfo['mime'];

            // Создаем изображение в зависимости от типа
            switch ($mimeType) {
                case 'image/jpeg':
                    $sourceImage = imagecreatefromjpeg($imagePath);
                    break;
                case 'image/png':
                    $sourceImage = imagecreatefrompng($imagePath);
                    break;
                case 'image/gif':
                    $sourceImage = imagecreatefromgif($imagePath);
                    break;
                case 'image/webp':
                    $sourceImage = imagecreatefromwebp($imagePath);
                    break;
                default:
                    throw new \Exception('Неподдерживаемый тип изображения: ' . $mimeType);
            }

            if (!$sourceImage) {
                throw new \Exception('Не удалось создать изображение из файла');
            }

            // Создаем новое изображение с нужными размерами
            $newImage = imagecreatetruecolor($width, $height);

            // Сохраняем прозрачность для PNG и GIF
            if (in_array($mimeType, ['image/png', 'image/gif'])) {
                imagealphablending($newImage, false);
                imagesavealpha($newImage, true);
                $transparent = imagecolorallocatealpha($newImage, 255, 255, 255, 127);
                imagefill($newImage, 0, 0, $transparent);
            }

            // Изменяем размер с сохранением пропорций
            imagecopyresampled($newImage, $sourceImage, 0, 0, 0, 0, $width, $height, $originalWidth, $originalHeight);

            // Сохраняем измененное изображение
            $success = false;
            switch ($mimeType) {
                case 'image/jpeg':
                    $success = imagejpeg($newImage, $imagePath, 90);
                    break;
                case 'image/png':
                    $success = imagepng($newImage, $imagePath, 9);
                    break;
                case 'image/gif':
                    $success = imagegif($newImage, $imagePath);
                    break;
                case 'image/webp':
                    $success = imagewebp($newImage, $imagePath, 90);
                    break;
            }

            // Освобождаем память
            imagedestroy($sourceImage);
            imagedestroy($newImage);

            if (!$success) {
                throw new \Exception('Не удалось сохранить измененное изображение');
            }

        } catch (\Exception $e) {
            // Логируем ошибку, но не прерываем процесс загрузки
            Log::error('Ошибка изменения размера изображения: ' . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Удалить изображение настройки
     */
    public function deleteImage($id)
    {
        try {
            $setting = Setting::find($id);

            if (!$setting) {
                return response()->json([
                    'success' => false,
                    'message' => 'Настройка не найдена'
                ], 404);
            }

            if ($setting->type !== 'image') {
                return response()->json([
                    'success' => false,
                    'message' => 'Эта настройка не предназначена для изображений'
                ], 422);
            }

            // Удаляем файл изображения, если он существует
            if ($setting->value && $setting->value !== 'default-image.png') {
                if (Storage::disk('public')->exists($setting->value)) {
                    Storage::disk('public')->delete($setting->value);
                }
            }

            // Очищаем значение настройки, но сохраняем размеры
            $setting->update(['value' => null]);

            return response()->json([
                'success' => true,
                'message' => 'Изображение успешно удалено',
                'data' => [
                    'id' => $setting->id,
                    'value' => null
                ]
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Ошибка удаления изображения: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Изменить размер изображения настройки
     */
    public function resizeImage(Request $request, $id)
    {
        try {
            $setting = Setting::find($id);

            if (!$setting) {
                return response()->json([
                    'success' => false,
                    'message' => 'Настройка не найдена'
                ], 404);
            }

            if ($setting->type !== 'image') {
                return response()->json([
                    'success' => false,
                    'message' => 'Эта настройка не предназначена для изображений'
                ], 422);
            }

            if (!$setting->value) {
                return response()->json([
                    'success' => false,
                    'message' => 'У настройки нет загруженного изображения'
                ], 422);
            }

            $validator = Validator::make($request->all(), [
                'width' => 'required|integer|min:1',
                'height' => 'required|integer|min:1'
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Ошибка валидации',
                    'errors' => $validator->errors()
                ], 422);
            }

            $width = $request->input('width');
            $height = $request->input('height');

            // Получаем полный путь к изображению
            $imagePath = storage_path('app/public/' . $setting->value);

            if (!file_exists($imagePath)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Файл изображения не найден'
                ], 404);
            }

            // Изменяем размер изображения
            $this->resizeImageFile($imagePath, $width, $height);

            // Обновляем размеры в базе данных
            $setting->update([
                'image_width' => $width,
                'image_height' => $height
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Размер изображения успешно изменен',
                'data' => [
                    'id' => $setting->id,
                    'value' => $setting->value,
                    'image_width' => $width,
                    'image_height' => $height,
                    'image_url' => URL::asset('storage/' . $setting->value)
                ]
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Ошибка изменения размера изображения: ' . $e->getMessage()
            ], 500);
        }
    }
}
