<?php

namespace App\Http\Resources\Partner\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CategoryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $imageUrl = $this->image;
        if ($imageUrl && ! str_starts_with($imageUrl, 'http')) {
            $imageUrl = url('/'.ltrim($imageUrl, '/'));
        }

        return [
            'id' => $this->id,
            'parent_id' => $this->parent_id,
            'name' => $this->name,
            'slug' => $this->slug,
            'image_url' => $imageUrl,
            'is_active' => (bool) $this->is_active,
            'sort_order' => (int) $this->sort_order,
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
