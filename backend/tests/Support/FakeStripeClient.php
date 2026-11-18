<?php

namespace Tests\Support;

use Stripe\HttpClient\ClientInterface;

/**
 * Stand-in for Stripe's HTTP transport: the real stripe-php SDK (via Cashier) runs, but
 * requests are recorded and answered from canned responses keyed by "METHOD path-regex".
 */
class FakeStripeClient implements ClientInterface
{
    /** @var list<array{method: string, path: string, params: array<string, mixed>}> */
    public array $requests = [];

    /** @param array<string, array<string, mixed>|callable(array<string, mixed>): array<string, mixed>> $routes */
    public function __construct(private array $routes = []) {}

    /** @param array<string, mixed>|callable(array<string, mixed>): array<string, mixed> $response */
    public function on(string $method, string $pathRegex, array|callable $response): self
    {
        $this->routes[strtoupper($method).' '.$pathRegex] = $response;

        return $this;
    }

    public function request($method, $absUrl, $headers, $params, $hasFile, $apiMode = 'v1', $maxNetworkRetries = null)
    {
        $path = (string) parse_url($absUrl, PHP_URL_PATH);
        $this->requests[] = ['method' => strtoupper($method), 'path' => $path, 'params' => (array) $params];

        foreach ($this->routes as $key => $response) {
            [$m, $regex] = explode(' ', $key, 2);
            if ($m === strtoupper($method) && preg_match('#^'.$regex.'$#', $path)) {
                $body = is_callable($response) ? $response((array) $params) : $response;

                return [json_encode($body), 200, []];
            }
        }

        return [json_encode(['error' => ['message' => "No fake for {$method} {$path}", 'type' => 'invalid_request_error']]), 404, []];
    }

    /** @return array{method: string, path: string, params: array<string, mixed>}|null */
    public function last(string $method, string $pathRegex): ?array
    {
        foreach (array_reverse($this->requests) as $r) {
            if ($r['method'] === strtoupper($method) && preg_match('#^'.$pathRegex.'$#', $r['path'])) {
                return $r;
            }
        }

        return null;
    }
}
