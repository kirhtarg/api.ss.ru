<?php

namespace App\Services;

use TCPDF;

class InvoicePdfService
{
    /**
     * Генерация PDF счета
     */
    public function generatePdf(array $data): string
    {
        // Проверяем параметры наценки
        $overTax = $data['over_tax'] ?? 0;
        $overText = $data['over_text'] ?? '';
        $overtaxAmount = $data['overtax_amount'] ?? 0;

        // Если параметры переданы, но текст пустой - используем стандартный
        if ($overTax > 0 && empty($overText)) {
            $overText = 'Наценка';
        }

        // Перезаписываем параметры в данных
        $data['over_tax'] = $overTax;
        $data['over_text'] = $overText;
        $data['overtax_amount'] = $overtaxAmount;

        // Создаем PDF документ
        $pdf = new TCPDF(PDF_PAGE_ORIENTATION, PDF_UNIT, PDF_PAGE_FORMAT, true, 'UTF-8', false);

        // Устанавливаем метаданные документа
        $pdf->SetCreator('Skate and Snow');
        $pdf->SetAuthor('Skate and Snow');
        $pdf->SetTitle('Счет на оплату');
        $pdf->SetSubject('Счет на оплату');

        // Убираем заголовок и подвал по умолчанию
        $pdf->setPrintHeader(false);
        $pdf->setPrintFooter(false);

        // Устанавливаем отступы
        $pdf->SetMargins(15, 15, 15);
        $pdf->SetAutoPageBreak(true, 15);

        // Добавляем страницу
        $pdf->AddPage();

        // Устанавливаем шрифт
        $pdf->SetFont('dejavusans', '', 10);

        // Заполняем счет
        $this->fillInvoice($pdf, $data);

        // Возвращаем PDF как строку
        return $pdf->Output('', 'S');
    }

    /**
     * Заполнение счета
     */
    private function fillInvoice(TCPDF $pdf, array $data): void
    {
        $settings = $data['settings'] ?? [];
        $orderId = $data['order_id'] ?? 'TEST123';
        $date = $data['date'] ?? date('d.m.Y');
        $orderItems = $data['order_items'] ?? [];
        $withVat = $data['with_vat'] ?? false; // Для обратной совместимости
        // Получаем ставку НДС (приоритет у vat_rate, затем вычисляем из with_vat)
        $vatRate = 0;
        if (isset($data['vat_rate'])) {
            $vatRate = (int) $data['vat_rate'];
        } elseif ($withVat) {
            $vatRate = 20; // Для обратной совместимости: true = 20%
        }
        $withVat = $vatRate > 0; // Обновляем withVat на основе vatRate

        // Вычисляем базовую сумму товаров (по базовым ценам)
        $baseTotal = 0;
        foreach ($orderItems as $item) {
            $basePrice = isset($item['base_price']) && (float) $item['base_price'] > 0
                ? (float) $item['base_price']
                : (float) ($item['price'] ?? 0);
            $baseTotal += $basePrice * ($item['quantity'] ?? 1);
        }
        if ($baseTotal == 0 && ! empty($orderItems)) {
            foreach ($orderItems as $item) {
                $baseTotal += (float) ($item['total'] ?? (($item['quantity'] ?? 1) * ($item['price'] ?? 0)));
            }
        }

        // Получаем скидки
        $saleDiscount = (float) ($data['sale_discount_amount'] ?? 0);
        $registeredUserDiscount = (float) ($data['registered_user_discount_amount'] ?? 0);
        $promoCodeDiscount = (float) ($data['promo_code_discount_amount'] ?? 0);
        $bonusDiscount = (float) ($data['bonus_points_to_use'] ?? 0);
        $birthdayDiscount = (float) ($data['birthday_discount_amount'] ?? 0);
        $overTaxAmount = (float) ($data['overtax_amount'] ?? 0);
        $overText = $data['over_text'] ?? '';

        $y = 15;

        // Банковские реквизиты (таблица вверху)
        $y = $this->fillBankDetails($pdf, $settings, $y);

        // Получатель
        $y = $this->fillRecipient($pdf, $settings, $y);

        // Заголовок счета
        $y = $this->fillInvoiceHeader($pdf, $orderId, $date, $y);

        // Поставщик
        $y = $this->fillSupplier($pdf, $settings, $data, $y);

        // Покупатель
        $y = $this->fillCustomer($pdf, $data, $y);

        // В банковский счет включаются только товары. Доставка оплачивается отдельно.
        $y = $this->fillItemsTable($pdf, $orderItems, $y);

        // Итоги
        $itemsCount = count($orderItems);

        $y = $this->fillTotals(
            $pdf, $baseTotal, $withVat, $itemsCount, $y,
            $saleDiscount, $registeredUserDiscount, $promoCodeDiscount,
            $bonusDiscount, $birthdayDiscount, $overTaxAmount, $overText, $vatRate
        );

        // Итоговая сумма к оплате (база − все скидки + наценка)
        $amountInWordsFinalAmount = $baseTotal
            - $saleDiscount
            - $registeredUserDiscount
            - $promoCodeDiscount
            - $bonusDiscount
            - $birthdayDiscount
            + $overTaxAmount;

        $y = $this->fillAmountInWords($pdf, max(0, $amountInWordsFinalAmount), $withVat, $y, $vatRate);

        // Подписи
        $this->fillSignatures($pdf, $y, $withVat);
    }

