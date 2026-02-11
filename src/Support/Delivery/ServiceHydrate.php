<?php

declare(strict_types=1);

namespace App\Support\Delivery;

use App\Infrastructure\Repository\DeliveryPointProductRepository;
use App\Infrastructure\Repository\DeliveryPointRepository;
use App\Infrastructure\Repository\UserRepository;
use App\Infrastructure\Repository\VehicleRepository;
use App\Support\Delivery\ServiceCalculator;
use App\Support\Delivery\ServiceFormater;
use DateInterval;
use DateTimeImmutable;

class ServiceHydrate {

    public function __construct(
        private DeliveryPointRepository $points,
        private DeliveryPointProductRepository $pointProducts,
        private UserRepository $users,
        private VehicleRepository $vehicles,
        private ServiceCalculator $serviceCalculator,
        private ServiceFormater $serviceFormater,
    ) {
    }
    private function hydrateDeliveries(array $deliveries, array &$productCache): array
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
            $pointsByDelivery[$deliveryId][] = $this->serviceFormater->formatPoint($point, $productsByPoint[$pointId] ?? [], $productCache);
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
            [$weight, $volume] = $this->serviceCalculator->calculateTotalsForResponse($deliveryPoints);
            $deliveryDate = new DateTimeImmutable($delivery['delivery_date']);
            $canEdit = $deliveryDate > $now->add(new DateInterval('P3D'));

            $results[] = [
                'id' => $deliveryId,
                'delivery_number' => sprintf('DEL-%s-%03d', substr($delivery['delivery_date'], 0, 4), $deliveryId),
                'courier' => $this->serviceFormater->formatUser($users[(int) ($delivery['courier_id'] ?? 0)] ?? null),
                'vehicle' => $this->serviceFormater->formatVehicle($vehicles[(int) ($delivery['vehicle_id'] ?? 0)] ?? null),
                'created_by' => $this->serviceFormater->formatUser($users[(int) $delivery['created_by']] ?? null),
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

    public function presentRawDeliveries(array $deliveries, array &$productCache): array
    {
        $productCache = [];
        return $this->hydrateDeliveries($deliveries, $productCache);
    }
}