<?php

declare(strict_types=1);

namespace App\Support\Validation;

use App\Domain\ValueObject\UserRole;
use App\Infrastructure\Repository\UserRepository;
use App\Infrastructure\Repository\VehicleRepository;
use App\Support\Exceptions\ValidationException;

class ValidateDeliveryService {
    public function __construct(
        private UserRepository $users,
        private VehicleRepository $vehicles,
    ) {
    }
    public function generateDeliveriesValidation($couriers, $vehicles, $routeData, $index, &$warnings) {
        if (empty($couriers) || empty($vehicles)) {
            return 'break';
        }

        if (!is_array($routeData['route'] ?? null) || count($routeData['route']) < 1) {
            $warnings[] = 'Маршрут #' . ($index + 1) . ' пропущен: нет точек';
            return 'continue';
        }

        if (!is_array($routeData['products'] ?? null) || count($routeData['products']) === 0) {
            $warnings[] = 'Маршрут #' . ($index + 1) . ' пропущен: нет товаров';
            return 'continue';
        }
    }

    public function persistDeliveryValidation($data) {
        $courier = $this->users->findById($data['courier_id']);
        if (!$courier || $courier['role'] !== UserRole::COURIER) {
            throw new ValidationException(['courier_id' => 'Курьер не найден или роль некорректна']);
        }

        $vehicle = $this->vehicles->findById($data['vehicle_id']);
        if (!$vehicle) {
            throw new ValidationException(['vehicle_id' => 'Машина не найдена']);
        }
    }
}