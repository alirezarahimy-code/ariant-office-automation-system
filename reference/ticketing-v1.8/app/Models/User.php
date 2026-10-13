<?php
declare(strict_types=1);

namespace App\Models;

use App\Core\Model;

class User extends Model
{
    protected string $table = 'users';

    public function findByUsername(string $username): ?array
    {
        $query = db()->prepare("SELECT * FROM {$this->table} WHERE username = ? LIMIT 1");
        $query->execute([$username]);
        return $query->fetch() ?: null;
    }

    public function findByEmail(string $email): ?array
    {
        $query = db()->prepare("SELECT * FROM {$this->table} WHERE email = ? LIMIT 1");
        $query->execute([$email]);
        return $query->fetch() ?: null;
    }

    public function findByRole(string $role): array
    {
        return $this->all(['role' => $role], 'full_name ASC');
    }

    public function findActive(): array
    {
        return $this->all(['is_active' => 1], 'full_name ASC');
    }

    public function findByDepartment(int $departmentId): array
    {
        return $this->all(['department_id' => $departmentId], 'full_name ASC');
    }

    public function findAgents(): array
    {
        $query = db()->prepare("SELECT * FROM {$this->table} WHERE (role = 'agent' OR is_it_agent = 1) AND is_active = 1");
        $query->execute();
        return $query->fetchAll();
    }

    public function search(string $keyword): array
    {
        $query = db()->prepare("SELECT * FROM {$this->table} WHERE username LIKE ? OR full_name LIKE ? OR email LIKE ? ORDER BY full_name ASC");
        $query->execute(['%' . $keyword . '%', '%' . $keyword . '%', '%' . $keyword . '%']);
        return $query->fetchAll();
    }

    public function getTickets(int $userId): array
    {
        $query = db()->prepare("SELECT * FROM tickets WHERE requester_id = ? OR assigned_to = ? ORDER BY created_at DESC");
        $query->execute([$userId, $userId]);
        return $query->fetchAll();
    }

    public function getNotifications(int $userId, bool $unreadOnly = false): array
    {
        $sql = "SELECT * FROM notifications WHERE user_id = ?";
        if ($unreadOnly) {
            $sql .= " AND is_read = 0";
        }
        $sql .= " ORDER BY created_at DESC";
        $query = db()->prepare($sql);
        $query->execute([$userId]);
        return $query->fetchAll();
    }

    public function countByRole(): array
    {
        $query = db()->query("SELECT role, COUNT(*) as count FROM {$this->table} GROUP BY role");
        return $query->fetchAll(\PDO::FETCH_KEY_PAIR);
    }

    public function countActive(): int
    {
        $query = db()->query("SELECT COUNT(*) FROM {$this->table} WHERE is_active = 1");
        return (int) $query->fetchColumn();
    }
}
