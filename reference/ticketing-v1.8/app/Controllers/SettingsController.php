<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Controller;

class SettingsController extends Controller
{
    public function index(): void
    {
        $user = current_user();
        if (!$user) {
            $this->redirect('index.php?page=login');
        }

        $data = [
            'user' => $user,
            'title' => 'تنظیمات',
        ];

        $this->view('settings/index', $data);
    }

    public function general(): void
    {
        $user = current_user();
        if (!$user) {
            $this->redirect('index.php?page=login');
        }

        $data = [
            'user' => $user,
            'title' => 'تنظیمات عمومی',
        ];

        $this->view('settings/general', $data);
    }
}
