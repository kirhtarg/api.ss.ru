<?php

namespace App\Http\Resources\Partner\V1;

use App\Services\OrderCalculationService;
use App\Services\Partner\PartnerStockAvailabilityService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProductResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $calculation = app(OrderCalculationService::class)->calculateFinalUnitPrice($this->resource);
        $availability = app(PartnerStockAvailabilityService::class);
        $availableQuantity = $availability->quantity($this->resource);

        return [
            'id' => $this->id,
            'name' => $this->name,
            'slug' => $this->slug,
            'sku' => $this->sku,
            'short_description' => $this->short_description,
            'description' => $this->description,
            'brand' => $this->brands->first() ? [
                'id' => $this->brands->first()->id,
                'name' => $this->brands->first()->name,
                'slug' => $this->brands->first()->slug,
            ] : null,
            'categories' => CategoryResource::collection($this->categories),
            'images' => CatalogImageResource::collection($this->images),
            'properties' => CatalogPropertyResource::collection($this->properties),
            'variations' => CatalogVariationResource::collection($this->variations->where('is_active', true)->values()),
            'price' => round((float) $calculation['base_price'], 2),
            'old_price' => (float) $calculation['base_price'] > (float) $calculation['final_price']
                ? round((float) $calculation['base_price'], 2)
                : null,
            'final_price' => round((float) $calculation['final_price'], 2),
            'discounts' => $calculation['discounts'],
            'currency' => 'RUB',
            'available_quantity' => $availableQuantity,
            'is_active' => (bool) $this->is_active,
            'is_available' => $availability->productIsAvailable($this->resource),
            'dimensions' => [
                'length' => $this->length !== null ? (float) $this->length : null,
                'width' => $this->width !== null ? (float) $this->width : null,
                'height' => $this->height !== null ? (float) $this->height : null,
                'weight' => $this->weight !== null ? (float) $this->weight : null,
            ],
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
