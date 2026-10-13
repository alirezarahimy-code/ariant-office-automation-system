<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Controller;

class ReportController extends Controller
{
    public function index(): void
    {
        $user = current_user();
        if (!$user) {
            $this->redirect('index.php?page=login');
        }

        $data = [
            'user' => $user,
            'title' => 'گزارش‌ها',
        ];

        $this->view('report/index', $data);
    }

    public function tickets(): void
    {
        $user = current_user();
        if (!$user) {
            $this->redirect('index.php?page=login');
        }

        $data = [
            'user' => $user,
            'title' => 'گزارش تیکت‌ها',
        ];

        $this->view('report/tickets', $data);
    }
}
