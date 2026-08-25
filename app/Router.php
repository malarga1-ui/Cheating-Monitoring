<?php
/**
 * Tiny HTTP router — detects param types at registration time.
 */
final class Router
{
    private array $routes = [];

    public function add(string $method, string $path, $handler): void
    {
        // Detect first parameter type once at registration time (zero cost per request).
        $firstType = null;
        try {
            if (is_array($handler) && class_exists($handler[0]) && method_exists($handler[0], $handler[1])) {
                $ref = new \ReflectionMethod($handler[0], $handler[1]);
                $params = $ref->getParameters();
                if (!empty($params) && $params[0]->getType()) {
                    $firstType = $params[0]->getType()->getName();
                }
            } elseif (is_callable($handler)) {
                $ref = new \ReflectionFunction($handler);
                $params = $ref->getParameters();
                if (!empty($params) && $params[0]->getType()) {
                    $firstType = $params[0]->getType()->getName();
                }
            }
        } catch (\Throwable $e) {}

        $this->routes[] = [
            'method'    => strtoupper($method),
            'pattern'   => $this->compile($path),
            'handler'   => $handler,
            'firstType' => $firstType,
        ];
    }

    public function get(string $path, $handler): void
    {
        $this->add('GET', $path, $handler);
    }

    public function post(string $path, $handler): void
    {
        $this->add('POST', $path, $handler);
    }

    public function delete(string $path, $handler): void
    {
        $this->add('DELETE', $path, $handler);
    }

    public function any(string $path, $handler): void
    {
        $this->add('ANY', $path, $handler);
    }

    public function dispatch(string $method, string $path): void
    {
        foreach ($this->routes as $route) {
            if ($route['method'] !== 'ANY' && $route['method'] !== $method) {
                continue;
            }
            if (preg_match($route['pattern'], $path, $m)) {
                $named = [];
                foreach ($m as $k => $v) {
                    if (is_string($k)) {
                        $named[$k] = rawurldecode($v);
                    }
                }
                if (empty($named)) {
                    $route['handler']();
                } elseif ($route['firstType'] === 'array') {
                    // Handler expects array $params — pass associative array
                    $route['handler']($named);
                } else {
                    // Handler expects individual typed params — pass values in order
                    $route['handler'](...array_values($named));
                }
                return;
            }
        }
        Response::error('Not found', 404);
    }

    private function compile(string $path): string
    {
        $path = rtrim($path, '/') ?: '/';
        $path = preg_quote($path, '#');
        $path = preg_replace('#\\\{([a-zA-Z_][a-zA-Z0-9_]*)\\\}#', '(?P<$1>[^/]+)', $path);
        return '#^' . $path . '$#i';
    }
}