    /**
     * Банковские реквизиты (таблица)
     */
    private function fillBankDetails(TCPDF $pdf, array $settings, float $y): float
    {
        $pdf->SetFont('dejavusans', 'B', 9);

        // Таблица банковских реквизитов
        $tableData = [
            ['Банк получателя', $this->cleanText($settings['bank_name'] ?? '')],
            ['БИК', $this->cleanText($settings['bik'] ?? '')],
            ['Корр. сч. №', $this->cleanText($settings['correspondent_account'] ?? '')],
            ['ИНН', $this->cleanText($settings['inn'] ?? '')],
            ['КПП', $this->cleanText($settings['kpp'] ?? '')],
            ['Сч. №', $this->cleanText($settings['account_number'] ?? '')],
        ];

        $pdf->SetFillColor(240, 240, 240);
        $pdf->SetTextColor(0, 0, 0);
        $pdf->SetDrawColor(0, 0, 0);
        $pdf->SetLineWidth(0.3);

        $colWidths = [60, 130];
        $rowHeight = 7;

        foreach ($tableData as $index => $row) {
            $x = 15;

            // Левая колонка (метка)
            $pdf->SetFont('dejavusans', 'B', 9);
            $pdf->SetXY($x, $y);
            $pdf->Cell($colWidths[0], $rowHeight, $row[0], 1, 0, 'L', true);

            // Правая колонка (значение)
            $pdf->SetFont('dejavusans', '', 9);
            $pdf->SetXY($x + $colWidths[0], $y);
            $pdf->Cell($colWidths[1], $rowHeight, $row[1], 1, 0, 'L', false);

            $y += $rowHeight;
        }

        return $y + 5;
    }

    /**
     * Получатель
     */
    private function fillRecipient(TCPDF $pdf, array $settings, float $y): float
    {
        $pdf->SetFont('dejavusans', 'B', 10);
        $pdf->SetXY(15, $y);
        $legalName = $this->cleanText($settings['legal_name'] ?? '');
        $pdf->Cell(0, 7, 'Получатель: '.$legalName, 0, 1, 'L');

        return $y + 7;
    }

    /**
     * Заголовок счета
     */
    private function fillInvoiceHeader(TCPDF $pdf, string $orderId, string $date, float $y): float
    {
        $pdf->SetFont('dejavusans', 'B', 14);
        $pdf->SetXY(15, $y);
        $pdf->Cell(0, 10, "Счет № {$orderId} от {$date} г.", 0, 1, 'L');

        return $y + 12;
    }

