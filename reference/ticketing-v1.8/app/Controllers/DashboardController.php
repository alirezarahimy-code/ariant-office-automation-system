<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Controller;

class DashboardController extends Controller
{
    public function index(): void
    {
        $user = current_user();
        if (!$user) {
            $this->redirect('index.php?page=login');
        }

        $data = [
            'user' => $user,
            'title' => 'داشبورد',
        ];

        $this->view('dashboard/index', $data);
    }
}
