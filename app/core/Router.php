<?php
declare(strict_types=1);

namespace App\Core;

final class Router
{
    /** @var array<int, array{0:string,1:string,2:callable|array,3:array}> */
    private array $routes = [];

    public function get(string $path, callable|array $handler, array $middleware = []): void
    {
        $this->routes[] = ['GET', $path, $handler, $middleware];
    }

    public function post(string $path, callable|array $handler, array $middleware = []): void
    {
        $this->routes[] = ['POST', $path, $handler, $middleware];
    }

    public function dispatch(string $method, string $uri): void
    {
        $path = '/' . trim((string) parse_url($uri, PHP_URL_PATH), '/');
        $base = rtrim((string) config('app.base_path', ''), '/');
        if ($base !== '' && str_starts_with($path, $base)) {
            $path = '/' . trim(substr($path, strlen($base)), '/');
        }

        $allowed = false;
        foreach ($this->routes as [$m, $pattern, $handler, $middleware]) {
            $regex = '#^' . preg_replace('#\{(\w+)\}#', '(?P<$1>[^/]+)', $pattern) . '$#';
            if (!preg_match($regex, $path, $matches)) {
                continue;
            }
            $allowed = true;
            if ($m !== $method) {
                continue;
            }
            $params = array_filter($matches, 'is_string', ARRAY_FILTER_USE_KEY);

            if ($method === 'POST') {
                Csrf::verify();
            }
            foreach ($middleware as $mw) {
                Middleware::run($mw);
            }
            if (is_array($handler)) {
                [$class, $action] = $handler;
                (new $class())->$action(...array_values($params));
            } else {
                $handler(...array_values($params));
            }
            return;
        }

        http_response_code($allowed ? 405 : 404);
        View::render('errors/404', ['title' => 'Page not found'], 'public');
    }
}
