<?php

declare(strict_types=1);

namespace App\Http\Controller;

use App\Application\Service\VehicleService;
use App\Support\Validate;
use App\Support\Request;
use App\Support\Response;

class VehicleController
{
    public function __construct(private VehicleService $vehicles, private Validate $validator)
    {
    }

    private function validateVehicleId(array $params): int
    {
        return $this->validator->validate_id($params, 'Машина');
    }

    public function index(Request $request, array $params = []): Response
    {
        $data = $this->vehicles->list();
        return Response::json(['data' => $data]);
    }

    public function store(Request $request, array $params = []): Response
    {
        $vehicle = $this->vehicles->create($request->body());
        return Response::json(['data' => $vehicle], 201);
    }

    public function update(Request $request, array $params = []): Response
    {
        $id = $this->validateVehicleId($params);
        $vehicle = $this->vehicles->update($id, $request->body());
        return Response::json(['data' => $vehicle]);
    }

    public function destroy(Request $request, array $params = []): Response
    {
        $id = $this->validateVehicleId($params);
        $this->vehicles->delete($id);
        return Response::json([], 204);
    }
}