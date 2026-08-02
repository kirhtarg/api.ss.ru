<?php

namespace App\Http\Resources\Partner\V1;

use App\Services\Partner\PartnerCatalogContractService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class BrandResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => (int) $this->id,
            'name' => $this->name,
            'slug' => $this->slug,
            'logo_url' => app(PartnerCatalogContractService::class)->storefrontUrl($this->logo),
        ];
    }
}
