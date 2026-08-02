<?php

namespace App\Services\Partner;

use App\Models\Promocode;
use App\Models\ShopBonusSettings;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

final class PartnerPromotionService
{
    public function apply(array $items, array $promotion = []): array
    {
        $customer = is_array($promotion['customer_reference'] ?? null) ? $promotion['customer_reference'] : [];
        $registered = ($customer['registration_status'] ?? null) === 'registered';
        $birthdayVerified = $registered && ($customer['birthday_status'] ?? null) === 'verified_today';
        $decisions = [];
        $registeredPercent = min(100, max(0, (float) ($promotion['registration_discount_percent'] ?? 5)));
        $adjusted = $this->applyCustomerDiscount($items, $registered, $birthdayVerified, $registeredPercent, $decisions);
        $beforePromo = round(array_sum(array_column($adjusted, 'total')), 2);
        $promoDiscount = $this->promoDiscount($promotion['promo_code'] ?? null, $adjusted, $beforePromo, $decisions);
        $afterPromo = max(0, round($beforePromo - $promoDiscount, 2));
        if ($promoDiscount > 0 && $beforePromo > 0) {
            $adjusted = $this->distributePromoDiscount($adjusted, $promoDiscount, $beforePromo);
            $afterPromo = round(array_sum(array_column($adjusted, 'total')), 2);
        }
        $bonus = $this->bonusPreview($promotion, $afterPromo, $adjusted, $registered, $decisions);

        Log::info('Partner promotion preview calculated', [
            'registered' => $registered,
            'birthday_verified' => $birthdayVerified,
            'registration_discount_percent' => $registeredPercent,
            'promo_code_hash' => empty($promotion['promo_code']) ? null : hash('sha256', mb_strtoupper(trim((string) $promotion['promo_code']))),
            'decision_codes' => array_column($decisions, 'code'),
            'total_before' => round(array_sum(array_column($items, 'total')), 2),
            'total_after' => $afterPromo,
        ]);

        return ['items' => $adjusted, 'discount_amount' => round(array_sum(array_map(fn ($item) => max(0, ($item['base_price'] - $item['final_price']) * $item['quantity']), $adjusted)), 2),
            'payable_subtotal' => $afterPromo, 'promo_discount_amount' => $promoDiscount, 'decisions' => $decisions, 'bonus' => $bonus];
    }

    private function applyCustomerDiscount(array $items, bool $registered, bool $birthday, float $registeredPercent, array &$decisions): array
    {
        if (! $registered) { $decisions[] = ['code' => 'registration_discount_not_applied', 'applied' => false, 'reason' => 'customer_not_registered']; return $items; }
        $percent = $birthday ? max(10, $registeredPercent) : $registeredPercent; $code = $birthday ? 'birthday_discount' : 'registered_customer_discount'; $applied = 0.0;
        if ($percent <= 0) { $decisions[] = ['code' => $code, 'applied' => false, 'reason' => 'disabled_by_partner_configuration', 'amount' => 0]; return $items; }
        foreach ($items as &$item) {
            if ((bool) data_get($item, 'tag_policy.disables_registered_discount', false)) {
                $item['promotion_eligibility']['registered_discount'] = false;
                continue;
            }
            if (! empty($item['discounts'])) continue;
            $discount = round($item['base_price'] * $percent / 100, 2);
            $item['final_price'] = $item['price'] = max(0.01, round($item['base_price'] - $discount, 2));
            $item['total'] = round($item['final_price'] * $item['quantity'], 2);
            $item['discounts'][$code] = ['percent' => $percent, 'amount' => $discount]; $applied += $discount * $item['quantity'];
        }
        $blocked = collect($items)->contains(fn ($item) => (bool) data_get($item, 'tag_policy.disables_registered_discount', false));
        $decisions[] = ['code' => $code, 'applied' => $applied > 0, 'reason' => $applied > 0 ? 'eligible' : ($blocked ? 'disabled_by_product_tag' : 'existing_item_discount_has_priority'), 'amount' => round($applied, 2)];
        return $items;
    }

