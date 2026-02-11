<?php

declare(strict_types=1);

namespace App\Support\Delivery;

use App\Application\Service\OpenStreetMapService;
use App\Infrastructure\Repository\VehicleRepository;
use App\Infrastructure\Repository\DeliveryRepository;
use App\Support\Exceptions\ValidationException;
use App\Support\Delivery\ServiceParser;
use DateTimeImmutable;

class ServiceValidate {

    public function __construct(
        private ServiceParser $service_parser,
        private ServiceCalculator $service_calculator,
        private VehicleRepository $vehicles,
        private OpenStreetMapService $distanceService,
        private DeliveryRepository $deliveries,
    ) {
    }

    public function validatePayload(array $payload, bool $requireProducts = true): array
    {
        $errors = [];
        $courierId = (int) ($payload['courier_id'] ?? 0);
        $vehicleId = (int) ($payload['vehicle_id'] ?? 0);
        $deliveryDate = $this->service_parser->parseDate($payload['delivery_date'] ?? null);
        $timeStart = $this->service_parser->parseTime($payload['time_start'] ?? null, 'time_start');
        $timeEnd = $this->service_parser->parseTime($payload['time_end'] ?? null, 'time_end');
        $points = $payload['points'] ?? [];

        if ($courierId <= 0) {
            $errors['courier_id'] = 'ID курьера обязателен';
        }
        if ($vehicleId <= 0) {
            $errors['vehicle_id'] = 'ID машины обязателен';
        }
        if ($deliveryDate === null) {
            $errors['delivery_date'] = 'Некорректная дата доставки';
        } elseif ($deliveryDate < (new DateTimeImmutable('today'))) {
            $errors['delivery_date'] = 'Дата доставки не может быть в прошлом';
        }

        if ($timeStart === null) {
            $errors['time_start'] = 'Время начала обязательно';
        }
        if ($timeEnd === null) {
            $errors['time_end'] = 'Время окончания обязательно';
        }
        if ($timeStart && $timeEnd && $timeStart >= $timeEnd) {
            $errors['time_start'] = 'Время начала должно быть раньше времени окончания';
        }

        if (!is_array($points) || count($points) === 0) {
            $errors['points'] = 'Необходимо указать точки маршрута';
        }

        if ($errors) {
            throw new ValidationException($errors);
        }

        $normalizedPoints = [];
        foreach ($points as $index => $point) {
            $lat = isset($point['latitude']) ? (float) $point['latitude'] : null;
            $lon = isset($point['longitude']) ? (float) $point['longitude'] : null;
            if ($lat === null || $lon === null) {
                throw new ValidationException(['points' => 'Каждая точка должна содержать координаты']);
            }

            $products = $point['products'] ?? [];
            if ($requireProducts && (!is_array($products) || $products === [])) {
                throw new ValidationException(['products' => 'Для каждой точки необходимо указать товары']);
            }

            $normalizedProducts = [];
            foreach ($products as $product) {
                $productId = (int) ($product['product_id'] ?? 0);
                $quantity = (int) ($product['quantity'] ?? 0);
                if ($productId <= 0 || $quantity <= 0) {
                    throw new ValidationException(['products' => 'Неверные данные о товарах']);
                }
                $normalizedProducts[] = [
                    'product_id' => $productId,
                    'quantity' => $quantity,
                ];
            }

            $normalizedPoints[] = [
                'sequence' => $point['sequence'] ?? ($index + 1),
                'latitude' => $lat,
                'longitude' => $lon,
                'products' => $normalizedProducts,
            ];
        }

        return [
            'courier_id' => $courierId,
            'vehicle_id' => $vehicleId,
            'delivery_date' => $deliveryDate?->format('Y-m-d'),
            'time_start' => $timeStart?->format('H:i'),
            'time_end' => $timeEnd?->format('H:i'),
            'points' => $normalizedPoints,
        ];
    }

    public function validateVehicleCapacity(array $data, ?int $currentDeliveryId, $productCache): void
    {
        [$requestedWeight, $requestedVolume] = $this->service_calculator->calculateTotals($data['points'], $productCache);
        $vehicle = $this->vehicles->findById($data['vehicle_id']);
        $maxWeight = (float) $vehicle['max_weight'];
        $maxVolume = (float) $vehicle['max_volume'];

        $existingDeliveries = $this->deliveries->findByVehicleOverlapping(
            $data['delivery_date'],
            $data['vehicle_id'],
            $data['time_start'],
            $data['time_end']
        );

        if ($currentDeliveryId !== null) {
            $existingDeliveries = array_filter($existingDeliveries, fn(array $delivery) => (int) $delivery['id'] !== $currentDeliveryId);
        }

        $existingTotals = $this->service_calculator->calculateExistingTotals($existingDeliveries, $productCache);

        if ($requestedWeight + $existingTotals['weight'] > $maxWeight) {
            throw new ValidationException([
                'weight' => sprintf(
                    'Превышена грузоподъемность: требуется %.2f кг, доступно %.2f кг',
                    $requestedWeight + $existingTotals['weight'],
                    $maxWeight
                ),
            ]);
        }
        if ($requestedVolume + $existingTotals['volume'] > $maxVolume) {
            throw new ValidationException([
                'volume' => sprintf(
                    'Превышен объем: требуется %.3f м³, доступно %.3f м³',
                    $requestedVolume + $existingTotals['volume'],
                    $maxVolume
                ),
            ]);
        }
    }

    public function validateRouteTime(array $data): void
    {
        $points = $data['points'];
        $first = $points[0];
        $last = $points[count($points) - 1];

        $distance = $this->distanceService->calculateDistance(
            $first['latitude'],
            $first['longitude'],
            $last['latitude'],
            $last['longitude']
        );

        $speed = 60.0; // км/ч
        $travelMinutes = ($distance / $speed) * 60;
        $serviceTime = count($points) * 30; // по 30 минут на точку
        $requiredMinutes = (int) ceil($travelMinutes + $serviceTime);

        $start = DateTimeImmutable::createFromFormat('H:i', $data['time_start']);
        $end = DateTimeImmutable::createFromFormat('H:i', $data['time_end']);
        $available = $end->getTimestamp() - $start->getTimestamp();
        $availableMinutes = (int) round($available / 60);

        if ($requiredMinutes > $availableMinutes) {
            throw new ValidationException([
                'time' => sprintf(
                    'Недостаточно времени для маршрута: требуется %d мин, доступно %d мин',
                    $requiredMinutes,
                    $availableMinutes
                ),
            ]);
        }
    }
}