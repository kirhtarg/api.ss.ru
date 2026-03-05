<?php

namespace App\Http\Controllers;

use App\Models\ConstructorPage;

class PageController extends Controller
{
    /**
     * Отобразить страницу конструктора
     */
    public function show($slug)
    {
        $page = ConstructorPage::where('slug', $slug)
            ->published()
            ->firstOrFail();

        // Получаем структуру страницы
        $structure = $page->structure ?? [];

        // Проходим по структуре и подготавливаем данные для динамических блоков
        $structure = $this->prepareDynamicBlocks($structure);

        return view('page-builder.render', [
            'page' => $page,
            'structure' => $structure,
        ]);
    }

    /**
     * Подготовить данные для динамических блоков
     */
    private function prepareDynamicBlocks($structure)
    {
        if (! is_array($structure)) {
            return $structure;
        }

        foreach ($structure as &$row) {
            if (isset($row['columns']) && is_array($row['columns'])) {
                foreach ($row['columns'] as &$column) {
                    if (isset($column['blocks']) && is_array($column['blocks'])) {
                        foreach ($column['blocks'] as &$block) {
                            $block = $this->prepareBlockData($block);
                        }
                    }
                }
            }
        }

        return $structure;
    }

    /**
     * Подготовить данные для конкретного блока
     */
    private function prepareBlockData($block)
    {
        if (! isset($block['type'])) {
            return $block;
        }

        switch ($block['type']) {
            case 'dynamic_slider':
                $block = $this->prepareSliderBlock($block);
                break;
            case 'dynamic_goods_catalog':
                $block = $this->prepareGoodsCatalogBlock($block);
                break;
            case 'dynamic_bonus_system':
                $block = $this->prepareBonusBlock($block);
                break;
        }

        return $block;
    }

    /**
     * Подготовить блок слайдера
     */
    private function prepareSliderBlock($block)
    {
        $sliderId = $block['settings']['slider_id'] ?? null;

        if ($sliderId) {
            $slider = \App\Models\Slider::with('activeImages')->find($sliderId);
            if ($slider) {
                $block['slider_data'] = $slider;
            }
        }

        return $block;
    }

    /**
     * Подготовить блок каталога товаров
     */
    private function prepareGoodsCatalogBlock($block)
    {
        $settings = $block['settings'] ?? [];
        $query = \App\Models\ShopGood::with(['images', 'category', 'brand'])->active();

        // Фильтры
        if (! empty($settings['category_ids'])) {
            $query->whereIn('category_id', $settings['category_ids']);
        }

        if (! empty($settings['tags'])) {
            $query->whereHas('tags', function ($q) use ($settings) {
                $q->whereIn('name', $settings['tags']);
            });
        }

        // Сортировка
        $sortBy = $settings['sort_by'] ?? 'name';
        switch ($sortBy) {
            case 'price_asc':
                $query->orderBy('price', 'asc');
                break;
            case 'price_desc':
                $query->orderBy('price', 'desc');
                break;
            case 'rating':
                $query->orderBy('rating', 'desc');
                break;
            default:
                $query->orderBy('name', 'asc');
        }

        // Лимит
        $limit = $settings['limit'] ?? 12;
        $goods = $query->limit($limit)->get();

        $block['goods_data'] = $goods;

        return $block;
    }

    /**
     * Подготовить блок бонусной системы
     */
    private function prepareBonusBlock($block)
    {
        $user = auth()->user();

        if ($user) {
            $bonusSettings = \App\Models\ShopBonusSettings::getActiveSettings();

            $block['bonus_data'] = [
                'settings' => $bonusSettings,
                'user_balance' => $user->bonus_balance ?? 0,
                'user_level' => $this->calculateUserLevel($user->bonus_balance ?? 0),
            ];
        }

        return $block;
    }

    /**
     * Рассчитать уровень пользователя по бонусам
     */
    private function calculateUserLevel($balance)
    {
        // Логика расчета уровня пользователя
        // Это можно доработать в зависимости от требований
        if ($balance >= 10000) {
            return 'VIP';
        }
        if ($balance >= 5000) {
            return 'Gold';
        }
        if ($balance >= 1000) {
            return 'Silver';
        }

        return 'Bronze';
    }

