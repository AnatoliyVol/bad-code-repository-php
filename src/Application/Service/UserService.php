<?php

declare(strict_types=1);

namespace App\Application\Service;

use App\Domain\ValueObject\UserRole;
use App\Infrastructure\Repository\DeliveryRepository;
use App\Infrastructure\Repository\UserRepository;
use App\Infrastructure\Security\PasswordHasher;
use App\Support\Exceptions\NotFoundException;
use App\Support\Exceptions\ValidationException;
use App\Support\Validation\ValidateUserService;
use DateTimeImmutable;

class UserService
{
    public function __construct(
        private UserRepository $users,
        private DeliveryRepository $deliveries,
        private PasswordHasher $hasher,
        private ValidateUserService $validateUserService,
    ) {
    }

    public function list(?string $role = null): array
    {
        if ($role !== null && !UserRole::isValid($role)) {
            throw new ValidationException(['role' => 'Неизвестная роль']);
        }

        $users = $this->users->findAll($role);
        return array_map([$this, 'transform'], $users);
    }

    public function create(array $payload): array
    {
        $this->validateUserService->createUserValidation($payload);

        $user = $this->users->create([
            'login' => $payload['login'],
            'password_hash' => $this->hasher->hash($payload['password']),
            'name' => $payload['name'],
            'role' => $payload['role'],
            'created_at' => (new DateTimeImmutable())->format(DATE_ATOM),
        ]);

        return $this->transform($user);
    }

    public function update(int $id, array $payload): array
    {
        $this->validateUserService->updateUserValidation($id, $payload);

        $data['login'] = $payload['login'];
        $data['name'] = $payload['name'];
        $data['password_hash'] = $this->hasher->hash($payload['password']);
        $data['role'] = $payload['role'];

        $updated = $this->users->update($id, $data);
        return $this->transform($updated);
    }

    public function delete(int $id): void
    {
        $user = $this->users->findById($id);
        if (!$user) {
            throw new NotFoundException('User not found');
        }

        $deliveries = $this->deliveries->findByFilters(null, $id, null);
        $hasActive = array_filter($deliveries, function (array $delivery): bool {
            return in_array($delivery['status'], ['planned', 'in_progress'], true);
        });

        if ($hasActive) {
            throw new ValidationException([
                'id' => 'Пользователь задействован в активных доставках и не может быть удален',
            ]);
        }

        $this->users->delete($id);
    }

    private function transform(array $user): array
    {
        return [
            'id' => (int) $user['id'],
            'login' => $user['login'],
            'name' => $user['name'],
            'role' => $user['role'],
            'created_at' => $user['created_at'],
        ];
    }
}