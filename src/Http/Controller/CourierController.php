<?php

declare(strict_types=1);

namespace App\Http\Controller;

use App\Application\Service\CourierService;
use App\Support\Validate;
use App\Support\Request;
use App\Support\Response;

class CourierController
{
    public function __construct(private CourierService $couriers, private Validate $validator)
    {
    }

    private function validateCourierId($params) {
        return $this->validator->validate_id($params, 'Доставка');
    }

    public function index(Request $request, array $params = []): Response
    {
        $user = $request->user() ?? [];
        $filters = [
            'date' => $request->query('date'),
            'status' => $request->query('status'),
            'date_from' => $request->query('date_from'),
            'date_to' => $request->query('date_to'),
        ];
        $deliveries = $this->couriers->listForCourier($filters, $user);
        return Response::json(['data' => $deliveries]);
    }

    public function show(Request $request, array $params = []): Response
    {
        $user = $request->user() ?? [];
        $id = $this->validateCourierId($params);
        $delivery = $this->couriers->getCourierDelivery($id, $user);
        return Response::json(['data' => $delivery]);
    }
}