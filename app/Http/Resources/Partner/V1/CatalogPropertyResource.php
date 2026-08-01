<?php

namespace App\Http\Resources\Partner\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CatalogPropertyResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $valueId = $this->pivot?->shop_property_value_id;
        $value = $valueId ? $this->values->firstWhere('id', $valueId) : null;

        return [
            'id' => $this->id,
            'code' => $this->slug ?: 'property_'.$this->id,
            'name' => $this->name,
            'type' => $this->property_type,
            'value' => $value ? [
                'id' => $value->id,
                'code' => 'value_'.$value->id,
                'label' => $value->value,
                'color' => $value->color_hex,
            ] : null,
            'sort_order' => (int) $this->sort_order,
        ];
    }
}
