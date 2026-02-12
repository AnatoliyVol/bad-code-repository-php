<?php

declare(strict_types=1);

namespace App\Application\Service;

use App\Constants\Route as RouteConstants;
use App\Support\Exceptions\ValidationException;
use DateTimeImmutable;

class RouteService
{
    public function calculate(array $payload): array
    {
        $points = $payload['points'] ?? [];
        if (!is_array($points) || count($points) < 2) {
            throw new ValidationException(['points' => 'Маршрут должен содержать минимум две точки']);
        }

        $distance = 0.0;
        for ($i = 0; $i < count($points) - 1; $i++) {
            $distance += $this->distanceBetween($points[$i], $points[$i + 1]);
        }

        $averageSpeed = 30.0;
        $durationMinutes = (int) round(($distance / $averageSpeed) * RouteConstants::MINUTES_IN_ONE_HOUR);
        $durationMinutes = max($durationMinutes, RouteConstants::MINIMUM_FOR_DURATION_MINUTES);

        $bufferMinutes = (int) round($durationMinutes * RouteConstants::PART_OF_DURATION_MINUTES_FOR_BUFFER);
        $totalMinutes = $durationMinutes + $bufferMinutes;

        $start = (new DateTimeImmutable('09:00'))->format('H:i');
        $endTime = (new DateTimeImmutable('09:00'))->modify("+{$totalMinutes} minutes")->format('H:i');

        return [
            'distance_km' => round($distance, 2),
            'duration_minutes' => $totalMinutes,
            'suggested_time' => [
                'start' => $start,
                'end' => $endTime,
            ],
        ];
    }

    private function distanceBetween(array $a, array $b): float
    {
        $lat1 = (float) ($a['latitude'] ?? 0);
        $lon1 = (float) ($a['longitude'] ?? 0);
        $lat2 = (float) ($b['latitude'] ?? 0);
        $lon2 = (float) ($b['longitude'] ?? 0);

        $earthRadius = 6371;
        $dLat = deg2rad($lat2 - $lat1);
        $dLon = deg2rad($lon2 - $lon1);
        $lat1Rad = deg2rad($lat1);
        $lat2Rad = deg2rad($lat2);

        $a = sin($dLat / 2) ** 2 + cos($lat1Rad) * cos($lat2Rad) * sin($dLon / 2) ** 2;
        $c = 2 * atan2(sqrt($a), sqrt(1 - $a));
        return $earthRadius * $c;
    }
}