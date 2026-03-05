<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class GoogleSheetsController extends Controller
{
    /**
     * Тестовый метод для проверки доступности контроллера
     */
    public function test()
    {
        return response()->json(['message' => 'GoogleSheetsController is working']);
    }

    /**
     * Загрузить данные из Google Sheets
     */
    public function loadGoogleSheets(Request $request): JsonResponse
    {
        try {
            Log::info('GoogleSheetsController::loadGoogleSheets called', [
                'method' => $request->method(),
                'url' => $request->url(),
                'all' => $request->all(),
            ]);

            $request->validate([
                'spreadsheetId' => 'required|string',
            ]);

            $spreadsheetId = $request->input('spreadsheetId');

            Log::info('Loading Google Sheets', ['spreadsheetId' => $spreadsheetId]);
            $sheets = [];

            // Пробуем загрузить разные листы (обычно gid=0, 1, 2, 3...)
            $maxSheets = 10; // Максимум 10 листов для проверки

            for ($gid = 0; $gid < $maxSheets; $gid++) {
                try {
                    $csvUrl = "https://docs.google.com/spreadsheets/d/{$spreadsheetId}/export?format=csv&gid={$gid}";

                    $response = Http::timeout(30)->get($csvUrl);

                    if ($response->successful()) {
                        $csvText = $response->body();
                        $lines = array_filter(explode("\n", $csvText), function ($line) {
                            return trim($line) !== '';
                        });

                        if (count($lines) > 0) {
                            // Определяем заголовки и данные
                            $headers = array_map(function ($h) {
                                return trim(str_replace('"', '', $h));
                            }, explode(',', $lines[0]));

                            $data = array_map(function ($line) {
                                return array_map(function ($cell) {
                                    return trim(str_replace('"', '', $cell));
                                }, explode(',', $line));
                            }, array_slice($lines, 1));

                            // Проверяем, что лист не пустой
                            if (count($headers) > 0 && count($data) > 0) {
                                $sheets[] = [
                                    'gid' => $gid,
                                    'name' => 'Лист '.($gid + 1),
                                    'headers' => $headers,
                                    'data' => $data,
                                ];
                            }
                        }
                    }
                } catch (\Exception $e) {
                    // Игнорируем ошибки для несуществующих листов
                    Log::debug("Google Sheets gid {$gid} not found: ".$e->getMessage());

                    continue;
                }
            }

            if (empty($sheets)) {
                Log::warning('No sheets found in Google Sheets', ['spreadsheetId' => $spreadsheetId]);

                return response()->json([
                    'success' => false,
                    'message' => 'Не найдено ни одного листа с данными в таблице',
                ], 404);
            }

            Log::info('Google Sheets loaded successfully', [
                'spreadsheetId' => $spreadsheetId,
                'sheetsCount' => count($sheets),
            ]);

            return response()->json([
                'success' => true,
                'data' => [
                    'sheets' => $sheets,
                ],
            ]);

        } catch (\Exception $e) {
            Log::error('Ошибка загрузки Google Sheets: '.$e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Ошибка загрузки данных из Google Sheets: '.$e->getMessage(),
            ], 500);
        }
    }
}
