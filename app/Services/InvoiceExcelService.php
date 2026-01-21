<?php

namespace App\Services;

use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;

class InvoiceExcelService
{
    /**
     * Заполнить Excel-шаблон данными счета
     * 
     * @param string $templatePath Путь к шаблону Excel
     * @param array $data Данные для заполнения
     * @return Spreadsheet
     */
    public function fillTemplate(string $templatePath, array $data): Spreadsheet
    {
        // Загружаем шаблон
        $spreadsheet = IOFactory::load($templatePath);
        $worksheet = $spreadsheet->getActiveSheet();
        
        // Заполняем банковские реквизиты (строки 2-5)
        $this->fillBankDetails($worksheet, $data);
        
        // Заполняем получателя (строка 8)
        $this->fillRecipient($worksheet, $data);
        
        // Заполняем номер счета и дату (строка 10)
        $this->fillInvoiceHeader($worksheet, $data);
        
        // Заполняем поставщика (строка 12)
        $this->fillSupplier($worksheet, $data);
        
        // Заполняем покупателя (строка 14)
        $this->fillCustomer($worksheet, $data);
        
        // Заполняем товары (начиная со строки 17)
        $this->fillItems($worksheet, $data);
        
        // Заполняем итоги (строки 19-22)
        $this->fillTotals($worksheet, $data);
        
        // Заполняем подписи (строки 27-31)
        $this->fillSignatures($worksheet, $data);
        
        return $spreadsheet;
    }
    
    /**
     * Заполнить банковские реквизиты
     */
    private function fillBankDetails($worksheet, array $data): void
    {
        $settings = $data['settings'] ?? [];
        
        // Строка 2: Банк (B2) и БИК (значение после метки "БИК" в S2, вставляем в T2)
        if (isset($settings['bank_name'])) {
            $worksheet->setCellValue('B2', $settings['bank_name']);
        }
        if (isset($settings['bik'])) {
            $worksheet->setCellValue('T2', $settings['bik']); // После метки "БИК" в S2
        }
        
        // Строка 3: Корреспондентский счет (значение после метки "Сч. №" в S3, вставляем в T3)
        if (isset($settings['correspondent_account'])) {
            $worksheet->setCellValue('T3', $settings['correspondent_account']);
        }
        
        // Строка 4: Банк получателя (значение после метки "Банк получателя" в B4, вставляем в C4)
        if (isset($settings['bank_name'])) {
            $worksheet->setCellValue('C4', $settings['bank_name']);
        }
        
        // Строка 5: ИНН (значение после метки "ИНН" в B5, вставляем в C5), 
        // КПП (значение после метки "КПП" в J5, вставляем в K5),
        // Расчетный счет (значение после метки "Сч. №" в S5, вставляем в T5)
        if (isset($settings['inn'])) {
            $worksheet->setCellValue('C5', $settings['inn']);
        }
        if (isset($settings['kpp']) && !empty($settings['kpp'])) {
            $worksheet->setCellValue('K5', $settings['kpp']);
        }
        if (isset($settings['account_number'])) {
            $worksheet->setCellValue('T5', $settings['account_number']);
        }
    }
    
    /**
     * Заполнить получателя
     */
    private function fillRecipient($worksheet, array $data): void
    {
        $settings = $data['settings'] ?? [];
        
        // Строка 8: Получатель (значение после метки "Получатель" в B8, вставляем в C8)
        if (isset($settings['legal_name'])) {
            $worksheet->setCellValue('C8', $settings['legal_name']);
        }
    }
    
    /**
     * Заполнить заголовок счета (номер и дата)
     */
    private function fillInvoiceHeader($worksheet, array $data): void
    {
        $orderId = $data['order_id'] ?? 'TEST123';
        $date = $data['date'] ?? date('d.m.Y');
        
        // Строка 10: "Счет № {номер} от {дата} г."
        $invoiceText = "Счет № {$orderId} от {$date} г.";
        $worksheet->setCellValue('B10', $invoiceText);
    }
    
    /**
     * Заполнить поставщика
     */
    private function fillSupplier($worksheet, array $data): void
    {
        $settings = $data['settings'] ?? [];
        $contact = $data['contact'] ?? null;
        $mainAddress = $data['main_address'] ?? null;
        
        // Строка 12: Поставщик (значение после метки "Поставщик:" в B12, вставляем в C12)
        $supplierText = '';
        if (isset($settings['legal_name'])) {
            $supplierText = $settings['legal_name'];
        }
        if (isset($settings['legal_address'])) {
            $supplierText .= ($supplierText ? ', ' : '') . $settings['legal_address'];
        } elseif ($mainAddress) {
            $supplierText .= ($supplierText ? ', ' : '') . $mainAddress;
        }
        if (isset($settings['inn'])) {
            $supplierText .= ($supplierText ? ', ' : '') . 'ИНН: ' . $settings['inn'];
        }
        if (isset($settings['kpp']) && !empty($settings['kpp'])) {
            $supplierText .= ', КПП: ' . $settings['kpp'];
        }
        
        $worksheet->setCellValue('C12', $supplierText);
    }
    
    /**
     * Заполнить покупателя
     */
    private function fillCustomer($worksheet, array $data): void
    {
        $customerName = $data['customer_name'] ?? 'Покупатель';
        $customerInn = $data['customer_inn'] ?? '';
        $customerAddress = $data['customer_address'] ?? '';
        $customerPhone = $data['customer_phone'] ?? '';
        
        // Строка 14: Покупатель (значение после метки "Покупатель:" в B14, вставляем в C14)
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
        
        $worksheet->setCellValue('C14', $customerText);
    }
    
