<?php

declare(strict_types=1);

namespace App\Support\Delivery;

use App\Support\Exceptions\ValidationException;
use App\Infrastructure\Repository\DeliveryPointProductRepository;
use App\Infrastructure\Repository\DeliveryPointRepository;
use App\Infrastructure\Repository\ProductRepository;

class ServiceCalculator {

    public function __construct(
        private DeliveryPointProductRepository $pointProducts,
        private DeliveryPointRepository $points,
        private ProductRepository $products,
    ) {
    }

    public function calculateTotals(array $points, array &$productCache): array
    {
        $totalWeight = 0.0;
        $totalVolume = 0.0;
        foreach ($points as $point) {
            foreach ($point['products'] as $product) {
                $entity = $this->requireProduct($product['product_id'], $productCache);
                $quantity = $product['quantity'];
                $totalWeight += (float) $entity['weight'] * $quantity;
                $totalVolume += $this->calculateProductVolume($entity) * $quantity;
            }
        }
        return [$totalWeight, $totalVolume];
    }

    public function calculateExistingTotals(array $deliveries, array &$productCache): array
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
            $entity = $this->requireProduct((int) $product['product_id'], $productCache);
            $quantity = (int) $product['quantity'];
            $totals['weight'] += (float) $entity['weight'] * $quantity;
            $totals['volume'] += $this->calculateProductVolume($entity) * $quantity;
        }
        return $totals;
    }

    public function calculateTotalsForResponse(array $points): array
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

    public function requireProduct(int $id, array &$productCache): array
    {
        if (!isset($this->productCache[$id])) {
            $product = $this->products->findById($id);
            if (!$product) {
                throw new ValidationException(['products' => 'Товар не найден: ' . $id]);
            }
            $productCache[$id] = $product;
        }
        return $productCache[$id];
    }

    public function calculateProductVolume(array $product): float
    {
        return ((float) $product['length'] * (float) $product['width'] * (float) $product['height']) / 1_000_000;
    }
}