    /**
     * Поставщик
     */
    private function fillSupplier(TCPDF $pdf, array $settings, array $data, float $y): float
    {
        $pdf->SetFont('dejavusans', 'B', 10);
        $pdf->SetXY(15, $y);
        $pdf->Cell(0, 7, 'Поставщик:', 0, 1, 'L');

        $supplierText = '';

        // Получаем название организации
        if (isset($settings['legal_name']) && ! empty($settings['legal_name'])) {
            $supplierText = $this->cleanText($settings['legal_name']);
        }

        // Получаем адрес
        $address = '';
        if (isset($settings['legal_address']) && ! empty($settings['legal_address'])) {
            $address = $this->cleanText($settings['legal_address']);
        } elseif (isset($data['main_address'])) {
            // Если main_address - это объект ContactAddress, извлекаем адрес
            if (is_object($data['main_address'])) {
                $addressObj = $data['main_address'];
                // Пробуем получить address_short, если есть, иначе address
                $address = $this->cleanText($addressObj->address_short ?? $addressObj->address ?? '');
            } elseif (is_array($data['main_address'])) {
                // Если это массив, извлекаем адрес
                $address = $this->cleanText($data['main_address']['address_short'] ?? $data['main_address']['address'] ?? '');
            } else {
                // Если это строка, очищаем её
                $address = $this->cleanText($data['main_address']);
            }
        }

        if ($address) {
            $supplierText .= ($supplierText ? ', ' : '').$address;
        }

        // Добавляем ИНН
        if (isset($settings['inn']) && ! empty($settings['inn'])) {
            $supplierText .= ($supplierText ? ', ' : '').'ИНН: '.$settings['inn'];
        }

        // Добавляем КПП
        if (isset($settings['kpp']) && ! empty($settings['kpp'])) {
            $supplierText .= ', КПП: '.$settings['kpp'];
        }

        $pdf->SetFont('dejavusans', '', 10);
        $pdf->SetXY(15, $y + 7);
        $pdf->MultiCell(0, 6, $supplierText, 0, 'L');

        return $y + 7 + (substr_count($supplierText, "\n") + 1) * 6 + 5;
    }

    /**
     * Очистка текста от HTML тегов и Unicode escape-последовательностей
     */
    private function cleanText($text): string
    {
        if (empty($text)) {
            return '';
        }

        // Если это объект, извлекаем нужные свойства
        if (is_object($text)) {
            // Если это ContactAddress, получаем адрес
            if (method_exists($text, 'getAttribute')) {
                $text = $text->address_short ?? $text->address ?? (string) $text;
            } elseif (isset($text->address_short)) {
                $text = $text->address_short;
            } elseif (isset($text->address)) {
                $text = $text->address;
            } else {
                $text = (string) $text;
            }
        }

        // Если это массив, извлекаем адрес
        if (is_array($text)) {
            $text = $text['address_short'] ?? $text['address'] ?? '';
        }

        // Если это не строка, преобразуем в строку
        if (! is_string($text)) {
            $text = (string) $text;
        }

        // Проверяем, не является ли это JSON строкой с объектом
        if (is_string($text) && (strpos($text, '{') === 0 || strpos($text, '[') === 0)) {
            $decoded = json_decode($text, true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                $text = $decoded['address'] ?? $decoded['address_short'] ?? $text;
            }
        }

        // Декодируем Unicode escape-последовательности (\u0441 и т.д.)
        if (is_string($text) && preg_match('/\\\\u[0-9a-fA-F]{4}/', $text)) {
            // Пробуем декодировать через json_decode
            $decoded = json_decode('"'.str_replace('"', '\\"', $text).'"', true);
            if ($decoded !== null && $decoded !== $text) {
                $text = $decoded;
            } else {
                // Если не получилось, используем preg_replace_callback
                $text = preg_replace_callback('/\\\\u([0-9a-fA-F]{4})/', function ($match) {
                    return mb_convert_encoding(pack('H*', $match[1]), 'UTF-8', 'UCS-2BE');
                }, $text);
            }
        }

        // Удаляем HTML теги
        $text = strip_tags($text);

        // Декодируем HTML сущности
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        // Удаляем лишние пробелы и переносы строк
        $text = preg_replace('/\s+/', ' ', $text);
        $text = trim($text);

        return $text;
    }

