<?php

declare(strict_types=1);

namespace App\Http\Controller;

use App\Application\Service\ProductService;
use App\Support\Validate;
use App\Support\Request;
use App\Support\Response;

class ProductController
{
    public function __construct(
        private ProductService $products,
        private Validate $validator)
    {
    }

    private function validateProductId(array $params): int
    {
        return $this->validator->validate_id($params, 'Товар');
    }

    public function index(Request $request, array $params = []): Response
    {
        $data = $this->products->list();
        return Response::json(['data' => $data]);
    }

    public function store(Request $request, array $params = []): Response
    {
        $product = $this->products->create($request->body());
        return Response::json(['data' => $product], 201);
    }

    public function update(Request $request, array $params = []): Response
    {
        $id = $this->validateProductId($params);
        $product = $this->products->update($id, $request->body());
        return Response::json(['data' => $product]);
    }

    public function destroy(Request $request, array $params = []): Response
    {
        $id = $this->validateProductId($params);
        $this->products->delete($id);
        return Response::json([], 204);
    }
}