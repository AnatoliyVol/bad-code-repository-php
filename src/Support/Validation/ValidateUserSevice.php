<?php

declare(strict_types=1);

namespace App\Support\Validation;

use App\Domain\ValueObject\UserRole;
use App\Infrastructure\Repository\UserRepository;
use App\Support\Exceptions\NotFoundException;
use App\Support\Exceptions\ValidationException;

class ValidateUserService {
    public function __construct(
        private UserRepository $users,
    ) {
    }

    public function createUserValidation(array $payload) {
        $errors = [];
        $login = trim((string) ($payload['login'] ?? ''));
        $name = trim((string) ($payload['name'] ?? ''));
        $password = (string) ($payload['password'] ?? '');
        $role = (string) ($payload['role'] ?? '');

        if ($login === '') {
            $errors['login'] = 'Логин обязателен';
        }
        if ($name === '') {
            $errors['name'] = 'Имя обязательно';
        }
        if ($password === '') {
            $errors['password'] = 'Пароль обязателен';
        }
        if ($role === '' || !UserRole::isValid($role)) {
            $errors['role'] = 'Некорректная роль';
        }
        if ($errors) {
            throw new ValidationException($errors);
        }

        if ($this->users->findByLogin($login)) {
            throw new ValidationException(['login' => 'Логин уже используется']);
        }
    }

    public function updateUserValidation(int $userId, array $payload) {

        $user = $this->users->findById($id);
        if (!$user) {
            throw new NotFoundException('User not found');
        }

        $errors = [];
        $login = trim((string) ($payload['login'] ?? ''));
        $name = trim((string) ($payload['name'] ?? ''));
        $password = (string) ($payload['password'] ?? '');
        $role = (string) ($payload['role'] ?? '');

        if ($login === '') {
            $errors['login'] = 'Логин обязателен';
        }
        else if ($login !== $user['login'] && $this->users->findByLogin($login)) {
                $errors['login'] = 'Логин уже используется';
        }

        if ($name === '') {
            $errors['name'] = 'Имя не может быть пустым';
        } 

        if ($password === '') {
            $errors['password'] = 'Пароль не может быть пустым';
        }

        if ($role === '' || !UserRole::isValid($role)) {
            $errors['role'] = 'Некорректная роль';
        }

        if ($errors) {
            throw new ValidationException($errors);
        }

    }
}