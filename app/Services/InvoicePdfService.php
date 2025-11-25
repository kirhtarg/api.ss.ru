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
        $totalSum = $data['total_amount'] ?? 0;
        $withVat = $data['with_vat'] ?? false;
        
        // Вычисляем сумму всех товаров
        $itemsTotal = 0;
        foreach ($orderItems as $item) {
            $itemsTotal += $item['total'] ?? ($item['quantity'] ?? 1) * ($item['price'] ?? 0);
        }
        
        // Вычисляем общую сумму, если не передана
        if ($totalSum == 0 && !empty($orderItems)) {
            $totalSum = $itemsTotal;
        }
        
        // Вычисляем скидку и доставку
        $discountAmount = 0;
        $deliveryAmount = 0;
        
        if ($itemsTotal > $totalSum) {
            // Есть скидка
            $discountAmount = $itemsTotal - $totalSum;
        } elseif ($totalSum > $itemsTotal) {
            // Есть доставка
            $deliveryAmount = $totalSum - $itemsTotal;
        }
        
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
        
        // Таблица товаров (с доставкой, если есть)
        $y = $this->fillItemsTable($pdf, $orderItems, $deliveryAmount, $y);
        
        // Итоги (со скидкой, если есть)
        $itemsCount = count($orderItems) + ($deliveryAmount > 0 ? 1 : 0);
        $y = $this->fillTotals($pdf, $itemsTotal, $totalSum, $discountAmount, $withVat, $itemsCount, $y);
        
        // Сумма прописью
        $y = $this->fillAmountInWords($pdf, $totalSum, $withVat, $y);
        
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
            ['Банк получателя', $settings['bank_name'] ?? ''],
            ['БИК', $settings['bik'] ?? ''],
            ['Сч. №', $settings['correspondent_account'] ?? ''],
            ['Банк получателя', $settings['bank_name'] ?? ''],
            ['ИНН', $settings['inn'] ?? ''],
            ['КПП', $settings['kpp'] ?? ''],
            ['Сч. №', $settings['account_number'] ?? ''],
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
        $pdf->Cell(0, 7, 'Получатель: ' . ($settings['legal_name'] ?? ''), 0, 1, 'L');
        
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
        if (isset($settings['legal_name'])) {
            $supplierText = $settings['legal_name'];
        }
        if (isset($settings['legal_address'])) {
            $supplierText .= ($supplierText ? ', ' : '') . $settings['legal_address'];
        } elseif (isset($data['main_address'])) {
            $supplierText .= ($supplierText ? ', ' : '') . $data['main_address'];
        }
        if (isset($settings['inn'])) {
            $supplierText .= ($supplierText ? ', ' : '') . 'ИНН: ' . $settings['inn'];
        }
        if (isset($settings['kpp']) && !empty($settings['kpp'])) {
            $supplierText .= ', КПП: ' . $settings['kpp'];
        }
        
        $pdf->SetFont('dejavusans', '', 10);
        $pdf->SetXY(15, $y + 7);
        $pdf->MultiCell(0, 6, $supplierText, 0, 'L');
        
        return $y + 7 + (substr_count($supplierText, "\n") + 1) * 6 + 5;
    }
    
    /**
     * Покупатель
     */
    private function fillCustomer(TCPDF $pdf, array $data, float $y): float
    {
        $pdf->SetFont('dejavusans', 'B', 10);
        $pdf->SetXY(15, $y);
        $pdf->Cell(0, 7, 'Покупатель:', 0, 1, 'L');
        
        $customerName = $data['customer_name'] ?? 'Покупатель';
        $customerInn = $data['customer_inn'] ?? '';
        $customerAddress = $data['customer_address'] ?? '';
        $customerPhone = $data['customer_phone'] ?? '';
        
        $customerText = $customerName;
        if ($customerAddress) {
            $customerText .= ', ' . $customerAddress;
        }
        if ($customerInn) {
            $customerText .= ', ИНН: ' . $customerInn;
        }
        if ($customerPhone) {
            $customerText .= ', тел: ' . $customerPhone;
        }
        
        $pdf->SetFont('dejavusans', '', 10);
        $pdf->SetXY(15, $y + 7);
        $pdf->MultiCell(0, 6, $customerText, 0, 'L');
        
        return $y + 7 + (substr_count($customerText, "\n") + 1) * 6 + 5;
    }
    
    /**
     * Таблица товаров
     */
    private function fillItemsTable(TCPDF $pdf, array $orderItems, float $deliveryAmount, float $y): float
    {
        $pdf->SetFont('dejavusans', 'B', 9);
        $pdf->SetFillColor(240, 240, 240);
        $pdf->SetTextColor(0, 0, 0);
        $pdf->SetDrawColor(0, 0, 0);
        $pdf->SetLineWidth(0.3);
        
        // Заголовки таблицы
        $colWidths = [15, 80, 20, 15, 25, 30];
        $rowHeight = 8;
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
        
        $itemIndex = 0;
        foreach ($orderItems as $item) {
            $x = 15;
            $row = $y;
            
            // №
            $pdf->SetXY($x, $row);
            $pdf->Cell($colWidths[0], $rowHeight, $itemIndex + 1, 1, 0, 'C', true);
            $x += $colWidths[0];
            
            // Наименование
            $goodName = $item['good_name'] ?? $item['name'] ?? 'Товар';
            $pdf->SetXY($x, $row);
            $pdf->Cell($colWidths[1], $rowHeight, $goodName, 1, 0, 'L', true);
            $x += $colWidths[1];
            
            // Кол-во
            $quantity = $item['quantity'] ?? 1;
            $pdf->SetXY($x, $row);
            $pdf->Cell($colWidths[2], $rowHeight, $quantity, 1, 0, 'C', true);
            $x += $colWidths[2];
            
            // Ед
            $unit = $item['unit'] ?? 'шт';
            $pdf->SetXY($x, $row);
            $pdf->Cell($colWidths[3], $rowHeight, $unit, 1, 0, 'C', true);
            $x += $colWidths[3];
            
            // Цена
            $price = $item['price'] ?? 0;
            $pdf->SetXY($x, $row);
            $pdf->Cell($colWidths[4], $rowHeight, number_format($price, 2, ',', ' '), 1, 0, 'R', true);
            $x += $colWidths[4];
            
            // Сумма
            $total = $item['total'] ?? ($quantity * $price);
            $pdf->SetXY($x, $row);
            $pdf->Cell($colWidths[5], $rowHeight, number_format($total, 2, ',', ' '), 1, 0, 'R', true);
            
            $y += $rowHeight;
            $itemIndex++;
        }
        
        // Добавляем строку доставки, если есть
        if ($deliveryAmount > 0) {
            $x = 15;
            $row = $y;
            
            // №
            $pdf->SetXY($x, $row);
            $pdf->Cell($colWidths[0], $rowHeight, $itemIndex + 1, 1, 0, 'C', true);
            $x += $colWidths[0];
            
            // Наименование
            $pdf->SetXY($x, $row);
            $pdf->Cell($colWidths[1], $rowHeight, 'Стоимость доставки', 1, 0, 'L', true);
            $x += $colWidths[1];
            
            // Кол-во
            $pdf->SetXY($x, $row);
            $pdf->Cell($colWidths[2], $rowHeight, '1', 1, 0, 'C', true);
            $x += $colWidths[2];
            
            // Ед
            $pdf->SetXY($x, $row);
            $pdf->Cell($colWidths[3], $rowHeight, 'шт', 1, 0, 'C', true);
            $x += $colWidths[3];
            
            // Цена
            $pdf->SetXY($x, $row);
            $pdf->Cell($colWidths[4], $rowHeight, number_format($deliveryAmount, 2, ',', ' '), 1, 0, 'R', true);
            $x += $colWidths[4];
            
            // Сумма
            $pdf->SetXY($x, $row);
            $pdf->Cell($colWidths[5], $rowHeight, number_format($deliveryAmount, 2, ',', ' '), 1, 0, 'R', true);
            
            $y += $rowHeight;
        }
        
        return $y + 5;
    }
    
    /**
     * Итоги
     */
    private function fillTotals(TCPDF $pdf, float $itemsTotal, float $totalSum, float $discountAmount, bool $withVat, int $itemsCount, float $y): float
    {
        $pdf->SetFont('dejavusans', '', 10);
        $colWidths = [15, 80, 20, 15, 25, 30];
        $rowHeight = 7;
        
        // Вычисляем начальную позицию для итогов (после колонок №, Наименование, Кол-во, Ед)
        $xLabel = 15 + array_sum(array_slice($colWidths, 0, 4)); // Начало колонки "Цена"
        $xValue = $xLabel + $colWidths[4]; // Начало колонки "Сумма"
        $labelWidth = $colWidths[4]; // Ширина для текста "Итого:" и т.д.
        $valueWidth = $colWidths[5]; // Ширина для суммы
        
        // Итого (сумма всех товаров)
        $pdf->SetXY($xLabel, $y);
        $pdf->Cell($labelWidth, $rowHeight, 'Итого:', 0, 0, 'R');
        $pdf->SetXY($xValue, $y);
        $pdf->Cell($valueWidth, $rowHeight, number_format($itemsTotal, 2, ',', ' '), 0, 0, 'R');
        $y += $rowHeight;
        
        // Скидка (если есть)
        if ($discountAmount > 0) {
            $pdf->SetXY($xLabel, $y);
            $pdf->Cell($labelWidth, $rowHeight, 'Скидка:', 0, 0, 'R');
            $pdf->SetXY($xValue, $y);
            $pdf->SetTextColor(255, 0, 0); // Красный цвет для скидки
            $pdf->Cell($valueWidth, $rowHeight, '-' . number_format($discountAmount, 2, ',', ' '), 0, 0, 'R');
            $pdf->SetTextColor(0, 0, 0); // Возвращаем черный цвет
            $y += $rowHeight;
        }
        
        // В том числе НДС (только если с НДС)
        if ($withVat) {
            $vatAmount = $totalSum * 0.20 / 1.20;
            $pdf->SetXY($xLabel, $y);
            $pdf->Cell($labelWidth, $rowHeight, 'В т.ч. НДС 20%:', 0, 0, 'R');
            $pdf->SetXY($xValue, $y);
            $pdf->Cell($valueWidth, $rowHeight, number_format($vatAmount, 2, ',', ' '), 0, 0, 'R');
            $y += $rowHeight;
        }
        
        // Всего к оплате
        $pdf->SetFont('dejavusans', 'B', 10);
        $pdf->SetXY($xLabel, $y);
        $pdf->Cell($labelWidth, $rowHeight, 'Всего к оплате:', 0, 0, 'R');
        $pdf->SetXY($xValue, $y);
        $pdf->Cell($valueWidth, $rowHeight, number_format($totalSum, 2, ',', ' '), 0, 0, 'R');
        $y += $rowHeight;
        
        // Всего наименований
        $pdf->SetFont('dejavusans', '', 10);
        $pdf->SetXY(15, $y);
        $pdf->Cell(0, 7, "Всего наименований {$itemsCount}, на сумму " . number_format($totalSum, 2, ',', ' ') . " руб.", 0, 1, 'L');
        
        return $y + 7;
    }
    
    /**
     * Сумма прописью
     */
    private function fillAmountInWords(TCPDF $pdf, float $totalSum, bool $withVat, float $y): float
    {
        $amountInWords = $this->numberToWords($totalSum);
        
        $pdf->SetFont('dejavusans', '', 10);
        $pdf->SetXY(15, $y);
        $pdf->MultiCell(0, 6, $amountInWords, 0, 'L');
        
        // Для ИП без НДС добавляем примечание
        if (!$withVat) {
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
        $number = (float)$number;
        $rubles = floor($number);
        $kopecks = round(($number - $rubles) * 100);
        
        $rublesWords = $this->num2str($rubles);
        $result = mb_ucfirst($rublesWords) . ' ' . $this->morph($rubles, 'рубль', 'рубля', 'рублей');
        
        if ($kopecks > 0) {
            $kopecksWords = $this->num2str($kopecks);
            $result .= ' ' . $kopecksWords . ' ' . $this->morph($kopecks, 'копейка', 'копейки', 'копеек');
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
        $number = (int)$number;
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
        
        return trim(preg_replace('/ {2,}/', ' ', join(' ', $out)));
    }
    
    private function processGroup($value, $unitIndex, $gender, $ten, $a20, $tens, $hundreds, $unit, &$out): void
    {
        if ($value == 0) {
            return;
        }
        
        list($i1, $i2, $i3) = [
            floor($value / 100),
            floor(($value % 100) / 10),
            $value % 10
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

