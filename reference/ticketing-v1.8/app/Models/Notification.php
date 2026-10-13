<?php
declare(strict_types=1);

namespace App\Models;

use App\Core\Model;

class Notification extends Model
{
    protected string $table = 'notifications';

    public function findByUser(int $userId, bool $unreadOnly = false): array
    {
        $sql = "SELECT * FROM {$this->table} WHERE user_id = ?";
        if ($unreadOnly) {
            $sql .= " AND is_read = 0";
        }
        $sql .= " ORDER BY created_at DESC";
        $query = db()->prepare($sql);
        $query->execute([$userId]);
        return $query->fetchAll();
    }

    public function findUnread(int $userId): array
    {
        return $this->findByUser($userId, true);
    }

    public function countUnread(int $userId): int
    {
        $query = db()->prepare("SELECT COUNT(*) FROM {$this->table} WHERE user_id = ? AND is_read = 0");
        $query->execute([$userId]);
        return (int) $query->fetchColumn();
    }

    public function markAsRead(int $id): bool
    {
        return $this->update($id, ['is_read' => 1, 'read_at' => date('Y-m-d H:i:s')]);
    }

    public function markAllAsRead(int $userId): bool
    {
        $query = db()->prepare("UPDATE {$this->table} SET is_read = 1, read_at = ? WHERE user_id = ? AND is_read = 0");
        return $query->execute([date('Y-m-d H:i:s'), $userId]);
    }

    public function findByType(string $type): array
    {
        return $this->all(['notification_type' => $type], 'created_at DESC');
    }

    public function findRecent(int $limit = 20): array
    {
        $query = db()->prepare("SELECT * FROM {$this->table} ORDER BY created_at DESC LIMIT " . max(1, min(100, $limit)));
        $query->execute();
        return $query->fetchAll();
    }
}
