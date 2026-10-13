<?php
declare(strict_types=1);

namespace App\Services;

use App\Models\User;

class AuthService
{
    private User $userModel;

    public function __construct()
    {
        $this->userModel = new User();
    }

    public function authenticate(string $username, string $password): ?array
    {
        $user = $this->userModel->findByUsername($username);
        
        if (!$user) {
            return null;
        }
        
        if (!password_verify($password, (string) $user['password_hash'])) {
            return null;
        }
        
        if ((int) $user['is_active'] !== 1) {
            return null;
        }
        
        return $user;
    }

    public function login(array $user): void
    {
        session_regenerate_id(true);
        $_SESSION['user_id'] = (int) $user['id'];
        
        // Update last login
        $this->userModel->update((int) $user['id'], [
            'last_login_at' => date('Y-m-d H:i:s'),
        ]);
        
        // Log activity
        if (function_exists('activity_log')) {
            activity_log([
                'action_code' => 'login',
                'user_id' => (int) $user['id'],
                'username' => $user['username'],
                'full_name' => $user['full_name'],
                'role' => $user['role'],
                'module' => 'auth',
            ]);
        }
    }

    public function logout(): void
    {
        if (isset($_SESSION['user_id'])) {
            $user = $this->userModel->find((int) $_SESSION['user_id']);
            if ($user && function_exists('activity_log')) {
                activity_log([
                    'action_code' => 'logout',
                    'user_id' => (int) $user['id'],
                    'username' => $user['username'],
                    'full_name' => $user['full_name'],
                    'role' => $user['role'],
                    'module' => 'auth',
                ]);
            }
        }
        
        $_SESSION = [];
        
        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], (bool) $params['secure'], (bool) $params['httponly']);
        }
        
        session_destroy();
    }

    public function getCurrentUser(): ?array
    {
        if (empty($_SESSION['user_id'])) {
            return null;
        }
        
        return $this->userModel->find((int) $_SESSION['user_id']);
    }

    public function isLoggedIn(): bool
    {
        return !empty($_SESSION['user_id']);
    }

    public function hasPermission(string $permission): bool
    {
        $user = $this->getCurrentUser();
        if (!$user) {
            return false;
        }
        
        if (function_exists('user_can')) {
            return user_can($user, $permission);
        }
        
        return false;
    }

    public function requireAuth(): array
    {
        $user = $this->getCurrentUser();
        if (!$user) {
            header('Location: index.php?page=login');
            exit;
        }
        return $user;
    }

    public function requirePermission(string $permission): array
    {
        $user = $this->requireAuth();
        
        if (!$this->hasPermission($permission)) {
            http_response_code(403);
            exit('دسترسی به این بخش مجاز نیست.');
        }
        
        return $user;
    }
}
