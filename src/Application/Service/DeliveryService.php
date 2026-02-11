<?php

declare(strict_types=1);

namespace App\Application\Service;

use App\Domain\ValueObject\DeliveryStatus;
use App\Domain\ValueObject\UserRole;
use App\Infrastructure\Repository\DeliveryPointProductRepository;
use App\Infrastructure\Repository\DeliveryPointRepository;
use App\Infrastructure\Repository\DeliveryRepository;
use App\Infrastructure\Repository\ProductRepository;
use App\Infrastructure\Repository\UserRepository;
use App\Infrastructure\Repository\VehicleRepository;
use App\Support\Exceptions\NotFoundException;
use App\Support\Exceptions\ValidationException;
use DateInterval;
use DateTimeImmutable;
use PDO;
use Throwable;
class DeliveryService
{
    private array $productCache = [];

    public function __construct(
        private PDO $pdo,
        private DeliveryRepository $deliveries,
        private DeliveryPointRepository $points,
        private DeliveryPointProductRepository $pointProducts,
        private UserRepository $users,
        private VehicleRepository $vehicles,
        private ProductRepository $products,
        private OpenStreetMapService $distanceService
    ) {
    }

    public function list(array $filters): array
    {
        $date = $this->parseDate($filters['date'] ?? null, allowNull: true);
        $courierId = isset($filters['courier_id']) ? (int) $filters['courier_id'] : null;
        $status = $this->sanitizeStatus($filters['status'] ?? null);

        $rows = $this->deliveries->findByFilters($date?->format('Y-m-d'), $courierId, $status);
        return $this->presentRawDeliveries($rows);
    }

    public function get(int $id): array
    {
        $delivery = $this->deliveries->findById($id);
        if (!$delivery) {
            throw new NotFoundException('Delivery not found');
        }

        $results = $this->presentRawDeliveries([$delivery]);
        return $results[0];
    }

    public function create(array $payload, array $currentUser): array
    {
        $this->productCache = [];
        $data = $this->validatePayload($payload);
        $data['created_by'] = (int) $currentUser['id'];
        return $this->persistDelivery($data, null);
    }

    public function update(int $id, array $payload): array
    {
        $this->productCache = [];
        $existing = $this->deliveries->findById($id);
        if (!$existing) {
            throw new NotFoundException('Delivery not found');
        }

        $this->assertEditable($existing['delivery_date']);

        $data = $this->validatePayload($payload);
        $data['created_by'] = (int) $existing['created_by'];
        return $this->persistDelivery($data, (int) $existing['id']);
    }

    public function delete(int $id): void
    {
        $this->productCache = [];
        $delivery = $this->deliveries->findById($id);
        if (!$delivery) {
            throw new NotFoundException('Delivery not found');
        }

        $this->assertEditable($delivery['delivery_date']);

        $this->removePoints((int) $delivery['id']);
        $this->deliveries->delete((int) $delivery['id']);
    }

