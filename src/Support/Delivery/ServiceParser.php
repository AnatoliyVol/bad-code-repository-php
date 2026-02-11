<?php

declare(strict_types=1);

namespace App\Support\Delivery;

use App\Support\Exceptions\ValidationException;
use DateTimeImmutable;

class ServiceParser {
    public function parseDate(?string $value, bool $allowNull = false): ?DateTimeImmutable
    {
        if ($value === null) {
            if ($allowNull) {
                return null;
            }
            return null;
        }
        $date = DateTimeImmutable::createFromFormat('Y-m-d', (string) $value);
        return $date ?: null;
    }

    public function parseTime(?string $value, string $field): ?DateTimeImmutable
    {
        if ($value === null) {
            return null;
        }
        $time = DateTimeImmutable::createFromFormat('H:i', $value);
        if (!$time) {
            $time = DateTimeImmutable::createFromFormat('H:i:s', $value);
        }
        if (!$time) {
            throw new ValidationException([$field => 'Неверный формат времени']);
        }
        return $time;
    }
}