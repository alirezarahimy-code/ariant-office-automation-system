<?php
declare(strict_types=1);

namespace App\Models;

use App\Core\Model;

class Ticket extends Model
{
    protected string $table = 'tickets';

    public function findByStatus(string $status): array
    {
        return $this->all(['status' => $status], 'created_at DESC');
    }

    public function findByUser(int $userId): array
    {
        return $this->all(['requester_id' => $userId], 'created_at DESC');
    }

    public function findByAssignee(int $assigneeId): array
    {
        return $this->all(['assigned_to' => $assigneeId], 'created_at DESC');
    }

    public function findByDepartment(int $departmentId): array
    {
        return $this->all(['department_id' => $departmentId], 'created_at DESC');
    }

    public function findByServiceGroup(string $serviceGroup): array
    {
        return $this->all(['service_group' => $serviceGroup], 'created_at DESC');
    }

    public function findOpen(): array
    {
        $query = db()->prepare("SELECT * FROM {$this->table} WHERE status NOT IN ('closed', 'resolved') ORDER BY created_at DESC");
        $query->execute();
        return $query->fetchAll();
    }

    public function findOverdue(): array
    {
        $query = db()->prepare("SELECT * FROM {$this->table} WHERE due_at < NOW() AND status NOT IN ('closed', 'resolved') ORDER BY due_at ASC");
        $query->execute();
        return $query->fetchAll();
    }

    public function search(string $keyword): array
    {
        $query = db()->prepare("SELECT * FROM {$this->table} WHERE subject LIKE ? OR description LIKE ? ORDER BY created_at DESC");
        $query->execute(['%' . $keyword . '%', '%' . $keyword . '%']);
        return $query->fetchAll();
    }

    public function getMessages(int $ticketId): array
    {
        $query = db()->prepare("SELECT * FROM ticket_messages WHERE ticket_id = ? ORDER BY created_at ASC");
        $query->execute([$ticketId]);
        return $query->fetchAll();
    }

    public function getAttachments(int $ticketId): array
    {
        $query = db()->prepare("SELECT a.* FROM ticket_attachments a JOIN ticket_messages m ON m.id = a.message_id WHERE m.ticket_id = ?");
        $query->execute([$ticketId]);
        return $query->fetchAll();
    }

    public function getEvents(int $ticketId): array
    {
        $query = db()->prepare("SELECT * FROM ticket_events WHERE ticket_id = ? ORDER BY created_at ASC");
        $query->execute([$ticketId]);
        return $query->fetchAll();
    }

    public function countByStatus(): array
    {
        $query = db()->query("SELECT status, COUNT(*) as count FROM {$this->table} GROUP BY status");
        return $query->fetchAll(\PDO::FETCH_KEY_PAIR);
    }

    public function countByPriority(): array
    {
        $query = db()->query("SELECT priority, COUNT(*) as count FROM {$this->table} GROUP BY priority");
        return $query->fetchAll(\PDO::FETCH_KEY_PAIR);
    }

    public function countByDepartment(): array
    {
        $query = db()->query("SELECT department_id, COUNT(*) as count FROM {$this->table} GROUP BY department_id");
        return $query->fetchAll(\PDO::FETCH_KEY_PAIR);
    }
}