    public function generate(array $payload, array $currentUser): array
    {
        $this->productCache = [];
        $data = $payload['delivery_data'] ?? null;
        if (!is_array($data) || $data === []) {
            throw new ValidationException(['delivery_data' => 'Необходимо передать данные для генерации']);
        }

        $couriers = $this->users->findAll(UserRole::COURIER);
        $vehicles = $this->vehicles->findAll();

        $resultByDate = [];
        $total = 0;

        foreach ($data as $dateString => $routes) {
            $dateObject = $this->parseDate((string) $dateString);
            if ($dateObject === null) {
                $resultByDate[$dateString] = [
                    'generated_count' => 0,
                    'deliveries' => [],
                    'warnings' => ['Некорректная дата: ' . $dateString],
                ];
                continue;
            }
            $date = $dateObject->format('Y-m-d');

            if (!is_array($routes) || $routes === []) {
                $resultByDate[$date] = [
                    'generated_count' => 0,
                    'deliveries' => [],
                    'warnings' => ['Нет маршрутов для генерации'],
                ];
                continue;
            }

            $warnings = [];
            $created = [];

            if (empty($couriers)) {
                $warnings[] = 'Нет доступных курьеров';
            }
            if (empty($vehicles)) {
                $warnings[] = 'Нет доступных машин';
            }

            foreach ($routes as $index => $routeData) {
                if (empty($couriers) || empty($vehicles)) {
                    break;
                }

                if (!is_array($routeData['route'] ?? null) || count($routeData['route']) < 1) {
                    $warnings[] = 'Маршрут #' . ($index + 1) . ' пропущен: нет точек';
                    continue;
                }
                if (!is_array($routeData['products'] ?? null) || count($routeData['products']) === 0) {
                    $warnings[] = 'Маршрут #' . ($index + 1) . ' пропущен: нет товаров';
                    continue;
                }

                $courier = $couriers[$index % count($couriers)];
                $vehicle = $vehicles[$index % count($vehicles)];

                $points = [];
                foreach ($routeData['route'] as $seq => $point) {
                    $points[] = [
                        'sequence' => $seq + 1,
                        'latitude' => (float) ($point['latitude'] ?? 0),
                        'longitude' => (float) ($point['longitude'] ?? 0),
                        'products' => array_map(static function (array $prod): array {
                            return [
                                'product_id' => (int) ($prod['product_id'] ?? 0),
                                'quantity' => (int) ($prod['quantity'] ?? 0),
                            ];
                        }, $routeData['products']),
                    ];
                }

                $requestPayload = [
                    'courier_id' => (int) $courier['id'],
                    'vehicle_id' => (int) $vehicle['id'],
                    'delivery_date' => $date,
                    'time_start' => (new DateTimeImmutable('09:00'))->add(new DateInterval('PT' . $index . 'H'))->format('H:i'),
                    'time_end' => '18:00',
                    'points' => $points,
                ];

                try {
                    $normalized = $this->validatePayload($requestPayload, false);
                    $normalized['created_by'] = (int) $currentUser['id'];
                    $delivery = $this->persistDelivery($normalized, null);
                    $created[] = $delivery;
                    $total++;
                } catch (ValidationException $exception) {
                    $warnings[] = 'Маршрут #' . ($index + 1) . ': ' . $exception->getMessage();
                }
            }

            $resultByDate[$date] = [
                'generated_count' => count($created),
                'deliveries' => $created,
                'warnings' => $warnings ?: null,
            ];
        }

        return [
            'total_generated' => $total,
            'by_date' => $resultByDate,
        ];
    }

    private function persistDelivery(array $data, ?int $deliveryId): array
    {
        $courier = $this->users->findById($data['courier_id']);
        if (!$courier || $courier['role'] !== UserRole::COURIER) {
            throw new ValidationException(['courier_id' => 'Курьер не найден или роль некорректна']);
        }

        $vehicle = $this->vehicles->findById($data['vehicle_id']);
        if (!$vehicle) {
            throw new ValidationException(['vehicle_id' => 'Машина не найдена']);
        }

        $this->validateVehicleCapacity($data, $deliveryId);
        if (count($data['points']) >= 2) {
            $this->validateRouteTime($data);
        }

        $this->pdo->beginTransaction();
        try {
            if ($deliveryId === null) {
                $deliveryRow = $this->deliveries->create([
                    'courier_id' => $data['courier_id'],
                    'vehicle_id' => $data['vehicle_id'],
                    'created_by' => $data['created_by'],
                    'delivery_date' => $data['delivery_date'],
                    'time_start' => $data['time_start'],
                    'time_end' => $data['time_end'],
                    'status' => DeliveryStatus::PLANNED,
                    'created_at' => (new DateTimeImmutable())->format(DATE_ATOM),
                    'updated_at' => (new DateTimeImmutable())->format(DATE_ATOM),
                ]);
            } else {
                $deliveryRow = $this->deliveries->update($deliveryId, [
                    'courier_id' => $data['courier_id'],
                    'vehicle_id' => $data['vehicle_id'],
                    'delivery_date' => $data['delivery_date'],
                    'time_start' => $data['time_start'],
                    'time_end' => $data['time_end'],
                    'updated_at' => (new DateTimeImmutable())->format(DATE_ATOM),
                ]);
                $this->removePoints($deliveryId);
            }

            foreach ($data['points'] as $index => $point) {
                $pointRow = $this->points->create([
                    'delivery_id' => $deliveryRow['id'],
                    'sequence' => $point['sequence'] ?? ($index + 1),
                    'latitude' => $point['latitude'],
                    'longitude' => $point['longitude'],
                ]);

                foreach ($point['products'] as $product) {
                    $this->pointProducts->create([
                        'delivery_point_id' => $pointRow['id'],
                        'product_id' => $product['product_id'],
                        'quantity' => $product['quantity'],
                    ]);
                }
            }

            $this->pdo->commit();
        } catch (Throwable $exception) {
            $this->pdo->rollBack();
            throw $exception;
        }

        return $this->get((int) $deliveryRow['id']);
    }

