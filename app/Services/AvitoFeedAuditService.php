<?php

namespace App\Services;

use App\Models\Setting;
use RuntimeException;
use SimpleXMLElement;
use XMLReader;

class AvitoFeedAuditService
{
    private const RESERVED_TAGS = [
        'Id',
        'Title',
        'Description',
        'Category',
        'Price',
        'Address',
        'Stock',
        'Images',
        'Delivery',
        'FeedVersion',
    ];

    public function audit(string $filePath, array $options = []): array
    {
        $this->assertReadableFile($filePath);

        $query = trim((string) ($options['query'] ?? ''));
        $scope = ($options['scope'] ?? 'issues') === 'all' ? 'all' : 'issues';
        $issueFilter = trim((string) ($options['issue'] ?? ''));
        $limit = min(100, max(1, (int) ($options['limit'] ?? 50)));
        $reader = $this->openReader($filePath);
        $items = [];
        $seenIds = [];
        $summary = [
            'ads' => 0,
            'ads_with_issues' => 0,
            'critical_ads' => 0,
            'duplicate_ids' => 0,
            'duplicate_price_nodes' => 0,
            'missing_or_invalid_price' => 0,
            'low_price_ads' => 0,
            'min_price' => null,
            'max_price' => null,
        ];
        $root = [
            'name' => null,
            'target' => null,
            'format_version' => null,
        ];
        $matched = 0;

        try {
            while ($reader->read()) {
                if ($reader->nodeType !== XMLReader::ELEMENT) {
                    continue;
                }

                if ($root['name'] === null) {
                    $root = [
                        'name' => $reader->name,
                        'target' => $reader->getAttribute('target'),
                        'format_version' => $reader->getAttribute('formatVersion'),
                    ];
                }

                if ($reader->name !== 'Ad') {
                    continue;
                }

                $summary['ads']++;
                $item = $this->analyzeAdXml($reader->readOuterXml());
                $id = $item['id'];

                if ($id !== '') {
                    if (isset($seenIds[$id])) {
                        $item['issues'][] = $this->issue('duplicate_id', 'critical', 'ID объявления повторяется в файле');
                        $summary['duplicate_ids']++;
                    }
                    $seenIds[$id] = true;
                }

                $this->updateSummary($summary, $item);

                if (!$this->matchesResult($item, $query, $scope, $issueFilter)) {
                    continue;
                }

                $matched++;
                if (count($items) < $limit) {
                    $items[] = $item;
                }
            }
        } finally {
            $reader->close();
        }

        return [
            'file' => [
                'name' => basename($filePath),
                'size' => filesize($filePath) ?: 0,
                'updated_at' => date(DATE_ATOM, filemtime($filePath) ?: time()),
                'root' => $root,
            ],
            'summary' => $summary,
            'config_issues' => $this->getConfigurationIssues(),
            'items' => $items,
            'matched' => $matched,
            'truncated' => $matched > count($items),
        ];
    }

    public function findAd(string $filePath, string $id): ?array
    {
        $this->assertReadableFile($filePath);
        $reader = $this->openReader($filePath);

        try {
            while ($reader->read()) {
                if ($reader->nodeType !== XMLReader::ELEMENT || $reader->name !== 'Ad') {
                    continue;
                }

                $outerXml = $reader->readOuterXml();
                $item = $this->analyzeAdXml($outerXml);
                if ($item['id'] !== $id) {
                    continue;
                }

                $item['pretty_xml'] = $this->formatXml($outerXml);

                return $item;
            }
        } finally {
            $reader->close();
        }

        return null;
    }

