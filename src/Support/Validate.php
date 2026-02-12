<?php

declare(strict_types=1);

namespace App\Support;

use App\Support\Exceptions\ValidationException;


class Validate {

    public static function validate_id(array $params, String $entity): int
    {
        $id = isset($params['id']) ? (int) $params['id'] : 0;
        if ($id <= 0) {
            throw new ValidationException(['id' => `Некорректный ID для объекта $entity`]);
        }
        return $id;
    }
}