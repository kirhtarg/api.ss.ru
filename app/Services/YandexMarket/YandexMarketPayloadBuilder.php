<?php

namespace App\Services\YandexMarket;

use App\Models\ShopGood;
use App\Models\ShopGoodVariation;
use App\Models\ShopYandexMarketAccount;
use App\Models\ShopYandexMarketCategoryMapping;
use App\Models\ShopYandexMarketProductBinding;

class YandexMarketPayloadBuilder
{
    public function __construct(private readonly YandexMarketProductResolver $resolver) {}

    public function build(ShopGood $good, ?ShopGoodVariation $variation, ShopYandexMarketAccount $account, ?ShopYandexMarketCategoryMapping $mapping): array
    {
        $offerId = $this->offerId($good, $variation, $account);
        $price = $this->priceSummary($good, $variation, $mapping);
        $stock = $this->stockSummary($good, $variation);
        $media = $this->media($good, $variation, $account);
        $dimensions = $this->dimensions($good, $mapping);
        $parameters = $this->parameters($mapping, $good, $variation);
        $hasVariations = $good->variations->where('is_active', true)->count() > 1;
        $hasDimensions = collect($dimensions)->every(fn ($value) => $value > 0);

        $offer = [
            'offerId' => $offerId,
            'name' => $this->decoded($good->name),
            'marketCategoryId' => (int) ($mapping?->market_category_id ?? 0),
            'pictures' => $media['pictures'],
            'vendor' => $this->decoded($good->brands->first()?->name),
            'description' => $this->plainDescription($good->description ?: $good->short_description ?: $good->name),
            'weightDimensions' => $hasDimensions ? $dimensions : null,
            'parameterValues' => $parameters['payload'],
            'customsCommodityCode' => $parameters['customsCommodityCode'] ?? null,
            'basicPrice' => [
                'value' => $price['final'],
                'discountBase' => $price['old_price'] ?: null,
                'currencyId' => 'RUR',
            ],
        ];
        if ($hasVariations) $offer['groupId'] = 'g_'.$good->id;
        $offer = array_filter($offer, fn ($value) => $value !== null && $value !== '' && $value !== []);

        $errors = [];
        if (! $mapping) $errors[] = 'Не найден профиль категории Яндекс Маркета.';
        if (! $offer['marketCategoryId']) $errors[] = 'Не выбрана категория Яндекс Маркета.';
        if ($offer['name'] === '') $errors[] = 'Не заполнено название.';
        if ($offer['vendor'] === '') $errors[] = 'Не заполнен бренд.';
        if (empty($offer['pictures'])) $errors[] = 'Не добавлено изображение.';
        if ($price['final'] <= 0) $errors[] = 'Цена должна быть больше нуля.';
        foreach ($dimensions as $key => $value) if ($value <= 0) $errors[] = 'Не заполнены вес и габариты товара.';
        foreach ($parameters['missing'] as $name) $errors[] = "Не заполнена обязательная характеристика: {$name}.";

        return [
            'offer_id' => $offerId,
            'good_id' => (int) $good->id,
            'variation_id' => $variation?->id,
            'name' => $this->decoded($good->name),
            'sku' => $this->decoded($variation?->sku ?: $good->sku),
            'market_category' => $mapping?->market_category_name,
            'market_category_id' => $mapping?->market_category_id,
            'mapping_id' => $mapping?->id,
            'selection_tag_id' => $mapping?->selection_tag_id ?: $account->selection_tag_id,
            'selection_tag_name' => $mapping?->selectionTag?->name,
            'variation_attributes' => $this->resolver->variationSummary($variation),
            'display_parameters' => $parameters['display'],
            'price_summary' => $price,
            'stock_summary' => $stock,
            'stock' => $stock['total'],
            'dimensions_summary' => ['values' => $dimensions, 'sent' => $hasDimensions],
            'media_summary' => ['count' => count($media['pictures']), 'primary' => $media['pictures'][0] ?? null],
            'offer_mapping' => ['offer' => $offer],
            'errors' => array_values(array_unique($errors)),
        ];
    }

    public function pricePayload(ShopGood $good, ?ShopGoodVariation $variation, ShopYandexMarketAccount $account, ?ShopYandexMarketCategoryMapping $mapping): array
    {
        $price = $this->priceSummary($good, $variation, $mapping);
        return ['offerId' => $this->offerId($good, $variation, $account), 'price' => array_filter([
            'value' => $price['final'], 'discountBase' => $price['old_price'] ?: null, 'currencyId' => 'RUR',
        ], fn ($value) => $value !== null)];
    }