    /**
     * Render row styles
     */
    private function renderRowStyles($row)
    {
        $settings = $row['settings'] ?? [];
        $styles = [];

        if (isset($settings['background_color'])) {
            $styles[] = "background-color: {$settings['background_color']}";
        }

        if (isset($settings['padding_top'])) {
            $styles[] = "padding-top: {$settings['padding_top']}";
        }

        if (isset($settings['padding_bottom'])) {
            $styles[] = "padding-bottom: {$settings['padding_bottom']}";
        }

        if (isset($settings['margin_bottom'])) {
            $styles[] = "margin-bottom: {$settings['margin_bottom']}";
        }

        if (isset($settings['max_width'])) {
            $styles[] = "max-width: {$settings['max_width']}";
            $styles[] = 'margin: 0 auto';
        }

        return implode('; ', $styles);
    }

    /**
     * Render row flex styles
     */
    private function renderRowFlexStyles($row)
    {
        $settings = $row['settings'] ?? [];
        $styles = [];

        if (isset($settings['alignment'])) {
            switch ($settings['alignment']) {
                case 'center':
                    $styles[] = 'justify-content: center';
                    break;
                case 'right':
                    $styles[] = 'justify-content: flex-end';
                    break;
                default:
                    $styles[] = 'justify-content: flex-start';
            }
        }

        return implode('; ', $styles);
    }

    /**
     * Render block content
     */
    private function renderBlock($block)
    {
        $type = $block['type'] ?? 'unknown';
        $settings = $block['settings'] ?? [];
        $content = $block['content'] ?? '';

        switch ($type) {
            case 'header':
                $level = $settings['level'] ?? 'h2';
                $style = $this->buildInlineStyles($settings, ['color', 'font_size', 'font_weight', 'text_align']);

                return "<{$level} style=\"{$style}\">{$content}</{$level}>";

            case 'text':
                $style = $this->buildInlineStyles($settings, ['color', 'font_size', 'line_height', 'text_align', 'max_width']);

                return "<div style=\"{$style}\">{$content}</div>";

            case 'image':
                $style = $this->buildInlineStyles($settings, ['width', 'height', 'object_fit']);
                $alt = $content['alt'] ?? '';
                $src = $content['src'] ?? '';

                return "<img src=\"{$src}\" alt=\"{$alt}\" style=\"{$style}\" />";

            case 'button':
                $style = $this->buildInlineStyles($settings, [
                    'background_color', 'color', 'font_size', 'padding',
                    'border_radius', 'border', 'cursor',
                ]);
                $link = $settings['link'] ?? '#';

                return "<a href=\"{$link}\" style=\"{$style}\">".(isset($content['text']) ? $content['text'] : 'Кнопка').'</a>';

            case 'spacer':
                $height = $settings['height'] ?? '40px';

                return "<div style=\"height: {$height}; width: 100%;\"></div>";

            case 'dynamic_slider':
                return $this->renderSliderBlock($block);

            case 'dynamic_goods_catalog':
                return $this->renderGoodsCatalogBlock($block);

            case 'dynamic_bonus_system':
                return $this->renderBonusBlock($block);

            default:
                return "<div>Неизвестный блок: {$type}</div>";
        }
    }

    /**
     * Render slider block
     */
    private function renderSliderBlock($block)
    {
        $sliderData = $block['slider_data'] ?? null;

        if (! $sliderData) {
            return '<div>Слайдер не найден</div>';
        }

        $html = '<div class="page-slider" data-settings="'.htmlspecialchars(json_encode($sliderData->settings)).'">';

        foreach ($sliderData->activeImages as $image) {
            $html .= '<div class="slide">';
            $html .= '<img src="'.$image->image_path.'" alt="'.htmlspecialchars($image->title ?? '').'" />';
            if ($image->title) {
                $html .= '<div class="slide-title">'.htmlspecialchars($image->title).'</div>';
            }
            if ($image->description) {
                $html .= '<div class="slide-description">'.htmlspecialchars($image->description).'</div>';
            }
            $html .= '</div>';
        }

        $html .= '</div>';

        return $html;
    }