    /**
     * Покупатель
     */
    private function fillCustomer(TCPDF $pdf, array $data, float $y): float
    {
        $pdf->SetFont('dejavusans', 'B', 10);
        $pdf->SetXY(15, $y);
        $pdf->Cell(0, 7, 'Покупатель:', 0, 1, 'L');

        $customerName = $this->cleanText($data['customer_name'] ?? 'Покупатель');
        $customerInn = $this->cleanText($data['customer_inn'] ?? '');
        $customerAddress = $this->cleanText($data['customer_address'] ?? '');
        $customerPhone = $this->cleanText($data['customer_phone'] ?? '');

        $customerText = $customerName;
        if ($customerAddress) {
            $customerText .= ', '.$customerAddress;
        }
        if ($customerInn) {
            $customerText .= ', ИНН: '.$customerInn;
        }
        if ($customerPhone) {
            $customerText .= ', тел: '.$customerPhone;
        }

        $pdf->SetFont('dejavusans', '', 10);
        $pdf->SetXY(15, $y + 7);
        $pdf->MultiCell(0, 6, $customerText, 0, 'L');

        return $y + 7 + (substr_count($customerText, "\n") + 1) * 6 + 5;
    }

    /**
     * Таблица товаров
     */
    private function fillItemsTable(TCPDF $pdf, array $orderItems, float $y): float
    {
        $pdf->SetFont('dejavusans', 'B', 9);
        $pdf->SetFillColor(240, 240, 240);
        $pdf->SetTextColor(0, 0, 0);
        $pdf->SetDrawColor(0, 0, 0);
        $pdf->SetLineWidth(0.3);

        // Заголовки таблицы
        $colWidths = [15, 80, 20, 15, 25, 30];
        $rowHeight = 8;
        $lineHeight = 4.5;
        $x = 15;

        $headers = ['№', 'Наименование работ, услуг', 'Кол-во', 'Ед', 'Цена', 'Сумма'];

        foreach ($headers as $index => $header) {
            $pdf->SetXY($x, $y);
            $pdf->Cell($colWidths[$index], $rowHeight, $header, 1, 0, 'C', true);
            $x += $colWidths[$index];
        }

        $y += $rowHeight;

        // Данные товаров
        $pdf->SetFont('dejavusans', '', 9);
        $pdf->SetFillColor(255, 255, 255);

        $maxCharsPerLine = 44; // Приблизительно для шрифта 9pt в ячейке 78мм

        $itemIndex = 0;
        foreach ($orderItems as $item) {
            $x = 15;
            $row = $y;

            // Получаем наименование, артикул и вариацию
            $goodName = $this->cleanText($item['good_name'] ?? $item['name'] ?? 'Товар');
            $goodSku = $this->cleanText($item['good_sku'] ?? $item['sku'] ?? '');
            $variationName = $this->cleanText($item['variation_name'] ?? '');

            // Разбиваем наименование на строки для расчёта высоты
            $nameLines = $this->wordWrap($goodName, $maxCharsPerLine);
            $totalLines = count($nameLines);
            if (! empty($goodSku)) {
                $totalLines++;
            }
            if (! empty($variationName)) {
                $totalLines++;
            }
            $actualRowHeight = max($rowHeight, $totalLines * $lineHeight + 2);

            // №
            $pdf->SetFont('dejavusans', '', 9);
            $pdf->SetXY($x, $row);
            $pdf->Cell($colWidths[0], $actualRowHeight, $itemIndex + 1, 1, 0, 'C', true);
            $x += $colWidths[0];

            // Наименование с артикулом и вариацией
            $nameX = $x;
            $nameY = $row;

            $pdf->SetDrawColor(0, 0, 0);
            $pdf->SetLineWidth(0.3);
            $pdf->SetFillColor(255, 255, 255);
            $pdf->Rect($nameX, $nameY, $colWidths[1], $actualRowHeight, 'DF');

            $currentTextY = $nameY + 1;

            // Название товара (многострочное если нужно)
            $pdf->SetFont('dejavusans', '', 9);
            foreach ($nameLines as $nameLine) {
                $pdf->SetXY($nameX + 1, $currentTextY);
                $pdf->Cell($colWidths[1] - 2, $lineHeight, $nameLine, 0, 0, 'L', false);
                $currentTextY += $lineHeight;
            }

            // Артикул
            if (! empty($goodSku)) {
                $pdf->SetFont('dejavusans', 'I', 8);
                $pdf->SetTextColor(80, 80, 80);
                $pdf->SetXY($nameX + 1, $currentTextY);
                $pdf->Cell($colWidths[1] - 2, $lineHeight, 'Арт.: '.$goodSku, 0, 0, 'L', false);
                $pdf->SetTextColor(0, 0, 0);
                $currentTextY += $lineHeight;
            }

            // Вариация (если есть)
            if (! empty($variationName)) {
                $pdf->SetFont('dejavusans', 'I', 8);
                $pdf->SetTextColor(100, 100, 100);
                $pdf->SetXY($nameX + 1, $currentTextY);
                $pdf->Cell($colWidths[1] - 2, $lineHeight, $variationName, 0, 0, 'L', false);
                $pdf->SetTextColor(0, 0, 0);
            }

            $x += $colWidths[1];

            // Кол-во
            $quantity = $item['quantity'] ?? 1;
            $pdf->SetFont('dejavusans', '', 9);
            $pdf->SetXY($x, $row);
            $pdf->Cell($colWidths[2], $actualRowHeight, $quantity, 1, 0, 'C', true);
            $x += $colWidths[2];

            // Ед
            $unit = $item['unit'] ?? 'шт';
            $pdf->SetXY($x, $row);
            $pdf->Cell($colWidths[3], $actualRowHeight, $unit, 1, 0, 'C', true);
            $x += $colWidths[3];

            // Цена (базовая цена за единицу)
            $basePrice = isset($item['base_price']) && (float) $item['base_price'] > 0
                ? (float) $item['base_price']
                : (float) ($item['price'] ?? 0);
            $pdf->SetXY($x, $row);
            $pdf->Cell($colWidths[4], $actualRowHeight, \App\Helpers\PriceHelper::formatPrice($basePrice), 1, 0, 'R', true);
            $x += $colWidths[4];

            // Сумма (базовая)
            $baseItemTotal = $basePrice * $quantity;
            $pdf->SetXY($x, $row);
            $pdf->Cell($colWidths[5], $actualRowHeight, \App\Helpers\PriceHelper::formatPrice($baseItemTotal), 1, 0, 'R', true);

            $y += $actualRowHeight;
            $itemIndex++;
        }

        return $y + 5;
    }

