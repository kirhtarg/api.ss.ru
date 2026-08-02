<?php

namespace App\Http\Resources\Partner\V1;

use App\Services\OrderCalculationService;
use App\Services\Partner\PartnerStockAvailabilityService;
use App\Services\Partner\PartnerCatalogContractService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProductResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $calculation = app(OrderCalculationService::class)->calculateFinalUnitPrice($this->resource);
        $availability = app(PartnerStockAvailabilityService::class);
        $availabilityState = $availability->productState($this->resource);
        $availableQuantity = $availabilityState['available_quantity'];
        $contract = app(PartnerCatalogContractService::class);
        $isActive = $availability->productIsActiveForCatalog($this->resource);
        $purchaseState = $contract->purchaseState($availableQuantity, (bool) $this->is_preorder, $isActive);

        return [
            'id' => $this->id,
            'name' => $this->name,
            'slug' => $this->slug,
            'sku' => $this->sku,
            'short_description' => $this->short_description,
            'description' => $this->description,
            'brand' => $this->brands->first()
                ? (new BrandResource($this->brands->first()))->resolve($request)
                : null,
            'categories' => CategoryResource::collection($this->categories),
            'images' => CatalogImageResource::collection($this->images),
            'properties' => CatalogPropertyResource::collection($this->properties),
            'variations' => CatalogVariationResource::collection($this->variations->where('is_active', true)->values()),
            'price' => round((float) $calculation['base_price'], 2),
            'old_price' => (float) $calculation['base_price'] > (float) $calculation['final_price']
                ? round((float) $calculation['base_price'], 2)
                : null,
            'final_price' => round((float) $calculation['final_price'], 2),
            'sale_price' => $this->sale_price !== null ? round((float) $this->sale_price, 2) : null,
            'demping_price' => $this->show_demping && $this->demping_price !== null
                ? round((float) $this->demping_price, 2) : null,
            'show_demping' => (bool) $this->show_demping,
            'discounts' => $calculation['discounts'],
            'currency' => 'RUB',
            'available_quantity' => $availableQuantity,
            'stock_quantity' => $contract->sourceStock($this->stock_quantity, true),
            'remote_stock_quantity' => $contract->sourceStock($this->remote_stock_quantity),
            'fast_remote_stock_quantity' => $contract->sourceStock($this->fast_remote_stock_quantity),
            'is_active' => $isActive,
            'is_available' => $availabilityState['is_available'],
            ...$purchaseState,
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
