<?php

declare(strict_types=1);

namespace App\Http\Controller;

use App\Application\Service\UserService;
use App\Support\Request;
use App\Support\Validate;
use App\Support\Response;

class UserController
{
    public function __construct(private UserService $users, private Validate $validator)
    {
    }

    private function validateUserId(array $params): int
    {
        return $this->validator->validate_id($params, 'Пользователь');
    }

    public function index(Request $request, array $params = []): Response
    {
        $role = $request->query('role');
        $data = $this->users->list($role);
        return Response::json(['data' => $data]);
    }

    public function store(Request $request, array $params = []): Response
    {
        $user = $this->users->create($request->body());
        return Response::json(['data' => $user], 201);
    }

    public function update(Request $request, array $params = []): Response
    {
        $id = $this->validateUserId($params);
        $user = $this->users->update($id, $request->body());
        return Response::json(['data' => $user]);
    }

    public function destroy(Request $request, array $params = []): Response
    {
        $id = $this->validateUserId($params);
        $this->users->delete($id);
        return Response::json([], 204);
    }
}