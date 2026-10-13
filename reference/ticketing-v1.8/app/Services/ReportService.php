<?php
declare(strict_types=1);

namespace App\Services;

use App\Models\Ticket;
use App\Models\User;
use App\Models\Asset;
use App\Models\ActivityLog;

class ReportService
{
    private Ticket $ticketModel;
    private User $userModel;
    private Asset $assetModel;
    private ActivityLog $activityLogModel;

    public function __construct()
    {
        $this->ticketModel = new Ticket();
        $this->userModel = new User();
        $this->assetModel = new Asset();
        $this->activityLogModel = new ActivityLog();
    }

    public function getTicketReport(array $filters = []): array
    {
        $tickets = $this->ticketModel->all([], 'created_at DESC');
        
        // Apply filters
        if (!empty($filters['status'])) {
            $tickets = array_filter($tickets, fn($t) => $t['status'] === $filters['status']);
        }
        
        if (!empty($filters['priority'])) {
            $tickets = array_filter($tickets, fn($t) => $t['priority'] === $filters['priority']);
        }
        
        if (!empty($filters['department_id'])) {
            $tickets = array_filter($tickets, fn($t) => (int)$t['department_id'] === (int)$filters['department_id']);
        }
        
        return array_values($tickets);
    }

    public function getUserReport(): array
    {
        return $this->userModel->countByRole();
    }

    public function getAssetReport(): array
    {
        return [
            'by_lifecycle' => $this->assetModel->countByLifecycleStatus(),
            'by_department' => $this->assetModel->countByDepartment(),
        ];
    }

    public function getActivityReport(string $from, string $to): array
    {
        return $this->activityLogModel->findByDateRange($from, $to);
    }

    public function getDashboardStats(): array
    {
        return [
            'tickets' => [
                'total' => count($this->ticketModel->all()),
                'open' => count($this->ticketModel->findOpen()),
                'overdue' => count($this->ticketModel->findOverdue()),
                'by_status' => $this->ticketModel->countByStatus(),
                'by_priority' => $this->ticketModel->countByPriority(),
            ],
            'users' => [
                'total' => count($this->userModel->all()),
                'active' => $this->userModel->countActive(),
                'by_role' => $this->userModel->countByRole(),
            ],
            'assets' => [
                'total' => count($this->assetModel->all()),
                'online' => count($this->assetModel->findOnline()),
                'by_lifecycle' => $this->assetModel->countByLifecycleStatus(),
            ],
        ];
    }

    public function getTicketTrends(int $days = 30): array
    {
        $query = db()->prepare("
            SELECT DATE(created_at) as date, COUNT(*) as count 
            FROM tickets 
            WHERE created_at >= DATE_SUB(NOW(), INTERVAL ? DAY) 
            GROUP BY DATE(created_at) 
            ORDER BY date ASC
        ");
        $query->execute([$days]);
        return $query->fetchAll();
    }

    public function getTopAgents(int $limit = 10): array
    {
        $query = db()->prepare("
            SELECT u.id, u.full_name, COUNT(t.id) as ticket_count 
            FROM users u 
            LEFT JOIN tickets t ON t.assigned_to = u.id AND t.status = 'closed' 
            WHERE u.role = 'agent' OR u.is_it_agent = 1 
            GROUP BY u.id 
            ORDER BY ticket_count DESC 
            LIMIT " . max(1, min(50, $limit))
        );
        $query->execute();
        return $query->fetchAll();
    }

    public function getAverageResolutionTime(): array
    {
        $query = db()->query("
            SELECT 
                AVG(TIMESTAMPDIFF(HOUR, created_at, resolved_at)) as avg_hours,
                COUNT(*) as total_resolved
            FROM tickets 
            WHERE resolved_at IS NOT NULL
        ");
        return $query->fetch() ?: ['avg_hours' => 0, 'total_resolved' => 0];
    }
}