    /**
     * Разбивка текста на строки по максимальной длине
     */
    private function wordWrap(string $text, int $maxChars): array
    {
        if (mb_strlen($text) <= $maxChars) {
            return [$text];
        }

        $words = explode(' ', $text);
        $lines = [];
        $currentLine = '';

        foreach ($words as $word) {
            if (empty($currentLine)) {
                $currentLine = $word;
            } elseif (mb_strlen($currentLine.' '.$word) <= $maxChars) {
                $currentLine .= ' '.$word;
            } else {
                $lines[] = $currentLine;
                $currentLine = $word;
            }
        }

        if (! empty($currentLine)) {
            $lines[] = $currentLine;
        }

        return $lines ?: [$text];
    }

    /**
     * Итоги
     */
    private function fillTotals(
        TCPDF $pdf,
        float $baseTotal,
        bool $withVat,
        int $itemsCount,
        float $y,
        float $saleDiscount = 0,
        float $registeredUserDiscount = 0,
        float $promoCodeDiscount = 0,
        float $bonusDiscount = 0,
        float $birthdayDiscount = 0,
        float $overTaxAmount = 0,
        string $overText = '',
        int $vatRate = 0
    ): float {
        $pdf->SetFont('dejavusans', '', 10);
        $colWidths = [15, 80, 20, 15, 25, 30];
        $rowHeight = 7;

        // Лейбл охватывает всю ширину до колонки "Сумма" (чтобы длинные тексты влезали)
        $xValue = 15 + array_sum(array_slice($colWidths, 0, 5)); // начало колонки "Сумма" = 170
        $xLabel = 15;
        $labelWidth = $xValue - $xLabel; // 155мм, текст выравнивается по правому краю
        $valueWidth = $colWidths[5]; // 30мм

        // Сумма товаров (базовая)
        $pdf->SetXY($xLabel, $y);
        $pdf->Cell($labelWidth, $rowHeight, 'Сумма товаров (базовая):', 0, 0, 'R');
        $pdf->SetXY($xValue, $y);
        $pdf->Cell($valueWidth, $rowHeight, \App\Helpers\PriceHelper::formatPrice($baseTotal), 0, 0, 'R');
        $y += $rowHeight;

        // Наценка (если есть)
        if ($overTaxAmount > 0) {
            $displayText = ! empty($overText) ? $overText : 'Наценка';
            $pdf->SetXY($xLabel, $y);
            $pdf->Cell($labelWidth, $rowHeight, $displayText.':', 0, 0, 'R');
            $pdf->SetXY($xValue, $y);
            $pdf->SetTextColor(255, 165, 0);
            $pdf->Cell($valueWidth, $rowHeight, '+'.\App\Helpers\PriceHelper::formatPrice($overTaxAmount), 0, 0, 'R');
            $pdf->SetTextColor(0, 0, 0);
            $y += $rowHeight;
        }

        // Акционные скидки (если есть)
        if ($saleDiscount > 0) {
            $pdf->SetXY($xLabel, $y);
            $pdf->Cell($labelWidth, $rowHeight, 'Акционные скидки:', 0, 0, 'R');
            $pdf->SetXY($xValue, $y);
            $pdf->SetTextColor(255, 0, 0);
            $pdf->Cell($valueWidth, $rowHeight, '-'.\App\Helpers\PriceHelper::formatPrice($saleDiscount), 0, 0, 'R');
            $pdf->SetTextColor(0, 0, 0);
            $y += $rowHeight;
        }

        // Скидка для зарегистрированных (если есть)
        if ($registeredUserDiscount > 0) {
            $pdf->SetXY($xLabel, $y);
            $pdf->Cell($labelWidth, $rowHeight, 'Скидка для зарегистрированных:', 0, 0, 'R');
            $pdf->SetXY($xValue, $y);
            $pdf->SetTextColor(255, 0, 0);
            $pdf->Cell($valueWidth, $rowHeight, '-'.\App\Helpers\PriceHelper::formatPrice($registeredUserDiscount), 0, 0, 'R');
            $pdf->SetTextColor(0, 0, 0);
            $y += $rowHeight;
        }

        // Скидка по промокоду (если есть)
        if ($promoCodeDiscount > 0) {
            $pdf->SetXY($xLabel, $y);
            $pdf->Cell($labelWidth, $rowHeight, 'Скидка по промокоду:', 0, 0, 'R');
            $pdf->SetXY($xValue, $y);
            $pdf->SetTextColor(255, 0, 0);
            $pdf->Cell($valueWidth, $rowHeight, '-'.\App\Helpers\PriceHelper::formatPrice($promoCodeDiscount), 0, 0, 'R');
            $pdf->SetTextColor(0, 0, 0);
            $y += $rowHeight;
        }

        // Скидка по списанию бонусов (если есть)
        if ($bonusDiscount > 0) {
            $pdf->SetXY($xLabel, $y);
            $pdf->Cell($labelWidth, $rowHeight, 'Скидка по бонусам:', 0, 0, 'R');
            $pdf->SetXY($xValue, $y);
            $pdf->SetTextColor(255, 0, 0);
            $pdf->Cell($valueWidth, $rowHeight, '-'.\App\Helpers\PriceHelper::formatPrice($bonusDiscount), 0, 0, 'R');
            $pdf->SetTextColor(0, 0, 0);
            $y += $rowHeight;
        }

        // Скидка ко дню рождения (если есть)
        if ($birthdayDiscount > 0) {
            $pdf->SetXY($xLabel, $y);
            $pdf->Cell($labelWidth, $rowHeight, 'Скидка ко дню рождения:', 0, 0, 'R');
            $pdf->SetXY($xValue, $y);
            $pdf->SetTextColor(255, 0, 0);
            $pdf->Cell($valueWidth, $rowHeight, '-'.\App\Helpers\PriceHelper::formatPrice($birthdayDiscount), 0, 0, 'R');
            $pdf->SetTextColor(0, 0, 0);
            $y += $rowHeight;
        }

        // Итоговая сумма к оплате (без доставки, с учётом всех скидок и наценки)
        $finalAmount = $baseTotal
            + $overTaxAmount
            - $saleDiscount
            - $registeredUserDiscount
            - $promoCodeDiscount
            - $bonusDiscount
            - $birthdayDiscount;

        // В том числе НДС (только если с НДС)
        if ($withVat && $vatRate > 0) {
            $vatAmount = \App\Helpers\PriceHelper::roundPrice($finalAmount * $vatRate / (100 + $vatRate));
            $pdf->SetXY($xLabel, $y);
            $pdf->Cell($labelWidth, $rowHeight, "В т.ч. НДС {$vatRate}%:", 0, 0, 'R');
            $pdf->SetXY($xValue, $y);
            $pdf->Cell($valueWidth, $rowHeight, \App\Helpers\PriceHelper::formatPrice($vatAmount), 0, 0, 'R');
            $y += $rowHeight;
        }

        // Всего к оплате
        $pdf->SetFont('dejavusans', 'B', 10);
        $pdf->SetXY($xLabel, $y);
        $pdf->Cell($labelWidth, $rowHeight, 'Всего к оплате:', 0, 0, 'R');
        $pdf->SetXY($xValue, $y);
        $pdf->Cell($valueWidth, $rowHeight, \App\Helpers\PriceHelper::formatPrice($finalAmount), 0, 0, 'R');
        $y += $rowHeight;

        // Всего наименований
        $pdf->SetFont('dejavusans', '', 10);
        $pdf->SetXY(15, $y);
        $pdf->Cell(0, 7, "Всего наименований {$itemsCount}, на сумму ".\App\Helpers\PriceHelper::formatPrice($finalAmount).' руб.', 0, 1, 'L');

        return $y + 7;
    }

