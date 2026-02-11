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
use App\Support\Delivery\ServiceCalculator;
use App\Support\Delivery\ServiceFormater;
use App\Support\Delivery\ServiceHydrate;
use App\Support\Delivery\ServiceParser;
use App\Support\Delivery\ServiceValidate;
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
        private ServiceCalculator $serviceCalculator,
        private ServiceParser $serviceParser,
        private ServiceHydrate $serviceHydrate,
        private ServiceValidate $serviceValidate,
        private ServiceFormater $serviceFormater,
    ) {
    }

    public function list(array $filters): array
    {
        $date = $this->serviceParser->parseDate($filters['date'] ?? null, allowNull: true);
        $courierId = isset($filters['courier_id']) ? (int) $filters['courier_id'] : null;
        $status = $this->sanitizeStatus($filters['status'] ?? null);

        $rows = $this->deliveries->findByFilters($date?->format('Y-m-d'), $courierId, $status);
        return $this->serviceHydrate->presentRawDeliveries($rows, $this->productCache);
    }

    public function get(int $id): array
    {
        $delivery = $this->deliveries->findById($id);
        if (!$delivery) {
            throw new NotFoundException('Delivery not found');
        }

        $results = $this->serviceHydrate->presentRawDeliveries([$delivery], $this->productCache);
        return $results[0];
    }

    public function create(array $payload, array $currentUser): array
    {
        $this->productCache = [];
        $data = $this->serviceValidate->validatePayload($payload);
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

        $data = $this->serviceValidate->validatePayload($payload);
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
            $dateObject = $this->serviceParser->parseDate((string) $dateString);
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
                    $normalized = $this->serviceValidate->validatePayload($requestPayload, false);
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

        $this->serviceValidate->validateVehicleCapacity($data, $deliveryId, $this->productCache);
        if (count($data['points']) >= 2) {
            $this->serviceValidate->validateRouteTime($data);
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


    private function removePoints(int $deliveryId): void
    {
        $points = $this->points->findByDelivery($deliveryId);
        foreach ($points as $point) {
            $this->pointProducts->deleteByDeliveryPoint((int) $point['id']);
        }
        $this->points->deleteByDelivery($deliveryId);
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

}
