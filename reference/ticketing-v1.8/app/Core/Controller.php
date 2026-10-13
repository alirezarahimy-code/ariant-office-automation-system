<?php
declare(strict_types=1);

namespace App\Core;

abstract class Controller
{
    protected array $data = [];

    protected function view(string $view, array $data = []): void
    {
        extract($data);
        $viewFile = APP_ROOT . '/app/Views/' . $view . '.php';
        if (file_exists($viewFile)) {
            require $viewFile;
        } else {
            throw new \RuntimeException("View {$view} not found");
        }
    }

    protected function json(array $data, int $statusCode = 200): void
    {
        http_response_code($statusCode);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    protected function redirect(string $url): void
    {
        header('Location: ' . $url);
        exit;
    }

    protected function set(string $key, mixed $value): void
    {
        $this->data[$key] = $value;
    }
}