    /**
     * Сумма прописью
     */
    private function fillAmountInWords(TCPDF $pdf, float $totalSum, bool $withVat, float $y, int $vatRate = 20): float
    {
        $amountInWords = $this->numberToWords($totalSum);

        $pdf->SetFont('dejavusans', '', 10);
        $pdf->SetXY(15, $y);
        $pdf->MultiCell(0, 6, $amountInWords, 0, 'L');

        // Для ИП без НДС добавляем примечание
        if (! $withVat) {
            $pdf->SetFont('dejavusans', 'I', 9);
            $pdf->SetXY(15, $y + 12);
            $pdf->Cell(0, 6, 'НДС не облагается в связи с применением УСН на основании статьи 346.11 НК РФ.', 0, 1, 'L');

            return $y + 20;
        }

        return $y + 12;
    }

    /**
     * Подписи
     */
    private function fillSignatures(TCPDF $pdf, float $y, bool $withVat): void
    {
        $pdf->SetFont('dejavusans', '', 10);

        // Руководитель
        $pdf->SetXY(15, $y);
        $pdf->Cell(60, 7, 'Руководитель', 0, 0, 'L');
        $pdf->SetXY(80, $y);
        $pdf->Cell(40, 7, '_________________', 0, 0, 'L');
        $pdf->SetXY(130, $y);
        $pdf->Cell(60, 7, '_________________', 0, 1, 'L');

        $pdf->SetXY(80, $y + 7);
        $pdf->SetFont('dejavusans', '', 8);
        $pdf->Cell(40, 5, '(подпись)', 0, 0, 'L');
        $pdf->SetXY(130, $y + 7);
        $pdf->Cell(60, 5, '(расшифровка подписи)', 0, 1, 'L');

        // Бухгалтер (только если с НДС)
        if ($withVat) {
            $y += 20;
            $pdf->SetFont('dejavusans', '', 10);
            $pdf->SetXY(15, $y);
            $pdf->Cell(60, 7, 'Бухгалтер', 0, 0, 'L');
            $pdf->SetXY(80, $y);
            $pdf->Cell(40, 7, '_________________', 0, 0, 'L');
            $pdf->SetXY(130, $y);
            $pdf->Cell(60, 7, '_________________', 0, 1, 'L');

            $pdf->SetXY(80, $y + 7);
            $pdf->SetFont('dejavusans', '', 8);
            $pdf->Cell(40, 5, '(подпись)', 0, 0, 'L');
            $pdf->SetXY(130, $y + 7);
            $pdf->Cell(60, 5, '(расшифровка подписи)', 0, 1, 'L');
        }
    }

