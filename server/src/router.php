<?php

$GLOBALS['__routes'] = [];

function route(string $method, string $pattern, callable $fn): void {
    $GLOBALS['__routes'][] = [$method, $pattern, $fn];
}

function dispatch(string $method, string $path): void {
    foreach ($GLOBALS['__routes'] as [$m, $pattern, $fn]) {
        if ($m !== $method) continue;
        if (preg_match($pattern, $path, $matches)) {
            $fn(...array_slice($matches, 1));
            return;
        }
    }
    json_out(['error' => 'not found'], 404);
}