    public function stockPayload(ShopGood $good, ?ShopGoodVariation $variation, ShopYandexMarketAccount $account): array
    {
        return [
            'sku' => $this->offerId($good, $variation, $account),
            'items' => [['count' => $this->stockSummary($good, $variation)['total'], 'updatedAt' => now()->toIso8601String()]],
        ];
    }

    public function offerId(ShopGood $good, ?ShopGoodVariation $variation, ShopYandexMarketAccount $account): string
    {
        $binding = $account->exists ? ShopYandexMarketProductBinding::query()->where('account_id', $account->id)
            ->where('good_id', $good->id)
            ->when($variation, fn ($q) => $q->where('variation_id', $variation->id), fn ($q) => $q->whereNull('variation_id'))
            ->oldest('id')->first() : null;
        return (string) ($binding?->offer_id ?: ($variation ? "g_{$good->id}_v_{$variation->id}" : "g_{$good->id}"));
    }

    private function parameters(?ShopYandexMarketCategoryMapping $mapping, ShopGood $good, ?ShopGoodVariation $variation): array
    {
        $payload = []; $display = []; $missing = []; $customsCommodityCode = null;
        foreach ($mapping?->attribute_mappings ?? [] as $item) {
            $value = $this->resolver->sourceValue($item, $good, $variation);
            $dictionary = $this->dictionaryValue($item, (string) $value);
            $isEmpty = ($value === null || $value === '') && ! $dictionary;
            $name = (string) ($item['name'] ?? 'ID '.($item['id'] ?? ''));
            $isTnVed = (bool) preg_match('/тн\s*вэд|тнвэд/ui', $name);
            if ((($item['required'] ?? false) || $isTnVed) && $isEmpty) $missing[] = $name;
            if ($isTnVed && ! $isEmpty) {
                $code = preg_replace('/\D/u', '', (string) $value);
                if (! in_array(strlen($code), [10, 14], true)) $missing[] = "{$name} должен содержать 10 или 14 цифр без пробелов.";
                else $customsCommodityCode = $code;
            }
            if (! $isEmpty && ! empty($item['uses_dictionary']) && ! ($item['allow_custom_values'] ?? true) && ! $dictionary) {
                $missing[] = ($item['name'] ?? 'ID '.($item['id'] ?? '')).' — значение «'.$this->decoded($value).'» не сопоставлено со словарем Маркета';
                continue;
            }
            if ($isEmpty || (int) ($item['id'] ?? 0) <= 0) continue;

            if (! $isTnVed) {
                $parameter = ['parameterId' => (int) $item['id']];
                if ($dictionary) $parameter['valueId'] = (int) $dictionary['id'];
                else $parameter['value'] = is_bool($value) ? ($value ? 'true' : 'false') : (string) $value;
                if (! empty($item['unit_id'])) $parameter['unitId'] = (int) $item['unit_id'];
                $payload[] = $parameter;
            }
            $display[] = [
                'id' => (int) $item['id'], 'name' => $this->decoded($item['name'] ?? ''),
                'source_value' => $this->decoded($value), 'value' => $dictionary['label'] ?? $this->decoded($value),
                'value_id' => $dictionary['id'] ?? null, 'required' => (bool) ($item['required'] ?? false),
                'distinctive' => (bool) ($item['distinctive'] ?? false),
            ];
        }
        return compact('payload', 'display', 'missing', 'customsCommodityCode');
    }

    private function dictionaryValue(array $item, string $value): ?array
    {
        $needle = $this->normalized($value);
        foreach (($item['dictionary_map'] ?? []) as $local => $remote) {
            if ($this->normalized((string) $local) !== $needle) continue;
            return is_array($remote)
                ? ['id' => (int) ($remote['id'] ?? 0), 'label' => $this->decoded($remote['label'] ?? $value)]
                : ['id' => (int) $remote, 'label' => $this->decoded(($item['dictionary_labels'] ?? [])[$local] ?? $value)];
        }
        return null;
    }