    /**
     * Преобразование числа в пропись
     */
    private function numberToWords($number): string
    {
        $number = (float) $number;
        $rubles = floor($number);
        $kopecks = round(($number - $rubles) * 100);

        $rublesWords = $this->num2str($rubles);
        $result = mb_ucfirst($rublesWords).' '.$this->morph($rubles, 'рубль', 'рубля', 'рублей');

        if ($kopecks > 0) {
            $kopecksWords = $this->num2str($kopecks);
            $result .= ' '.$kopecksWords.' '.$this->morph($kopecks, 'копейка', 'копейки', 'копеек');
        } else {
            $result .= ' 00 копеек';
        }

        return $result;
    }

    /**
     * Преобразование числа в пропись (русский язык)
     */
    private function num2str($number): string
    {
        $number = (int) $number;
        if ($number == 0) {
            return 'ноль';
        }

        $ten = [
            ['', 'один', 'два', 'три', 'четыре', 'пять', 'шесть', 'семь', 'восемь', 'девять'],
            ['', 'одна', 'две', 'три', 'четыре', 'пять', 'шесть', 'семь', 'восемь', 'девять'],
        ];
        $a20 = ['десять', 'одиннадцать', 'двенадцать', 'тринадцать', 'четырнадцать', 'пятнадцать', 'шестнадцать', 'семнадцать', 'восемнадцать', 'девятнадцать'];
        $tens = [2 => 'двадцать', 'тридцать', 'сорок', 'пятьдесят', 'шестьдесят', 'семьдесят', 'восемьдесят', 'девяносто'];
        $hundreds = ['', 'сто', 'двести', 'триста', 'четыреста', 'пятьсот', 'шестьсот', 'семьсот', 'восемьсот', 'девятьсот'];
        $unit = [
            ['', '', '', 0],
            ['тысяча', 'тысячи', 'тысяч', 1],
            ['миллион', 'миллиона', 'миллионов', 0],
            ['миллиард', 'миллиарда', 'миллиардов', 0],
        ];

        $out = [];

        $milliards = floor($number / 1000000000) % 1000;
        $millions = floor($number / 1000000) % 1000;
        $thousands = floor($number / 1000) % 1000;
        $units = $number % 1000;

        if ($milliards > 0) {
            $this->processGroup($milliards, 3, 0, $ten, $a20, $tens, $hundreds, $unit, $out);
        }

        if ($millions > 0) {
            $this->processGroup($millions, 2, 0, $ten, $a20, $tens, $hundreds, $unit, $out);
        }

        if ($thousands > 0) {
            $this->processGroup($thousands, 1, 1, $ten, $a20, $tens, $hundreds, $unit, $out);
        }

        if ($units > 0 || count($out) == 0) {
            $this->processGroup($units, 0, 0, $ten, $a20, $tens, $hundreds, $unit, $out);
        }

        return trim(preg_replace('/ {2,}/', ' ', implode(' ', $out)));
    }

