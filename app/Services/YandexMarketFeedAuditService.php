<?php

namespace App\Services;

class YandexMarketFeedAuditService
{
    private const RESULT_LIMIT = 100;

    public function audit(string $path, array $filters = []): array
    {
        if (! is_file($path)) {
            throw new \RuntimeException('Файл goods_feed.xml не найден. Сначала сформируйте YML-фид.');
        }

        $scope = in_array($filters['scope'] ?? 'issues', ['all', 'issues', 'clean'], true) ? $filters['scope'] : 'issues';
        $severity = $filters['severity'] ?? null;
        $query = mb_strtolower(trim((string) ($filters['query'] ?? '')));
        $reader = new \XMLReader();
        if (! $reader->open($path, null, LIBXML_NONET | LIBXML_COMPACT)) {
            throw new \RuntimeException('Не удалось открыть YML-фид.');
        }

        $summary = ['total' => 0, 'clean' => 0, 'with_issues' => 0, 'critical' => 0, 'warnings' => 0];
        $items = [];
        $matched = 0;

        try {
            while ($reader->read()) {
                if ($reader->nodeType !== \XMLReader::ELEMENT || $reader->name !== 'offer') {
                    continue;
                }

                $xml = $reader->readOuterXML();
                $offer = $xml !== '' ? @simplexml_load_string($xml) : false;
                if (! $offer) {
                    continue;
                }

                $item = $this->inspectOffer($offer);
                $summary['total']++;
                if ($item['issues']) {
                    $summary['with_issues']++;
                } else {
                    $summary['clean']++;
                }
                if (collect($item['issues'])->contains('severity', 'critical')) {
                    $summary['critical']++;
                }
                if (collect($item['issues'])->contains('severity', 'warning')) {
                    $summary['warnings']++;
                }

                if (! $this->matches($item, $scope, $severity, $query)) {
                    continue;
                }

                $matched++;
                if (count($items) < self::RESULT_LIMIT) {
                    $items[] = $item;
                }
            }
        } finally {
            $reader->close();
        }

        return [
            'file' => [
                'name' => basename($path),
                'size' => filesize($path) ?: 0,
                'updated_at' => date(DATE_ATOM, filemtime($path) ?: time()),
            ],
            'summary' => $summary,
            'matched' => $matched,
            'truncated' => $matched > count($items),
            'items' => $items,
        ];
    }

    private function inspectOffer(\SimpleXMLElement $offer): array
    {
        $id = trim((string) ($offer['id'] ?? ''));
        $name = trim((string) ($offer->name ?? ''));
        $issues = [];
        $critical = [
            'sku' => [$id, 'Не указан уникальный offer id (SKU).'],
            'name' => [$name, 'Не указано название товара.'],
            'url' => [trim((string) ($offer->url ?? '')), 'Не указана ссылка на товар.'],
            'price' => [(float) ($offer->price ?? 0) > 0 ? '1' : '', 'Цена отсутствует или равна нулю.'],
            'currency' => [trim((string) ($offer->currencyId ?? '')), 'Не указана валюта.'],
            'category' => [trim((string) ($offer->categoryId ?? '')), 'Не указана категория.'],
        ];
        foreach ($critical as $code => [$value, $message]) {
            if ($value === '') {
                $issues[] = compact('code', 'message') + ['severity' => 'critical'];
            }
        }

        $recommended = [
            'picture' => [isset($offer->picture), 'Нет изображения товара.'],
            'vendor' => [trim((string) ($offer->vendor ?? '')) !== '', 'Не указан бренд.'],
            'description' => [trim((string) ($offer->description ?? '')) !== '', 'Нет описания.'],
            'weight' => [(float) ($offer->weight ?? 0) > 0, 'Не указан вес.'],
            'dimensions' => [$this->validDimensions((string) ($offer->dimensions ?? '')), 'Не указаны все три габарита.'],
        ];
        foreach ($recommended as $code => [$valid, $message]) {
            if (! $valid) {
                $issues[] = compact('code', 'message') + ['severity' => 'warning'];
            }
        }

        return [
            'id' => $id,
            'name' => $name,
            'price' => trim((string) ($offer->price ?? '')),
            'stock' => max(0, (int) ($offer->count ?? 0)),
            'weight' => trim((string) ($offer->weight ?? '')),
            'dimensions' => trim((string) ($offer->dimensions ?? '')),
            'issues' => $issues,
        ];
    }

    private function validDimensions(string $value): bool
    {
        $parts = array_map('trim', explode('/', $value));

        return count($parts) === 3 && collect($parts)->every(fn ($part) => is_numeric($part) && (float) $part > 0);
    }

    private function matches(array $item, string $scope, ?string $severity, string $query): bool
    {
        if ($scope === 'clean' && $item['issues']) return false;
        if ($scope === 'issues' && ! $item['issues']) return false;
        if ($severity && ! collect($item['issues'])->contains('severity', $severity)) return false;
        if ($query !== '' && ! str_contains(mb_strtolower($item['id'].' '.$item['name']), $query)) return false;

        return true;
    }
}
