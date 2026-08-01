<?php

namespace App\Http\Resources\Partner\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CatalogImageResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $imageUrl = $this->url;

        return [
            'id' => $this->id,
            'url' => $imageUrl && ! str_starts_with($imageUrl, 'http') ? url($imageUrl) : $imageUrl,
            'alt' => $this->alt_text,
            'is_main' => (bool) $this->is_main,
            'sort_order' => (int) $this->sort_order,
        ];
    }
}