    private function validatePayload(array $payload, bool $requireProducts = true): array
    {
        $errors = [];
        $courierId = (int) ($payload['courier_id'] ?? 0);
        $vehicleId = (int) ($payload['vehicle_id'] ?? 0);
        $deliveryDate = $this->parseDate($payload['delivery_date'] ?? null);
        $timeStart = $this->parseTime($payload['time_start'] ?? null, 'time_start');
        $timeEnd = $this->parseTime($payload['time_end'] ?? null, 'time_end');
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

    private function validateVehicleCapacity(array $data, ?int $currentDeliveryId): void
    {
        [$requestedWeight, $requestedVolume] = $this->calculateTotals($data['points']);
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

        $existingTotals = $this->calculateExistingTotals($existingDeliveries);

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

    private function calculateTotals(array $points): array
    {
        $totalWeight = 0.0;
        $totalVolume = 0.0;
        foreach ($points as $point) {
            foreach ($point['products'] as $product) {
                $entity = $this->requireProduct($product['product_id']);
                $quantity = $product['quantity'];
                $totalWeight += (float) $entity['weight'] * $quantity;
                $totalVolume += $this->calculateProductVolume($entity) * $quantity;
            }
        }
        return [$totalWeight, $totalVolume];
    }

    private function calculateExistingTotals(array $deliveries): array
    {
        if ($deliveries === []) {
            return ['weight' => 0.0, 'volume' => 0.0];
        }
        $ids = array_column($deliveries, 'id');
        $points = $this->points->findByDeliveryIds($ids);
        $pointIds = array_column($points, 'id');
        $products = $this->pointProducts->findByDeliveryPointIds($pointIds);
        $totals = ['weight' => 0.0, 'volume' => 0.0];
        foreach ($products as $product) {
            $entity = $this->requireProduct((int) $product['product_id']);
            $quantity = (int) $product['quantity'];
            $totals['weight'] += (float) $entity['weight'] * $quantity;
            $totals['volume'] += $this->calculateProductVolume($entity) * $quantity;
        }
        return $totals;
    }

    private function validateRouteTime(array $data): void
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

    private function removePoints(int $deliveryId): void
    {
        $points = $this->points->findByDelivery($deliveryId);
        foreach ($points as $point) {
            $this->pointProducts->deleteByDeliveryPoint((int) $point['id']);
        }
        $this->points->deleteByDelivery($deliveryId);
    }

    private function hydrateDeliveries(array $deliveries): array
    {
        if ($deliveries === []) {
            return [];
        }

        $ids = array_map(static fn(array $delivery) => (int) $delivery['id'], $deliveries);
        $points = $this->points->findByDeliveryIds($ids);
        $pointIds = array_column($points, 'id');
        $products = $this->pointProducts->findByDeliveryPointIds($pointIds);

        $productsByPoint = [];
        foreach ($products as $product) {
            $productsByPoint[(int) $product['delivery_point_id']][] = $product;
        }

        $pointsByDelivery = [];
        foreach ($points as $point) {
            $pointId = (int) $point['id'];
            $deliveryId = (int) $point['delivery_id'];
            $pointsByDelivery[$deliveryId][] = $this->formatPoint($point, $productsByPoint[$pointId] ?? []);
        }

        $userIds = [];
        $vehicleIds = [];
        foreach ($deliveries as $delivery) {
            if ($delivery['courier_id']) {
                $userIds[] = (int) $delivery['courier_id'];
            }
            $userIds[] = (int) $delivery['created_by'];
            if ($delivery['vehicle_id']) {
                $vehicleIds[] = (int) $delivery['vehicle_id'];
            }
        }

        $users = $this->users->findManyByIds(array_unique($userIds));
        $vehicles = $this->vehicles->findManyByIds(array_unique($vehicleIds));

        $now = new DateTimeImmutable('today');
        $results = [];
        foreach ($deliveries as $delivery) {
            $deliveryId = (int) $delivery['id'];
            $deliveryPoints = $pointsByDelivery[$deliveryId] ?? [];
            [$weight, $volume] = $this->calculateTotalsForResponse($deliveryPoints);
            $deliveryDate = new DateTimeImmutable($delivery['delivery_date']);
            $canEdit = $deliveryDate > $now->add(new DateInterval('P3D'));

            $results[] = [
                'id' => $deliveryId,
                'delivery_number' => sprintf('DEL-%s-%03d', substr($delivery['delivery_date'], 0, 4), $deliveryId),
                'courier' => $this->formatUser($users[(int) ($delivery['courier_id'] ?? 0)] ?? null),
                'vehicle' => $this->formatVehicle($vehicles[(int) ($delivery['vehicle_id'] ?? 0)] ?? null),
                'created_by' => $this->formatUser($users[(int) $delivery['created_by']] ?? null),
                'delivery_date' => $delivery['delivery_date'],
                'time_start' => $delivery['time_start'],
                'time_end' => $delivery['time_end'],
                'status' => $delivery['status'],
                'created_at' => $delivery['created_at'],
                'updated_at' => $delivery['updated_at'],
                'delivery_points' => $deliveryPoints,
                'total_weight' => round($weight, 2),
                'total_volume' => round($volume, 3),
                'can_edit' => $canEdit,
            ];
        }

        return $results;
    }

    public function presentRawDeliveries(array $deliveries): array
    {
        $this->productCache = [];
        return $this->hydrateDeliveries($deliveries);
    }

    private function calculateTotalsForResponse(array $points): array
    {
        $weight = 0.0;
        $volume = 0.0;
        foreach ($points as $point) {
            foreach ($point['products'] as $product) {
                $weight += $product['product']['weight'] * $product['quantity'];
                $volume += $product['product']['volume'] * $product['quantity'];
            }
        }
        return [$weight, $volume];
    }

    private function formatPoint(array $point, array $products): array
    {
        $items = [];
        foreach ($products as $product) {
            $entity = $this->requireProduct((int) $product['product_id']);
            $items[] = [
                'id' => (int) $product['id'],
                'product' => [
                    'id' => (int) $entity['id'],
                    'name' => $entity['name'],
                    'weight' => (float) $entity['weight'],
                    'length' => (float) $entity['length'],
                    'width' => (float) $entity['width'],
                    'height' => (float) $entity['height'],
                    'volume' => $this->calculateProductVolume($entity),
                ],
                'quantity' => (int) $product['quantity'],
            ];
        }

        return [
            'id' => (int) $point['id'],
            'sequence' => (int) $point['sequence'],
            'latitude' => (float) $point['latitude'],
            'longitude' => (float) $point['longitude'],
            'products' => $items,
        ];
    }

    private function formatUser(?array $user): ?array
    {
        if (!$user) {
            return null;
        }
        return [
            'id' => (int) $user['id'],
            'login' => $user['login'],
            'name' => $user['name'],
            'role' => $user['role'],
        ];
    }

    private function formatVehicle(?array $vehicle): ?array
    {
        if (!$vehicle) {
            return null;
        }
        return [
            'id' => (int) $vehicle['id'],
            'brand' => $vehicle['brand'],
            'license_plate' => $vehicle['license_plate'],
            'max_weight' => (float) $vehicle['max_weight'],
            'max_volume' => (float) $vehicle['max_volume'],
        ];
    }

    private function assertEditable(string $date): void
    {
        $today = new DateTimeImmutable('today');
        $deliveryDate = new DateTimeImmutable($date);
        if ($deliveryDate <= $today) {
            throw new ValidationException(['delivery_date' => 'Нельзя изменять прошедшие доставки']);
        }
        if ($deliveryDate <= $today->add(new DateInterval('P3D'))) {
            throw new ValidationException(['delivery_date' => 'Изменение доступно не позднее чем за 3 дня до доставки']);
        }
    }

    private function parseDate(?string $value, bool $allowNull = false): ?DateTimeImmutable
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

    private function parseTime(?string $value, string $field): ?DateTimeImmutable
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

    private function sanitizeStatus(?string $status): ?string
    {
        if ($status === null || $status === '') {
            return null;
        }
        if (!DeliveryStatus::isValid($status)) {
            throw new ValidationException(['status' => 'Некорректный статус']);
        }
        return $status;
    }

    private function requireProduct(int $id): array
    {
        if (!isset($this->productCache[$id])) {
            $product = $this->products->findById($id);
            if (!$product) {
                throw new ValidationException(['products' => 'Товар не найден: ' . $id]);
            }
            $this->productCache[$id] = $product;
        }
        return $this->productCache[$id];
    }

    private function calculateProductVolume(array $product): float
    {
        return ((float) $product['length'] * (float) $product['width'] * (float) $product['height']) / 1_000_000;
    }
}