    /**
     * Render goods catalog block
     */
    private function renderGoodsCatalogBlock($block)
    {
        $goods = $block['goods_data'] ?? [];

        $html = '<div class="goods-catalog">';
        $html .= '<div class="goods-grid" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 20px;">';

        foreach ($goods as $good) {
            $html .= '<div class="good-item" style="border: 1px solid #e5e7eb; border-radius: 8px; overflow: hidden;">';

            // Image
            $image = $good->images->first();
            if ($image) {
                $html .= '<img src="'.$image->path.'" alt="'.htmlspecialchars($good->name).'" style="width: 100%; height: 200px; object-fit: cover;" />';
            }

            // Content
            $html .= '<div style="padding: 16px;">';
            $html .= '<h3 style="font-size: 18px; font-weight: bold; margin-bottom: 8px;">'.htmlspecialchars($good->name).'</h3>';

            if ($good->price) {
                $html .= '<div style="font-size: 16px; color: #059669; font-weight: bold;">'.number_format($good->price, 0, ',', ' ').' ₽</div>';
            }

            $html .= '<a href="/goods/'.$good->id.'" style="display: inline-block; margin-top: 12px; padding: 8px 16px; background-color: #3b82f6; color: white; text-decoration: none; border-radius: 4px;">Подробнее</a>';
            $html .= '</div>';

            $html .= '</div>';
        }

        $html .= '</div>';
        $html .= '</div>';

        return $html;
    }

    /**
     * Render bonus block
     */
    private function renderBonusBlock($block)
    {
        $bonusData = $block['bonus_data'] ?? null;

        if (! $bonusData) {
            return '<div>Необходимо авторизоваться для просмотра бонусов</div>';
        }

        $html = '<div class="bonus-system" style="background-color: #f3f4f6; padding: 20px; border-radius: 8px;">';
        $html .= '<h3 style="font-size: 24px; font-weight: bold; margin-bottom: 16px;">Ваша бонусная программа</h3>';

        $html .= '<div style="display: flex; align-items: center; margin-bottom: 16px;">';
        $html .= '<div style="font-size: 18px; margin-right: 8px;">Уровень:</div>';
        $html .= '<div style="font-size: 18px; font-weight: bold; color: #059669;">'.$bonusData['user_level'].'</div>';
        $html .= '</div>';

        $html .= '<div style="display: flex; align-items: center;">';
        $html .= '<div style="font-size: 18px; margin-right: 8px;">Баланс:</div>';
        $html .= '<div style="font-size: 18px; font-weight: bold; color: #dc2626;">'.number_format($bonusData['user_balance'], 0, ',', ' ').' баллов</div>';
        $html .= '</div>';

        $html .= '</div>';

        return $html;
    }

    /**
     * Build inline styles from settings
     */
    private function buildInlineStyles($settings, $allowedKeys)
    {
        $styles = [];

        foreach ($allowedKeys as $key) {
            if (isset($settings[$key]) && ! empty($settings[$key])) {
                $cssKey = str_replace('_', '-', $key);

                // Add units for specific properties
                if (in_array($key, ['font_size', 'padding', 'margin_bottom', 'max_width', 'width', 'height', 'border_radius'])) {
                    $value = $settings[$key];
                    if (is_numeric($value) && ! str_contains($value, 'px')) {
                        $value .= 'px';
                    }
                    $styles[] = "{$cssKey}: {$value}";
                } else {
                    $styles[] = "{$cssKey}: {$settings[$key]}";
                }
            }
        }

        return implode('; ', $styles);
    }

    /**
     * Check if structure has slider blocks
     */
    private function hasSliderBlocks($structure)
    {
        if (! is_array($structure)) {
            return false;
        }

        foreach ($structure as $row) {
            if (isset($row['columns']) && is_array($row['columns'])) {
                foreach ($row['columns'] as $column) {
                    if (isset($column['blocks']) && is_array($column['blocks'])) {
                        foreach ($column['blocks'] as $block) {
                            if (($block['type'] ?? '') === 'dynamic_slider') {
                                return true;
                            }
                        }
                    }
                }
            }
        }

        return false;
    }
}