    private function priceSummary(ShopGood $good, ?ShopGoodVariation $variation, ?ShopYandexMarketCategoryMapping $mapping): array
    {
        $item = $variation ?: $good;
        $base = $this->adjust((float) $item->price, $mapping?->price_adjustment);
        $sale = $this->adjust((float) $item->sale_price, $mapping?->price_adjustment);
        $dumping = $this->adjust((float) $item->demping_price, $mapping?->price_adjustment);
        $source = $item->show_demping && $dumping > 0 ? 'dumping' : ($sale > 0 && $sale < $base ? 'sale' : 'base');
        $final = $source === 'dumping' ? $dumping : ($source === 'sale' ? $sale : $base);
        return ['base' => $base, 'sale' => $sale ?: null, 'dumping' => $dumping ?: null, 'source' => $source, 'final' => $final, 'old_price' => $base > $final ? $base : null];
    }

    private function adjust(float $price, ?array $rule): float
    {
        if ($price <= 0) return 0;
        $value = max(0, (float) ($rule['value'] ?? 0));
        $delta = ($rule['type'] ?? 'percent') === 'absolute' ? $value : $price * $value / 100;
        return max(0, round(($rule['operation'] ?? 'add') === 'subtract' ? $price - $delta : $price + $delta, 2));
    }

    private function stockSummary(ShopGood $good, ?ShopGoodVariation $variation): array
    {
        $item = $variation ?: $good;
        $local = max(0, (int) $item->stock_quantity);
        $remote = $this->hasStock($item->remote_stock_quantity);
        $fast = $this->hasStock($item->fast_remote_stock_quantity);
        return ['local' => $local, 'remote_available' => $remote, 'fast_remote_available' => $fast, 'total' => $local + ($remote ? 10 : 0) + ($fast ? 10 : 0)];
    }

    private function hasStock(mixed $value): bool
    {
        if ($value === null || trim((string) $value) === '') return false;
        preg_match('/-?\d+(?:[.,]\d+)?/u', (string) $value, $match);
        return isset($match[0]) ? (float) str_replace(',', '.', $match[0]) > 0 : ! in_array(mb_strtolower(trim((string) $value)), ['нет', 'false', 'none', 'null'], true);
    }

    private function dimensions(ShopGood $good, ?ShopYandexMarketCategoryMapping $mapping): array
    {
        $settings = $mapping?->dimension_settings ?? [];
        return [
            'length' => round($this->positive($good->shipping_length, $good->depth) * (float) ($settings['length_multiplier'] ?? 1), 3),
            'width' => round($this->positive($good->shipping_width, $good->width) * (float) ($settings['width_multiplier'] ?? 1), 3),
            'height' => round($this->positive($good->shipping_height, $good->height) * (float) ($settings['height_multiplier'] ?? 1), 3),
            'weight' => round($this->positive($good->shipping_weight, $good->weight) * (float) ($settings['weight_multiplier'] ?? 1), 3),
        ];
    }

    private function media(ShopGood $good, ?ShopGoodVariation $variation, ShopYandexMarketAccount $account): array
    {
        $source = $variation && $variation->images->isNotEmpty() ? $variation->images : $good->images;
        $pictures = $source->sortBy(fn ($image) => sprintf('%d-%09d-%09d', $image->is_main ? 0 : 1, $image->sort_order, $image->id))
            ->map(fn ($image) => $this->imageUrl((string) $image->url, $account))->filter()->unique()->take(20)->values()->all();
        return compact('pictures');
    }

    private function imageUrl(string $url, ShopYandexMarketAccount $account): ?string
    {
        if ($url === '') return null;
        if (! preg_match('~^https?://~i', $url)) $url = rtrim((string) $account->image_base_url, '/').'/'.ltrim($url, '/');
        return filter_var($url, FILTER_VALIDATE_URL) ? $url : null;
    }

    private function positive(mixed ...$values): float { foreach ($values as $value) if (is_numeric($value) && (float) $value > 0) return (float) $value; return 0; }
    private function plainDescription(mixed $value): string { return trim(preg_replace('/\s+/u', ' ', strip_tags($this->decoded($value))) ?? ''); }
    private function normalized(mixed $value): string { return mb_strtolower(preg_replace('/\s+/u', ' ', $this->decoded($value)) ?? ''); }
    private function decoded(mixed $value): string { $value = trim((string) ($value ?? '')); for ($i = 0; $i < 5; $i++) { $next = html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8'); if ($next === $value) break; $value = $next; } return trim($value); }
}
