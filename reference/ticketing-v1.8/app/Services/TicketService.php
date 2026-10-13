<?php
declare(strict_types=1);

namespace App\Services;

use App\Models\Ticket;
use App\Models\User;
use App\Models\Notification;

class TicketService
{
    private Ticket $ticketModel;
    private User $userModel;
    private Notification $notificationModel;

    public function __construct()
    {
        $this->ticketModel = new Ticket();
        $this->userModel = new User();
        $this->notificationModel = new Notification();
    }

    public function getTicket(int $id): ?array
    {
        return $this->ticketModel->find($id);
    }

    public function getUserTickets(int $userId): array
    {
        return $this->ticketModel->findByUser($userId);
    }

    public function getOpenTickets(): array
    {
        return $this->ticketModel->findOpen();
    }

    public function getOverdueTickets(): array
    {
        return $this->ticketModel->findOverdue();
    }

    public function createTicket(array $data): int
    {
        $ticketId = $this->ticketModel->create($data);
        
        // Create notification for assigned user
        if (!empty($data['assigned_to'])) {
            $this->createNotification(
                (int) $data['assigned_to'],
                $ticketId,
                'ticket_assigned',
                'تیکت جدید',
                'یک تیکت جدید به شما اختصاص داده شده است.'
            );
        }
        
        return $ticketId;
    }

    public function assignTicket(int $ticketId, int $assigneeId): bool
    {
        $result = $this->ticketModel->update($ticketId, [
            'assigned_to' => $assigneeId,
            'assigned_at' => date('Y-m-d H:i:s'),
            'status' => 'assigned',
        ]);
        
        if ($result) {
            $this->createNotification(
                $assigneeId,
                $ticketId,
                'ticket_assigned',
                'تیکت جدید',
                'یک تیکت جدید به شما اختصاص داده شده است.'
            );
        }
        
        return $result;
    }

    public function updateStatus(int $ticketId, string $status): bool
    {
        $data = ['status' => $status];
        
        if ($status === 'resolved') {
            $data['resolved_at'] = date('Y-m-d H:i:s');
        } elseif ($status === 'closed') {
            $data['closed_at'] = date('Y-m-d H:i:s');
        }
        
        return $this->ticketModel->update($ticketId, $data);
    }

    public function addMessage(int $ticketId, int $userId, string $body, bool $isInternal = false): int
    {
        $query = db()->prepare("INSERT INTO ticket_messages (ticket_id, user_id, body, is_internal) VALUES (?, ?, ?, ?)");
        $query->execute([$ticketId, $userId, $body, $isInternal ? 1 : 0]);
        
        $messageId = (int) db()->lastInsertId();
        
        // Notify ticket requester
        $ticket = $this->getTicket($ticketId);
        if ($ticket && (int) $ticket['requester_id'] !== $userId) {
            $this->createNotification(
                (int) $ticket['requester_id'],
                $ticketId,
                'ticket_reply',
                'پاسخ جدید',
                'پاسخ جدیدی به تیکت شما اضافه شده است.'
            );
        }
        
        return $messageId;
    }

    public function searchTickets(string $keyword): array
    {
        return $this->ticketModel->search($keyword);
    }

    public function getTicketStats(): array
    {
        return [
            'by_status' => $this->ticketModel->countByStatus(),
            'by_priority' => $this->ticketModel->countByPriority(),
            'by_department' => $this->ticketModel->countByDepartment(),
            'open' => count($this->getOpenTickets()),
            'overdue' => count($this->getOverdueTickets()),
        ];
    }

    private function createNotification(int $userId, int $ticketId, string $type, string $title, string $body): void
    {
        $this->notificationModel->create([
            'user_id' => $userId,
            'ticket_id' => $ticketId,
            'notification_type' => $type,
            'title' => $title,
            'body' => $body,
        ]);
    }
}
