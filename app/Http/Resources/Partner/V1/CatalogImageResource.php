<?php

namespace App\Http\Resources\Partner\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Log;

class CatalogImageResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $imageUrl = $this->url;
        if ($imageUrl && ! str_starts_with($imageUrl, 'http')) {
            $frontendUrl = rtrim((string) config('app.frontend_url'), '/');
            $imageUrl = $frontendUrl.'/'.ltrim($imageUrl, '/');

            if (config('app.debug')) {
                Log::debug('[FIX:partner-catalog-image-origin] Catalog image URL uses storefront origin', [
                    'image_id' => $this->id,
                    'origin' => parse_url($frontendUrl, PHP_URL_HOST),
                ]);
            }
        }

        return [
            'id' => $this->id,
            'url' => $imageUrl,
            'alt' => $this->alt_text,
            'is_main' => (bool) $this->is_main,
            'sort_order' => (int) $this->sort_order,
        ];
    }
}
