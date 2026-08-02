<?php

namespace App\Http\Resources\Partner\V1;

use App\Services\OrderCalculationService;
use App\Services\Partner\PartnerStockAvailabilityService;
use App\Services\Partner\PartnerCatalogContractService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CatalogVariationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $calculation = app(OrderCalculationService::class)->calculateFinalUnitPrice($this->good, $this->resource);
        $availability = app(PartnerStockAvailabilityService::class);
        $availableQuantity = $availability->quantity($this->good, $this->resource);
        $contract = app(PartnerCatalogContractService::class);
        $purchaseState = $contract->purchaseState(
            $availableQuantity,
            (bool) $this->good?->is_preorder,
            (bool) $this->is_active && $availability->productIsActiveForCatalog($this->good),
        );

        return [
            'id' => $this->id,
            'sku' => $this->sku,
            'name' => $this->name,
            'display_name' => $this->display_name,
            'attributes' => $this->attributeValues->map(fn ($value): array => [
                'id' => $value->attribute?->id,
                'code' => $value->attribute ? 'attribute_'.$value->attribute->id : null,
                'name' => $value->attribute?->name,
                'value' => [
                    'id' => $value->id,
                    'code' => 'attribute_value_'.$value->id,
                    'label' => $value->value,
                    'color' => $value->color,
                ],
            ])->values(),
            'images' => CatalogImageResource::collection($this->images),
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
            'is_active' => (bool) $this->is_active,
            'is_available' => (bool) $this->is_active
                && $availability->productIsActiveForCatalog($this->good)
                && $availableQuantity > 0,
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
