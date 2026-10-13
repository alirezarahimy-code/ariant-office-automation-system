<?php
declare(strict_types=1);

namespace App\Core;

abstract class Model
{
    protected string $table = '';
    protected string $primaryKey = 'id';

    public function find(int $id): ?array
    {
        $query = db()->prepare("SELECT * FROM {$this->table} WHERE {$this->primaryKey} = ? LIMIT 1");
        $query->execute([$id]);
        return $query->fetch() ?: null;
    }

    public function all(array $conditions = [], string $orderBy = ''): array
    {
        $sql = "SELECT * FROM {$this->table}";
        $params = [];
        
        if (!empty($conditions)) {
            $where = [];
            foreach ($conditions as $key => $value) {
                $where[] = "{$key} = ?";
                $params[] = $value;
            }
            $sql .= " WHERE " . implode(' AND ', $where);
        }
        
        if ($orderBy) {
            $sql .= " ORDER BY {$orderBy}";
        }
        
        $query = db()->prepare($sql);
        $query->execute($params);
        return $query->fetchAll();
    }

    public function create(array $data): int
    {
        $columns = implode(', ', array_keys($data));
        $placeholders = implode(', ', array_fill(0, count($data), '?'));
        $query = db()->prepare("INSERT INTO {$this->table} ({$columns}) VALUES ({$placeholders})");
        $query->execute(array_values($data));
        return (int) db()->lastInsertId();
    }

    public function update(int $id, array $data): bool
    {
        $set = [];
        $params = [];
        foreach ($data as $key => $value) {
            $set[] = "{$key} = ?";
            $params[] = $value;
        }
        $params[] = $id;
        $query = db()->prepare("UPDATE {$this->table} SET " . implode(', ', $set) . " WHERE {$this->primaryKey} = ?");
        return $query->execute($params);
    }

    public function delete(int $id): bool
    {
        $query = db()->prepare("DELETE FROM {$this->table} WHERE {$this->primaryKey} = ?");
        return $query->execute([$id]);
    }
}
