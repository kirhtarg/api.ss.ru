<?php

namespace App\Services\Partner;

use Illuminate\Database\Eloquent\Builder;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

class PartnerSyncCursor
{
    public function apply(Builder $query, ?string $cursor, ?string $updatedSince): Builder
    {
        if ($updatedSince) {
            $query->where('updated_at', '>=', $updatedSince);
        }

        if ($cursor) {
            [$updatedAt, $id] = $this->decode($cursor);
            $query->where(function (Builder $cursorQuery) use ($updatedAt, $id): void {
                $cursorQuery->where('updated_at', '>', $updatedAt)
                    ->orWhere(fn (Builder $sameTimestamp) => $sameTimestamp
                        ->where('updated_at', $updatedAt)
                        ->where('id', '>', $id));
            });
        }

        return $query->orderBy('updated_at')->orderBy('id');
    }

    public function encode(?object $model): ?string
    {
        if (! $model?->updated_at) {
            return null;
        }

        return rtrim(strtr(base64_encode(json_encode([
            $model->updated_at->format('Y-m-d H:i:s.u'),
            (int) $model->id,
        ], JSON_THROW_ON_ERROR)), '+/', '-_'), '=');
    }

    private function decode(string $cursor): array
    {
        $normalized = strtr($cursor, '-_', '+/');
        $normalized .= str_repeat('=', (4 - strlen($normalized) % 4) % 4);
        $decoded = base64_decode($normalized, true);
        $payload = $decoded !== false ? json_decode($decoded, true) : null;
        if (! is_array($payload) || count($payload) !== 2 || ! is_string($payload[0]) || ! is_numeric($payload[1])) {
            throw new UnprocessableEntityHttpException('Invalid synchronization cursor');
        }

        return [$payload[0], (int) $payload[1]];
    }
}