    /**
     * Заполнить товары
     */
    private function fillItems($worksheet, array $data): void
    {
        $orderItems = $data['order_items'] ?? [];
        $startRow = 17; // Начало данных товаров
        
        foreach ($orderItems as $index => $item) {
            $row = $startRow + $index;
            
            // № (столбец B)
            $worksheet->setCellValue('B' . $row, $index + 1);
            
            // Наименование (столбец D)
            $goodName = $item['good_name'] ?? $item['name'] ?? 'Товар';
            $worksheet->setCellValue('D' . $row, $goodName);
            
            // Кол-во (столбец V)
            $quantity = $item['quantity'] ?? 1;
            $worksheet->setCellValue('V' . $row, $quantity);
            
            // Ед (столбец X)
            $unit = $item['unit'] ?? 'шт';
            $worksheet->setCellValue('X' . $row, $unit);
            
            // Цена (столбец Z)
            $price = $item['price'] ?? 0;
            $worksheet->setCellValue('Z' . $row, number_format($price, 2, ',', ' '));
            
            // Сумма (столбец AC)
            $total = $item['total'] ?? ($quantity * $price);
            $worksheet->setCellValue('AC' . $row, number_format($total, 2, ',', ' '));
        }
    }
    
    /**
     * Заполнить итоги
     */
    private function fillTotals($worksheet, array $data): void
    {
        $orderItems = $data['order_items'] ?? [];
        $totalSum = $data['total_amount'] ?? 0;
        $withVat = $data['with_vat'] ?? false; // Для обратной совместимости
        // Получаем ставку НДС (приоритет у vat_rate, затем вычисляем из with_vat)
        $vatRate = 0;
        if (isset($data['vat_rate'])) {
            $vatRate = (int)$data['vat_rate'];
        } elseif ($withVat) {
            $vatRate = 20; // Для обратной совместимости: true = 20%
        }
        $withVat = $vatRate > 0; // Обновляем withVat на основе vatRate
        
        // Вычисляем общую сумму, если не передана
        if ($totalSum == 0 && !empty($orderItems)) {
            foreach ($orderItems as $item) {
                $totalSum += $item['total'] ?? ($item['quantity'] ?? 1) * ($item['price'] ?? 0);
            }
        }
        
        $itemsCount = count($orderItems);
        
        // Строка 19: Итого (метка "Итого:" в AC19, значение в AD19)
        $worksheet->setCellValue('AD19', number_format($totalSum, 2, ',', ' '));
        
        // Строка 20: В том числе НДС (только если с НДС, метка в AC20, значение в AD20)
        if ($withVat && $vatRate > 0) {
            $vatAmount = $totalSum * $vatRate / (100 + $vatRate); // НДС включен в цену
            $worksheet->setCellValue('AD20', number_format($vatAmount, 2, ',', ' '));
            // Обновляем метку с указанием ставки НДС (если возможно)
            // Метка находится в AC20, но обычно она статична в шаблоне
        } else {
            // Для ИП без НДС оставляем пустым
            $worksheet->setCellValue('AD20', '');
        }
        
        // Строка 21: Всего к оплате (метка "Всего к оплате:" в AC21, значение в AD21)
        $worksheet->setCellValue('AD21', number_format($totalSum, 2, ',', ' '));
        
        // Строка 22: Всего наименований (заменяем весь текст в B22)
        $amountInWords = $this->numberToWords($totalSum);
        $totalText = "Всего наименований {$itemsCount}, на сумму " . number_format($totalSum, 2, ',', ' ') . " руб.";
        $worksheet->setCellValue('B22', $totalText);
        
        // Добавляем сумму прописью на следующей строке
        $worksheet->setCellValue('B23', $amountInWords);
    }
    
    /**
     * Заполнить подписи
     */
    private function fillSignatures($worksheet, array $data): void
    {
        // Строка 27: Руководитель
        // Строка 28: подпись (H28), расшифровка (R28)
        // Строка 30: Бухгалтер
        // Строка 31: подпись (H31), расшифровка (R31)
        
        // Подписи оставляем пустыми, так как они должны быть физическими
        // Можно добавить имена руководителя и бухгалтера из настроек, если они есть
    }
    
    /**
     * Преобразование числа в пропись (используем существующую функцию из контроллера)
     */
    private function numberToWords($number): string
    {
        // Используем ту же логику, что и в TransferInvoiceController
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
    
    /**
     * Сохранить Excel файл
     */
    public function saveExcel(Spreadsheet $spreadsheet, string $filepath): void
    {
        $writer = new Xlsx($spreadsheet);
        $writer->save($filepath);
    }
    
    /**
     * Конвертировать Excel в PDF
     */
    public function convertToPdf(Spreadsheet $spreadsheet, string $filepath): void
    {
        // Пробуем использовать Tcpdf, если доступен
        if (class_exists('PhpOffice\PhpSpreadsheet\Writer\Pdf\Tcpdf')) {
            $writer = new \PhpOffice\PhpSpreadsheet\Writer\Pdf\Tcpdf($spreadsheet);
        } elseif (class_exists('PhpOffice\PhpSpreadsheet\Writer\Pdf\Mpdf')) {
            $writer = new \PhpOffice\PhpSpreadsheet\Writer\Pdf\Mpdf($spreadsheet);
        } elseif (class_exists('PhpOffice\PhpSpreadsheet\Writer\Pdf\Dompdf')) {
            $writer = new \PhpOffice\PhpSpreadsheet\Writer\Pdf\Dompdf($spreadsheet);
        } else {
            throw new \Exception('PDF writer не установлен. Установите один из: tecnickcom/tcpdf, mpdf/mpdf или dompdf/dompdf');
        }
        
        $writer->save($filepath);
    }
}

