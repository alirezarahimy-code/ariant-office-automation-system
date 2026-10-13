<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Controller;

class TicketController extends Controller
{
    public function index(): void
    {
        $user = current_user();
        if (!$user) {
            $this->redirect('index.php?page=login');
        }

        $data = [
            'user' => $user,
            'title' => 'تیکت‌ها',
        ];

        $this->view('ticket/index', $data);
    }

    public function create(): void
    {
        $user = current_user();
        if (!$user) {
            $this->redirect('index.php?page=login');
        }

        $data = [
            'user' => $user,
            'title' => 'ثبت تیکت جدید',
        ];

        $this->view('ticket/create', $data);
    }

    public function show(int $id): void
    {
        $user = current_user();
        if (!$user) {
            $this->redirect('index.php?page=login');
        }

        $ticket = fetch_ticket($id);
        if (!$ticket) {
            http_response_code(404);
            echo 'تیکت یافت نشد';
            return;
        }

        $data = [
            'user' => $user,
            'ticket' => $ticket,
            'title' => 'تیکت #' . $ticket['id'],
        ];

        $this->view('ticket/show', $data);
    }

    public function reply(int $id): void
    {
        $user = current_user();
        if (!$user) {
            $this->redirect('index.php?page=login');
        }

        $ticket = fetch_ticket($id);
        if (!$ticket) {
            http_response_code(404);
            echo 'تیکت یافت نشد';
            return;
        }

        $data = [
            'user' => $user,
            'ticket' => $ticket,
            'title' => 'پاسخ به تیکت #' . $ticket['id'],
        ];

        $this->view('ticket/reply', $data);
    }
}
