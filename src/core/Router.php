<?php
declare(strict_types=1);

namespace App\Core;

final class Router
{
    private array $routes = [];

    public function add(string $method, string $path, $handler): void
    {
        $method = strtoupper($method);

        if (is_object($handler) && !is_callable($handler)) {
            throw new \InvalidArgumentException('Route handler must be callable or invokable object');
        }

        $paramNames = [];

        $regex = preg_replace_callback(
            '/\{([a-zA-Z_][a-zA-Z0-9_]*)(?::([^}]+))?\}/',
            function ($m) use (&$paramNames) {
                $name = $m[1];
                $pattern = $m[2] ?? '[^\/]+';
                $paramNames[] = $name;
                return '(' . $pattern . ')';
            },
            $path
        );

        $regex = '#^' . $regex . '$#';

        $this->routes[] = [
            'method'  => $method,
            'regex'   => $regex,
            'params'  => $paramNames,
            'handler' => $handler,
        ];
    }

    public function dispatch(string $method, string $uri): void
    {
        $method = strtoupper($method);
        $path = parse_url($uri, PHP_URL_PATH) ?: '/';

        foreach ($this->routes as $r) {
            if ($r['method'] !== $method) continue;

            if (preg_match($r['regex'], $path, $matches)) {
                array_shift($matches);

                $params = [];
                foreach ($r['params'] as $i => $name) {
                    $params[$name] = $matches[$i] ?? null;
                }

                $handler = $r['handler'];

                if (is_object($handler) && is_callable($handler)) {
                    $handler($params);
                    return;
                }

                $ref = new \ReflectionFunction(\Closure::fromCallable($handler));
                $argc = $ref->getNumberOfParameters();

                if ($argc === 0) $handler();
                else $handler($params);

                return;
            }
        }

        throw new HttpException(404, 'NOT_FOUND', ['path' => $path], 'Route not found');
    }
}
