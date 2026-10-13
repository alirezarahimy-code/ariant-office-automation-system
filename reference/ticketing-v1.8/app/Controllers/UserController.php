<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Controller;

class UserController extends Controller
{
    public function index(): void
    {
        $user = current_user();
        if (!$user) {
            $this->redirect('index.php?page=login');
        }

        $data = [
            'user' => $user,
            'title' => 'کاربران',
        ];

        $this->view('user/index', $data);
    }

    public function create(): void
    {
        $user = current_user();
        if (!$user) {
            $this->redirect('index.php?page=login');
        }

        $data = [
            'user' => $user,
            'title' => 'ایجاد کاربر جدید',
        ];

        $this->view('user/create', $data);
    }

    public function show(int $id): void
    {
        $user = current_user();
        if (!$user) {
            $this->redirect('index.php?page=login');
        }

        $data = [
            'user' => $user,
            'title' => 'پروفایل کاربر',
        ];

        $this->view('user/show', $data);
    }
}
