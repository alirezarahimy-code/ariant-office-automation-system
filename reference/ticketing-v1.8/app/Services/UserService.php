<?php
declare(strict_types=1);

namespace App\Services;

use App\Models\User;

class UserService
{
    private User $userModel;

    public function __construct()
    {
        $this->userModel = new User();
    }

    public function getUser(int $id): ?array
    {
        return $this->userModel->find($id);
    }

    public function getUserByUsername(string $username): ?array
    {
        return $this->userModel->findByUsername($username);
    }

    public function getActiveUsers(): array
    {
        return $this->userModel->findActive();
    }

    public function getAgents(): array
    {
        return $this->userModel->findAgents();
    }

    public function getUsersByRole(string $role): array
    {
        return $this->userModel->findByRole($role);
    }

    public function getUsersByDepartment(int $departmentId): array
    {
        return $this->userModel->findByDepartment($departmentId);
    }

    public function createUser(array $data): int
    {
        if (!empty($data['password'])) {
            $data['password_hash'] = password_hash($data['password'], PASSWORD_DEFAULT);
            unset($data['password']);
        }
        
        return $this->userModel->create($data);
    }

    public function updateUser(int $id, array $data): bool
    {
        if (!empty($data['password'])) {
            $data['password_hash'] = password_hash($data['password'], PASSWORD_DEFAULT);
            unset($data['password']);
        }
        
        return $this->userModel->update($id, $data);
    }

    public function deactivateUser(int $id): bool
    {
        return $this->userModel->update($id, ['is_active' => 0]);
    }

    public function activateUser(int $id): bool
    {
        return $this->userModel->update($id, ['is_active' => 1]);
    }

    public function searchUsers(string $keyword): array
    {
        return $this->userModel->search($keyword);
    }

    public function getUserStats(): array
    {
        return [
            'by_role' => $this->userModel->countByRole(),
            'active' => $this->userModel->countActive(),
        ];
    }

    public function getUserTickets(int $userId): array
    {
        return $this->userModel->getTickets($userId);
    }

    public function getUserNotifications(int $userId, bool $unreadOnly = false): array
    {
        return $this->userModel->getNotifications($userId, $unreadOnly);
    }
}