    private function processGroup($value, $unitIndex, $gender, $ten, $a20, $tens, $hundreds, $unit, &$out): void
    {
        if ($value == 0) {
            return;
        }

        [$i1, $i2, $i3] = [
            floor($value / 100),
            floor(($value % 100) / 10),
            $value % 10,
        ];

        if ($i1 > 0) {
            $out[] = $hundreds[$i1];
        }

        if ($i2 > 1) {
            $out[] = $tens[$i2];
            if ($i3 > 0) {
                $out[] = $ten[$gender][$i3];
            }
        } elseif ($i2 == 1) {
            $out[] = $a20[$i3];
        } elseif ($i3 > 0) {
            $out[] = $ten[$gender][$i3];
        }

        if ($unitIndex > 0 && isset($unit[$unitIndex])) {
            $out[] = $this->morph($value, $unit[$unitIndex][0], $unit[$unitIndex][1], $unit[$unitIndex][2]);
        }
    }

    private function morph($n, $f1, $f2, $f5): string
    {
        $n = abs($n) % 100;
        $n1 = $n % 10;
        if ($n > 10 && $n < 20) {
            return $f5;
        }
        if ($n1 > 1 && $n1 < 5) {
            return $f2;
        }
        if ($n1 == 1) {
            return $f1;
        }

        return $f5;
    }
}
