<?php

namespace App\Services\Partner;

class PartnerCatalogContractService
{
    public function sourceStock(mixed $value, bool $local = false): int|string|null
    {
        if ($local) {
            return max(0, (int) $value);
        }

        if ($value === null || $value === '') {
            return null;
        }

        if (is_int($value) || (is_string($value) && preg_match('/^\d+$/', $value) === 1)) {
            return max(0, (int) $value);
        }

        return (string) $value;
    }

    /** @return array{is_preorder: bool, purchase_mode: string, can_order: bool, can_preorder: bool} */
    public function purchaseState(int $availableQuantity, bool $isPreorder, bool $isActive = true): array
    {
        if (! $isActive) {
            return ['is_preorder' => $isPreorder, 'purchase_mode' => 'unavailable', 'can_order' => false, 'can_preorder' => false];
        }

        if ($availableQuantity > 0) {
            return ['is_preorder' => $isPreorder, 'purchase_mode' => 'regular', 'can_order' => true, 'can_preorder' => false];
        }

        if ($isPreorder) {
            return ['is_preorder' => true, 'purchase_mode' => 'preorder', 'can_order' => false, 'can_preorder' => true];
        }

        return ['is_preorder' => false, 'purchase_mode' => 'unavailable', 'can_order' => false, 'can_preorder' => false];
    }

    public function storefrontUrl(?string $path): ?string
    {
        if ($path === null || $path === '') {
            return null;
        }
        if (preg_match('#^https?://#i', $path) === 1) {
            return $path;
        }

        $origin = rtrim((string) config('app.frontend_url'), '/');

        return $origin !== '' ? $origin.'/'.ltrim($path, '/') : null;
    }
}
