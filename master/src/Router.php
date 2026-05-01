<?php
declare(strict_types=1);

namespace Leikwan\Master;

use Leikwan\Master\Http\Request;
use Leikwan\Master\Http\Response;

final class Router
{
    /** @var array<int, array{method:string,pattern:string,handler:mixed}> */
    private array $routes = [];

    /**
     * Route handlers are often registered as [$controller, 'method'] descriptors.
     * Resolve them lazily so one broken or stale admin route does not abort the
     * entire application bootstrap and take unrelated APIs down with it.
     */
    public function add(string $method, string $pattern, mixed $handler): void
    {
        $this->routes[] = [
            'method' => strtoupper($method),
            'pattern' => $pattern,
            'handler' => $handler,
        ];
    }

    public function dispatch(Request $request): Response
    {
        foreach ($this->routes as $route) {
            if ($route['method'] !== $request->method()) {
                continue;
            }

            $regex = preg_replace('/\{([a-zA-Z_][a-zA-Z0-9_]*)\}/', '(?P<$1>[^/]+)', $route['pattern']);
            $regex = '#^' . $regex . '$#';
            if (!preg_match($regex, $request->path(), $matches)) {
                continue;
            }

            $params = [];
            foreach ($matches as $key => $value) {
                if (!is_int($key)) {
                    $params[$key] = $value;
                }
            }

            return $this->dispatchHandler($route['handler'], $route['method'], $route['pattern'], $request, $params);
        }

        return Response::json(404, [
            'error' => 'not_found',
            'path' => $request->path(),
        ]);
    }

    /**
     * @param array<string, string> $params
     */
    private function dispatchHandler(
        mixed $handler,
        string $method,
        string $pattern,
        Request $request,
        array $params
    ): Response {
        if (!is_callable($handler)) {
            $message = sprintf(
                'Route handler for %s %s is not callable: %s',
                $method,
                $pattern,
                $this->describeHandler($handler)
            );

            if (str_starts_with($request->path(), '/api/')) {
                return Response::json(500, [
                    'error' => 'invalid_route_handler',
                    'message' => $message,
                ]);
            }

            return Response::html(
                500,
                '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Application Error</title></head><body><h1>Application Error</h1><p>'
                . htmlspecialchars($message, ENT_QUOTES, 'UTF-8')
                . '</p></body></html>'
            );
        }

        $response = call_user_func($handler, $request, $params);
        if ($response instanceof Response) {
            return $response;
        }

        $message = sprintf(
            'Route handler for %s %s returned %s instead of %s',
            $method,
            $pattern,
            get_debug_type($response),
            Response::class
        );

        if (str_starts_with($request->path(), '/api/')) {
            return Response::json(500, [
                'error' => 'invalid_route_response',
                'message' => $message,
            ]);
        }

        return Response::html(
            500,
            '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Application Error</title></head><body><h1>Application Error</h1><p>'
            . htmlspecialchars($message, ENT_QUOTES, 'UTF-8')
            . '</p></body></html>'
        );
    }

    private function describeHandler(mixed $handler): string
    {
        if (is_array($handler) && count($handler) === 2) {
            $target = is_object($handler[0]) ? $handler[0]::class : (string) $handler[0];
            return $target . '::' . (string) $handler[1];
        }

        if (is_string($handler)) {
            return $handler;
        }

        return get_debug_type($handler);
    }
}