    private function promoDiscount(mixed $rawCode, array $items, float $subtotal, array &$decisions): float
    {
        $code = mb_strtoupper(trim((string) $rawCode)); if ($code === '') return 0.0;
        if (! Schema::hasTable('promocodes')) { $decisions[] = ['code' => 'promo_code', 'applied' => false, 'reason' => 'promotion_service_unavailable']; return 0.0; }
        $promocode = Promocode::query()->whereRaw('UPPER(code) = ?', [$code])->first();
        if (! $promocode) { $decisions[] = ['code' => 'promo_code', 'applied' => false, 'reason' => 'not_found']; return 0.0; }
        $cart = array_map(fn ($item) => ['good_id' => $item['good_id'], 'variation_id' => $item['variation_id'], 'quantity' => $item['quantity'], 'price' => $item['final_price'], 'total' => $item['total']], $items);
        $result = $promocode->calculateDiscount($subtotal, $cart);
        $amount = ($result['success'] ?? false) ? round((float) ($result['discount'] ?? 0), 2) : 0.0;
        $decisions[] = ['code' => 'promo_code', 'applied' => $amount > 0, 'reason' => $amount > 0 ? 'eligible' : 'not_applicable', 'amount' => $amount];
        return $amount;
    }

    private function bonusPreview(array $promotion, float $subtotal, array $items, bool $registered, array &$decisions): array
    {
        $settings = Schema::hasTable('shop_bonus_settings') ? ShopBonusSettings::getDefaultSettings() : null;
        $earn = 0; $blockedLines = 0; $increasedLines = 0;
        if ($registered && $settings && $subtotal >= (float) $settings->min_order_amount) {
            foreach ($items as $item) {
                if ((bool) data_get($item, 'tag_policy.disables_bonuses', false)) { $blockedLines++; continue; }
                $percent = (float) data_get($item, 'tag_policy.increased_bonus_percent', 0);
                if ($percent > 0) $increasedLines++;
                else $percent = ! empty($item['discounts']) ? (float) $settings->sale_price_percentage : (float) $settings->regular_price_percentage;
                $earn += (float) $item['total'] * $percent / 100;
            }
            $earn = (int) \App\Helpers\PriceHelper::roundDiscount($earn);
        }
        if ($blockedLines > 0) $decisions[] = ['code' => 'partner_bonus_earn', 'applied' => $earn > 0, 'reason' => 'partially_disabled_by_product_tag', 'blocked_lines' => $blockedLines];
        elseif ($increasedLines > 0) $decisions[] = ['code' => 'partner_bonus_earn', 'applied' => $earn > 0, 'reason' => 'increased_by_product_tag', 'increased_lines' => $increasedLines];
        $requested = max(0, (int) ($promotion['partner_bonus_spend'] ?? 0));
        $spendReason = $requested > 0 ? 'verified_partner_bonus_account_required' : 'not_requested';
        if ($requested > 0) $decisions[] = ['code' => 'partner_bonus_spend', 'applied' => false, 'reason' => $spendReason];
        return ['earn_preview' => (int) $earn, 'spend_requested' => $requested, 'spend_applied' => 0, 'spend_available' => 0, 'spend_reason' => $spendReason, 'currency' => 'RUB',
            'tag_rules' => ['blocked_lines' => $blockedLines, 'increased_lines' => $increasedLines]];
    }

    private function distributePromoDiscount(array $items, float $discount, float $subtotal): array
    {
        $remaining = $discount; $last = array_key_last($items);
        foreach ($items as $index => &$item) {
            $share = $index === $last ? $remaining : min($remaining, round($discount * ($item['total'] / $subtotal), 2));
            $remaining = round($remaining - $share, 2);
            $unitDiscount = round($share / max(1, $item['quantity']), 2);
            $item['final_price'] = $item['price'] = max(0.01, round($item['final_price'] - $unitDiscount, 2));
            $item['total'] = round($item['final_price'] * $item['quantity'], 2);
            $item['discounts']['promo_code'] = ['amount' => $share];
        }
        return $items;
    }
}
