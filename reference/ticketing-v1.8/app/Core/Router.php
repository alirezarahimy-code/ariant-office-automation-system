<?php
declare(strict_types=1);

namespace App\Core;

class Router
{
    private array $routes = [];
    private array $params = [];

    public function add(string $route, array $params = []): void
    {
        // Convert route to regex
        $route = preg_replace('/\//', '\\/', $route);
        $route = preg_replace('/\{([a-z]+)\}/', '(?P<\1>[a-z-]+)', $route);
        $route = '/^' . $route . '$/i';
        $this->routes[$route] = $params;
    }

    public function match(string $url): bool
    {
        foreach ($this->routes as $route => $params) {
            if (preg_match($route, $url, $matches)) {
                foreach ($matches as $key => $match) {
                    if (is_string($key)) {
                        $params[$key] = $match;
                    }
                }
                $this->params = $params;
                return true;
            }
        }
        return false;
    }

    public function getParams(): array
    {
        return $this->params;
    }

    public function dispatch(string $url): void
    {
        $url = trim($url, '/');
        
        if ($this->match($url)) {
            $controller = $this->params['controller'] ?? 'Dashboard';
            $controller = "App\\Controllers\\" . $controller . 'Controller';
            $action = $this->params['action'] ?? 'index';

            if (class_exists($controller)) {
                $controllerObject = new $controller();
                if (method_exists($controllerObject, $action)) {
                    call_user_func_array([$controllerObject, $action], array_values($this->params));
                    return;
                }
            }
        }
        
        // 404
        http_response_code(404);
        echo 'Page not found';
    }
}
