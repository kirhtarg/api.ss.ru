<?php

namespace App\Services;

use App\Models\ShopDescriptionDictionary;
use App\Models\ShopGood;
use App\Models\Shop\Property;
use Illuminate\Support\Str;

class ShopDescriptionFormatterService
{
    public const VERSION = 1;

    public function format(ShopGood $good): array
    {
        $source = trim((string) ($good->description ?? ''));
        $hash = hash('sha256', $source);
        $labels = $this->labelsFor($good);
        $lines = $this->extractLines($source);
        $rows = [];
        $narrative = [];

        foreach ($lines as $line) {
            $parts = $this->splitLineByLabels($line, $labels);
            if (count($parts) === 0) {
                $narrative[] = $line;
                continue;
            }

            foreach ($parts as $part) {
                if ($part['label'] !== '') {
                    $rows[] = $part;
                } elseif ($part['value'] !== '') {
                    $narrative[] = $part['value'];
                }
            }
        }

        $uniqueRows = [];
        foreach ($rows as $row) {
            $key = Str::lower($row['label']);
            if (! isset($uniqueRows[$key])) {
                $uniqueRows[$key] = $row;
            } else {
                $uniqueRows[$key]['value'] .= '; '.$row['value'];
            }
        }
        $rows = array_values($uniqueRows);

        $recognizedRatio = count($lines) > 0 ? count($rows) / count($lines) : 0;
        $status = count($rows) >= 2 ? 'formatted' : 'skipped';
        $html = $status === 'formatted' ? $this->render($narrative, $rows) : null;

        return [
            'html' => $html,
            'rows' => $rows,
            'hash' => $hash,
            'version' => self::VERSION,
            'status' => $status,
            'confidence' => round(min(1, (count($rows) / max(1, count($lines))) * 1.15), 3),
            'recognized_ratio' => round($recognizedRatio, 3),
        ];
    }

    public function formatAndPersist(ShopGood $good, bool $force = false): array
    {
        $source = (string) ($good->description ?? '');
        $hash = hash('sha256', trim($source));
        if (! $force && $good->description_format_manual && $good->formatted_description_html) {
            return ['status' => 'manual', 'html' => $good->formatted_description_html, 'hash' => $good->description_format_hash];
        }
        if (! $force && $good->description_format_hash === $hash && (int) $good->description_format_version === self::VERSION && $good->description_format_status !== 'pending') {
            return ['status' => 'cached', 'html' => $good->formatted_description_html, 'hash' => $hash];
        }

        $result = $this->format($good);
        $good->forceFill([
            'formatted_description_html' => $result['html'],
            'description_format_hash' => $result['hash'],
            'description_format_version' => self::VERSION,
            'description_format_status' => $result['status'],
            'description_format_manual' => false,
            'description_formatted_at' => now(),
        ])->saveQuietly();

        return $result;
    }

    private function labelsFor(ShopGood $good): array
    {
        $propertyLabels = Property::query()->whereNotNull('name')->pluck('name')->all();
        $categoryIds = $good->relationLoaded('categories')
            ? $good->categories->pluck('id')->all()
            : $good->categories()->pluck('shop_categories.id')->all();
        $custom = ShopDescriptionDictionary::query()
            ->where('is_active', true)
            ->where(function ($query) use ($categoryIds) {
                $query->whereNull('category_id');
                if ($categoryIds) {
                    $query->orWhereIn('category_id', $categoryIds);
                }
            })
            ->orderByDesc('priority')
            ->get(['name', 'aliases']);

        $labels = [];
        foreach ($propertyLabels as $label) {
            $labels[] = $this->normalizeLabel((string) $label);
        }
        foreach ($custom as $entry) {
            $labels[] = $this->normalizeLabel((string) $entry->name);
            foreach ((array) $entry->aliases as $alias) {
                $labels[] = $this->normalizeLabel((string) $alias);
            }
        }

        $labels = array_values(array_unique(array_filter($labels, fn ($label) => mb_strlen($label) >= 2)));
        usort($labels, fn ($a, $b) => mb_strlen($b) <=> mb_strlen($a));
        return $labels;
    }

    /**
     * Пользователь может сохранить название словаря уже с двоеточием
     * («Задний амортизатор:»). Знак является разделителем в тексте, а не
     * частью имени характеристики, поэтому убираем его перед построением
     * регулярного выражения.
     */
    private function normalizeLabel(string $label): string
    {
        $label = trim($label);
        return trim((string) preg_replace('/\s*[:：\-–—]+\s*$/u', '', $label));
    }

    private function extractLines(string $source): array
    {
        if ($source === '') return [];
        $text = html_entity_decode($source, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace('/<\s*(br|\/p|\/div|\/li|\/tr)\b[^>]*>/iu', "\n", $text) ?? $text;
        $text = strip_tags($text);
        $text = str_replace(["\r\n", "\r", "\xC2\xA0"], ["\n", "\n", ' '], $text);
        $lines = preg_split('/\n+/u', $text) ?: [];
        $result = [];
        foreach ($lines as $line) {
            $line = trim(preg_replace('/\s+/u', ' ', (string) $line));
            $line = preg_replace('/^\s*[-–—•·]+\s*/u', '', $line) ?? $line;
            if ($line !== '') $result[] = $line;
        }
        return $result;
    }

    private function splitLineByLabels(string $line, array $labels): array
    {
        $matches = [];
        foreach ($labels as $label) {
            $pattern = '/(?<!\pL)'.preg_quote($label, '/').'\s*(?::|[-–—])\s*/iu';
            if (preg_match_all($pattern, $line, $found, PREG_OFFSET_CAPTURE)) {
                foreach ($found[0] as $index => $match) {
                    $matches[] = ['offset' => $match[1], 'length' => strlen($match[0]), 'label' => $label];
                }
            }
        }
        if (! $matches) return [];
        usort($matches, fn ($a, $b) => $a['offset'] <=> $b['offset'] ?: $b['length'] <=> $a['length']);
        $filtered = [];
        $end = -1;
        foreach ($matches as $match) {
            if ($match['offset'] < $end) continue;
            $filtered[] = $match;
            $end = $match['offset'] + $match['length'];
        }

        $parts = [];
        foreach ($filtered as $index => $match) {
            $valueStart = $match['offset'] + $match['length'];
            $valueEnd = $filtered[$index + 1]['offset'] ?? strlen($line);
            $value = trim(substr($line, $valueStart, $valueEnd - $valueStart));
            $value = preg_replace('/^[\s:;,.\-–—•·]+/u', '', $value) ?? $value;
            if ($value !== '') $parts[] = ['label' => trim($match['label']), 'value' => $value];
        }
        return $parts;
    }

    private function render(array $narrative, array $rows): string
    {
        $escape = static fn ($value) => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $html = '';
        foreach ($narrative as $paragraph) {
            $html .= '<p>'.$escape($paragraph).'</p>';
        }
        $html .= '<div class="product-description-specs product-description-specs--auto">';
        foreach ($rows as $row) {
            $html .= '<div class="product-description-spec-row"><div class="product-description-spec-label">'.$escape($row['label']).'</div><div class="product-description-spec-value">'.$escape($row['value']).'</div></div>';
        }
        $html .= '</div>';
        return $html;
    }
}
