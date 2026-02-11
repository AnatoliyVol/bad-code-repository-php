<?php

declare(strict_types=1);

namespace App\Support\Delivery;

use App\Support\Delivery\ServiceCalculator;

class ServiceFormater {

    public function __construct(
        private ServiceCalculator $service_calculator,
    ) {
    }

    public function formatPoint(array $point, array $products, array $productCache): array
    {
        $items = [];
        foreach ($products as $product) {
            $entity = $this->service_calculator->requireProduct((int) $product['product_id'], $productCache);
            $items[] = [
                'id' => (int) $product['id'],
                'product' => [
                    'id' => (int) $entity['id'],
                    'name' => $entity['name'],
                    'weight' => (float) $entity['weight'],
                    'length' => (float) $entity['length'],
                    'width' => (float) $entity['width'],
                    'height' => (float) $entity['height'],
                    'volume' => $this->service_calculator->calculateProductVolume($entity),
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

    public function formatUser(?array $user): ?array
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

    public function formatVehicle(?array $vehicle): ?array
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
}