    private function analyzeAdXml(string $outerXml): array
    {
        $previousErrors = libxml_use_internal_errors(true);

        try {
            $ad = simplexml_load_string($outerXml, SimpleXMLElement::class, LIBXML_NONET | LIBXML_COMPACT | LIBXML_PARSEHUGE);
            if (!$ad) {
                return [
                    'id' => '',
                    'title' => '',
                    'sku' => '',
                    'category' => '',
                    'prices' => [],
                    'price' => null,
                    'stock' => null,
                    'issues' => [$this->issue('invalid_ad_xml', 'critical', 'Не удалось разобрать XML объявления')],
                ];
            }

            $childCounts = [];
            foreach ($ad->children() as $child) {
                $name = $child->getName();
                $childCounts[$name] = ($childCounts[$name] ?? 0) + 1;
            }

            $prices = [];
            foreach ($ad->xpath('./Price') ?: [] as $priceNode) {
                $prices[] = trim((string) $priceNode);
            }

            $description = (string) ($ad->Description ?? '');
            $item = [
                'id' => trim((string) ($ad->Id ?? '')),
                'title' => trim((string) ($ad->Title ?? '')),
                'sku' => $this->extractSku($description),
                'category' => trim((string) ($ad->Category ?? '')),
                'prices' => $prices,
                'price' => $this->parsePrice($prices[0] ?? null),
                'stock' => $this->parseInteger((string) ($ad->Stock ?? '')),
                'issues' => [],
            ];

            foreach (['Id', 'Title', 'Description', 'Category', 'Address'] as $requiredTag) {
                if (trim((string) ($ad->{$requiredTag} ?? '')) === '') {
                    $item['issues'][] = $this->issue(
                        'missing_' . strtolower($requiredTag),
                        'critical',
                        "Отсутствует обязательный тег {$requiredTag}"
                    );
                }
            }

            if (!$prices || $item['price'] === null || $item['price'] <= 0) {
                $item['issues'][] = $this->issue('invalid_price', 'critical', 'Цена отсутствует, равна нулю или имеет неверный формат');
            } elseif ($item['price'] <= 10) {
                $item['issues'][] = $this->issue('low_price', 'critical', 'Подозрительно низкая цена: 10 ₽ или меньше');
            } elseif ($item['price'] < 100) {
                $item['issues'][] = $this->issue('low_price', 'warning', 'Подозрительно низкая цена: меньше 100 ₽');
            }

            foreach (self::RESERVED_TAGS as $tag) {
                if (($childCounts[$tag] ?? 0) > 1) {
                    $severity = $tag === 'Price' || $tag === 'Id' ? 'critical' : 'warning';
                    $item['issues'][] = $this->issue(
                        'duplicate_' . strtolower($tag),
                        $severity,
                        "Тег {$tag} повторяется " . $childCounts[$tag] . ' раза'
                    );
                }
            }

            foreach (array_keys($childCounts) as $tag) {
                if (strcasecmp($tag, 'Price') === 0 && $tag !== 'Price') {
                    $item['issues'][] = $this->issue('wrong_price_case', 'critical', "Использован тег {$tag} вместо Price");
                }
            }

            return $item;
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previousErrors);
        }
    }

    private function updateSummary(array &$summary, array $item): void
    {
        $issueCodes = array_column($item['issues'], 'code');
        if ($item['issues']) {
            $summary['ads_with_issues']++;
        }
        $hasCriticalIssue = false;
        foreach ($item['issues'] as $issue) {
            if ($issue['severity'] === 'critical') {
                $hasCriticalIssue = true;
                break;
            }
        }
        if ($hasCriticalIssue) {
            $summary['critical_ads']++;
        }
        if (in_array('duplicate_price', $issueCodes, true)) {
            $summary['duplicate_price_nodes']++;
        }
        if (in_array('invalid_price', $issueCodes, true) || in_array('wrong_price_case', $issueCodes, true)) {
            $summary['missing_or_invalid_price']++;
        }
        if (in_array('low_price', $issueCodes, true)) {
            $summary['low_price_ads']++;
        }

        if ($item['price'] !== null && $item['price'] > 0) {
            $summary['min_price'] = $summary['min_price'] === null
                ? $item['price']
                : min($summary['min_price'], $item['price']);
            $summary['max_price'] = $summary['max_price'] === null
                ? $item['price']
                : max($summary['max_price'], $item['price']);
        }
    }

    private function matchesResult(array $item, string $query, string $scope, string $issueFilter): bool
    {
        if ($query !== '') {
            $haystack = implode(' ', [$item['id'], $item['title'], $item['sku'], $item['category']]);
            if (mb_stripos($haystack, $query) === false) {
                return false;
            }
        } elseif ($scope === 'issues' && !$item['issues']) {
            return false;
        }

        if ($issueFilter !== '') {
            return in_array($issueFilter, array_column($item['issues'], 'code'), true);
        }

        return true;
    }

    private function getConfigurationIssues(): array
    {
        $issues = [];
        $reserved = array_map('strtolower', self::RESERVED_TAGS);
        $propertyMapping = $this->decodeSetting('avito_property_mapping');

        foreach ($propertyMapping as $propertyId => $tagName) {
            $tagName = trim((string) $tagName);
            if ($tagName !== '' && in_array(strtolower($tagName), $reserved, true)) {
                $issues[] = [
                    'code' => 'reserved_property_mapping',
                    'severity' => 'critical',
                    'message' => "Характеристика #{$propertyId} сопоставлена с системным тегом {$tagName}",
                ];
            }
        }

        foreach ($this->decodeSetting('avito_tags') as $tag) {
            if (!is_array($tag) || !($tag['apply'] ?? false)) {
                continue;
            }

            $type = $tag['type'] ?? 'value';
            $name = trim((string) ($tag['name'] ?? ''));
            if ($type !== 'complex' && $name !== '' && in_array(strtolower($name), $reserved, true)) {
                $issues[] = [
                    'code' => 'reserved_permanent_tag',
                    'severity' => 'critical',
                    'message' => "Постоянный тег {$name} дублирует системный тег фида",
                ];
            }

            if ($type === 'complex') {
                $complexXml = (string) ($tag['complex_xml'] ?? '');
                foreach (self::RESERVED_TAGS as $reservedTag) {
                    if (preg_match('/<\s*' . preg_quote($reservedTag, '/') . '\b/i', $complexXml)) {
                        $issues[] = [
                            'code' => 'reserved_complex_tag',
                            'severity' => 'critical',
                            'message' => "Сложный постоянный XML содержит системный тег {$reservedTag}",
                        ];
                    }
                }
            }
        }

        return $issues;
    }

    private function decodeSetting(string $key): array
    {
        $value = Setting::where('key', $key)->value('value');
        if (is_array($value)) {
            return $value;
        }

        $decoded = json_decode((string) $value, true);

        return is_array($decoded) ? $decoded : [];
    }

    private function openReader(string $filePath): XMLReader
    {
        $reader = new XMLReader();
        if (!$reader->open($filePath, null, LIBXML_NONET | LIBXML_COMPACT | LIBXML_PARSEHUGE)) {
            throw new RuntimeException('Не удалось открыть постоянный Avito XML для чтения.');
        }

        return $reader;
    }

    private function assertReadableFile(string $filePath): void
    {
        if (!is_file($filePath) || !is_readable($filePath)) {
            throw new RuntimeException('Постоянный файл exports/avito.xml не найден или недоступен для чтения.');
        }
    }

    private function parsePrice(?string $value): ?float
    {
        if ($value === null) {
            return null;
        }

        $normalized = str_replace([' ', ','], ['', '.'], trim($value));

        return is_numeric($normalized) ? (float) $normalized : null;
    }

    private function parseInteger(string $value): ?int
    {
        $value = trim($value);

        return $value !== '' && is_numeric($value) ? (int) $value : null;
    }

    private function extractSku(string $description): string
    {
        if (preg_match('/Артикул:\s*(?:<\/strong>)?\s*([^<\r\n]+)/ui', $description, $matches)) {
            return trim(html_entity_decode($matches[1], ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        }

        return '';
    }

    private function issue(string $code, string $severity, string $message): array
    {
        return compact('code', 'severity', 'message');
    }

    private function formatXml(string $xml): string
    {
        $document = new \DOMDocument('1.0', 'UTF-8');
        $document->preserveWhiteSpace = false;
        $document->formatOutput = true;

        return $document->loadXML($xml, LIBXML_NONET | LIBXML_PARSEHUGE)
            ? trim((string) $document->saveXML($document->documentElement))
            : $xml;
    }
}
