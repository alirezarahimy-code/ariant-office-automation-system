<?php
declare(strict_types=1);

namespace App\Models;

use App\Core\Model;

class Asset extends Model
{
    protected string $table = 'assets';

    public function findByTag(string $tag): ?array
    {
        $query = db()->prepare("SELECT * FROM {$this->table} WHERE asset_tag = ? LIMIT 1");
        $query->execute([$tag]);
        return $query->fetch() ?: null;
    }

    public function findByHostname(string $hostname): ?array
    {
        $query = db()->prepare("SELECT * FROM {$this->table} WHERE hostname = ? LIMIT 1");
        $query->execute([$hostname]);
        return $query->fetch() ?: null;
    }

    public function findByDepartment(int $departmentId): array
    {
        return $this->all(['department_id' => $departmentId], 'hostname ASC');
    }

    public function findByOwner(int $ownerId): array
    {
        return $this->all(['owner_user_id' => $ownerId], 'hostname ASC');
    }

    public function findByLifecycleStatus(string $status): array
    {
        return $this->all(['lifecycle_status' => $status], 'hostname ASC');
    }

    public function findOnline(): array
    {
        $query = db()->prepare("SELECT a.* FROM {$this->table} a JOIN asset_profiles p ON p.asset_id = a.id WHERE p.ad_online = 1");
        $query->execute();
        return $query->fetchAll();
    }

    public function search(string $keyword): array
    {
        $query = db()->prepare("SELECT * FROM {$this->table} WHERE asset_tag LIKE ? OR hostname LIKE ? OR serial_number LIKE ? ORDER BY hostname ASC");
        $query->execute(['%' . $keyword . '%', '%' . $keyword . '%', '%' . $keyword . '%']);
        return $query->fetchAll();
    }

    public function getProfile(int $assetId): ?array
    {
        $query = db()->prepare("SELECT * FROM asset_profiles WHERE asset_id = ? LIMIT 1");
        $query->execute([$assetId]);
        return $query->fetch() ?: null;
    }

    public function getHistory(int $assetId): array
    {
        $query = db()->prepare("SELECT * FROM asset_history_events WHERE asset_id = ? ORDER BY created_at DESC");
        $query->execute([$assetId]);
        return $query->fetchAll();
    }

    public function getInventoryHistory(int $assetId): array
    {
        $query = db()->prepare("SELECT * FROM asset_inventory_history WHERE asset_id = ? ORDER BY collected_at DESC");
        $query->execute([$assetId]);
        return $query->fetchAll();
    }

    public function getRelations(int $assetId): array
    {
        $query = db()->prepare("SELECT * FROM asset_relations WHERE source_asset_id = ? OR target_asset_id = ?");
        $query->execute([$assetId, $assetId]);
        return $query->fetchAll();
    }

    public function countByLifecycleStatus(): array
    {
        $query = db()->query("SELECT lifecycle_status, COUNT(*) as count FROM {$this->table} GROUP BY lifecycle_status");
        return $query->fetchAll(\PDO::FETCH_KEY_PAIR);
    }

    public function countByDepartment(): array
    {
        $query = db()->query("SELECT department_id, COUNT(*) as count FROM {$this->table} GROUP BY department_id");
        return $query->fetchAll(\PDO::FETCH_KEY_PAIR);
    }
}
