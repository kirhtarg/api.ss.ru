<?php

namespace Tests\Unit;

use App\Jobs\ProcessOzonSyncRunJob;
use PHPUnit\Framework\Attributes\Test;
use ReflectionMethod;
use Tests\TestCase;

class ProcessOzonSyncRunJobTest extends TestCase
{
    #[Test]
    public function it_reads_offer_errors_from_associative_ozon_result(): void
    {
        $response = [
            'result' => [
                'items' => [
                    ['offer_id' => 'g_10', 'errors' => []],
                    ['offer_id' => 'g_20', 'errors' => [['message' => 'Некорректная цена']]],
                ],
            ],
        ];

        $method = new ReflectionMethod(ProcessOzonSyncRunJob::class, 'responseErrorsForOffer');
        $errors = $method->invoke(new ProcessOzonSyncRunJob(1), $response, 'g_20');

        $this->assertSame([['message' => 'Некорректная цена']], $errors);
    }

    #[Test]
    public function it_reads_offer_errors_from_list_result(): void
    {
        $response = [
            'result' => [
                ['offer_id' => 'g_20', 'errors' => ['Цена отклонена']],
            ],
        ];

        $method = new ReflectionMethod(ProcessOzonSyncRunJob::class, 'responseErrorsForOffer');
        $errors = $method->invoke(new ProcessOzonSyncRunJob(1), $response, 'g_20');

        $this->assertSame(['Цена отклонена'], $errors);
    }

    #[Test]
    public function it_reads_offer_errors_from_single_result_object(): void
    {
        $response = [
            'result' => ['offer_id' => 'g_20', 'errors' => ['Оффер отклонён']],
        ];

        $method = new ReflectionMethod(ProcessOzonSyncRunJob::class, 'responseErrorsForOffer');
        $errors = $method->invoke(new ProcessOzonSyncRunJob(1), $response, 'g_20');

        $this->assertSame(['Оффер отклонён'], $errors);
    }

    #[Test]
    public function it_reports_when_ozon_does_not_return_the_requested_offer(): void
    {
        $response = ['result' => [['offer_id' => 'g_10', 'updated' => true, 'errors' => []]]];

        $method = new ReflectionMethod(ProcessOzonSyncRunJob::class, 'responseErrorsForOffer');
        $errors = $method->invoke(new ProcessOzonSyncRunJob(1), $response, 'g_20');

        $this->assertSame(
            [['message' => 'Ozon не вернул результат обновления для offer_id g_20.']],
            $errors,
        );
    }

    #[Test]
    public function it_reports_when_ozon_does_not_confirm_an_update(): void
    {
        $response = ['result' => [['offer_id' => 'g_20', 'updated' => false, 'errors' => []]]];

        $method = new ReflectionMethod(ProcessOzonSyncRunJob::class, 'responseErrorsForOffer');
        $errors = $method->invoke(new ProcessOzonSyncRunJob(1), $response, 'g_20');

        $this->assertSame(
            [['message' => 'Ozon не подтвердил обновление данных оффера.']],
            $errors,
        );
    }
}
