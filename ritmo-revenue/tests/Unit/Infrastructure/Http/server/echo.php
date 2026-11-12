<?php
/**
 * Router of the local test server (php -S 127.0.0.1:<port> echo.php): answers every request
 * with what it received, so the HTTP client tests can check what really went over the wire.
 */

declare(strict_types=1);

$path = (string) parse_url((string) $_SERVER['REQUEST_URI'], PHP_URL_PATH);

if ($path === '/redirect') {
    header('Location: /echo?redirected=1', true, 302);

    return;
}
if (preg_match('#^/status/(\d{3})$#', $path, $match) === 1) {
    http_response_code((int) $match[1]);
}
if ($path === '/not-json') {
    header('Content-Type: text/plain');
    echo 'plain text';

    return;
}

$headers = [];
foreach ($_SERVER as $name => $value) {
    if (str_starts_with($name, 'HTTP_')) {
        $headers[strtolower(str_replace('_', '-', substr($name, 5)))] = $value;
    }
}

header('Content-Type: application/json');
header('X-Five-Min-Limit-Remaining: 42');
header('X-Echo: one', false);
header('X-Echo: two', false);
echo json_encode([
    'method' => $_SERVER['REQUEST_METHOD'],
    'uri' => $_SERVER['REQUEST_URI'],
    'headers' => $headers,
    'body' => file_get_contents('php://input'),
]);
