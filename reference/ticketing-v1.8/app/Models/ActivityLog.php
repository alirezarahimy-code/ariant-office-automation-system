<?php
declare(strict_types=1);

namespace App\Models;

use App\Core\Model;

class ActivityLog extends Model
{
    protected string $table = 'activity_logs';

    public function findByUser(int $userId): array
    {
        return $this->all(['user_id' => $userId], 'created_at DESC');
    }

    public function findByAction(string $actionCode): array
    {
        return $this->all(['action_code' => $actionCode], 'created_at DESC');
    }

    public function findByModule(string $module): array
    {
        return $this->all(['module' => $module], 'created_at DESC');
    }

    public function findByDateRange(string $from, string $to): array
    {
        $query = db()->prepare("SELECT * FROM {$this->table} WHERE created_at BETWEEN ? AND ? ORDER BY created_at DESC");
        $query->execute([$from, $to]);
        return $query->fetchAll();
    }

    public function findRecent(int $limit = 100): array
    {
        $query = db()->prepare("SELECT * FROM {$this->table} ORDER BY created_at DESC LIMIT " . max(1, min(1000, $limit)));
        $query->execute();
        return $query->fetchAll();
    }

    public function search(string $keyword): array
    {
        $query = db()->prepare("SELECT * FROM {$this->table} WHERE username LIKE ? OR full_name LIKE ? OR action_label LIKE ? OR action_code LIKE ? ORDER BY created_at DESC");
        $query->execute(['%' . $keyword . '%', '%' . $keyword . '%', '%' . $keyword . '%', '%' . $keyword . '%']);
        return $query->fetchAll();
    }

    public function countByAction(): array
    {
        $query = db()->query("SELECT action_code, COUNT(*) as count FROM {$this->table} GROUP BY action_code");
        return $query->fetchAll(\PDO::FETCH_KEY_PAIR);
    }

    public function countByModule(): array
    {
        $query = db()->query("SELECT module, COUNT(*) as count FROM {$this->table} GROUP BY module");
        return $query->fetchAll(\PDO::FETCH_KEY_PAIR);
    }

    public function countByUser(): array
    {
        $query = db()->query("SELECT user_id, COUNT(*) as count FROM {$this->table} GROUP BY user_id");
        return $query->fetchAll(\PDO::FETCH_KEY_PAIR);
    }
}
