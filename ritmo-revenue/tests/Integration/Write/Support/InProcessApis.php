<?php

declare(strict_types=1);

namespace Ritmo\Tests\Integration\Write\Support;

use Ritmo\Contracts\Exception\ConnectorException;
use Ritmo\Contracts\HttpClient;
use Ritmo\Contracts\HttpResponse;
use Ritmo\Tests\Support\Fixtures;
use RitmoFakeApis\Journal;
use RitmoFakeApis\Scenario;
use RitmoFakeApis\Simulator;
use RuntimeException;

require_once dirname(__DIR__, 3) . '/fake-apis/router.php';

/**
 * The simulator of tests/fake-apis answered in this process, as the wire behind the real HTTP
 * guard: the connectors keep their production base URLs and this client maps them to the
 * simulator's paths, so nothing ever leaves the process. It adds what a server cannot show
 * from outside:
 * - a crash: after the Nth write reached the simulator, the answer is lost and the "process
 *   dies" (SimulatedCrash, an Error nobody catches);
 * - a lost answer (timeout) before or after the write applied.
 */
final class InProcessApis implements HttpClient
{
    private const HOSTS = ['https://api.pricelabs.co/v1' => '/pricelabs/v1', 'https://api.beds24.com/v2' => '/beds24/v2'];

    private Simulator $simulator;

    private readonly string $log;

    /** @var list<string> "METHOD /path" of every request received */
    private array $lines = [];

    private int $writes = 0;

    private ?int $crashAfterWrites = null;

    /** @var list<array{method: string, path: string, applied: bool}> */
    private array $timeouts = [];

    public function __construct()
    {
        $log = tempnam(sys_get_temp_dir(), 'ritmo-write-sim');
        if ($log === false) {
            throw new RuntimeException('No temporary file for the simulator.');
        }
        $this->log = $log;
        $this->simulator = new Simulator(Scenario::load(Fixtures::path('api/scenario.json')), new Journal($log));
    }

    public function __destruct()
    {
        foreach ([$this->log, $this->log . '.state', $this->log . '.writes', $this->log . '.scenario'] as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
    }

    public function request(string $method, string $url, array $headers = [], ?string $body = null, int $timeout = 30): HttpResponse
    {
        [$path, $query] = $this->map($url);
        $this->lines[] = $method . ' ' . $path;
        $lost = $this->takeTimeout($method, $path);
        if ($lost !== null && !$lost) {
            throw new ConnectorException('No response from the simulator: timeout.', '', null, true);
        }
        $reply = $this->simulator->handle($method, $path, $query, array_change_key_case($headers, CASE_LOWER), $body ?? '');
        if ($lost !== null) {
            throw new ConnectorException('No response from the simulator: timeout.', '', null, true);
        }
        if ($method !== 'GET' && !str_ends_with($path, '/listing_prices') && $this->crashAfterWrites !== null && ++$this->writes >= $this->crashAfterWrites) {
            $this->crashAfterWrites = null;

            throw new SimulatedCrash('The process died after the write reached the simulator.');
        }

        return new HttpResponse($reply->status, $reply->headers, $reply->body);
    }

    /**
     * The same world with more bookings (as the PMS would serve them from now on); what the
     * write runs stored is kept.
     *
     * @param list<array<string, mixed>> $bookings in the scenario's format
     */
    public function addBookings(array $bookings): void
    {
        $data = json_decode((string) file_get_contents(Fixtures::path('api/scenario.json')), true, 32, JSON_THROW_ON_ERROR);
        $data['bookings'] = [...$data['bookings'], ...$bookings];
        $file = $this->log . '.scenario';
        file_put_contents($file, json_encode($data, JSON_THROW_ON_ERROR));
        $this->simulator = new Simulator(Scenario::load($file), new Journal($this->log));
    }

    public function enableWrites(array $notReady = []): void
    {
        $this->control('POST', '/__writes', ['not_ready' => $notReady]);
    }

    /**
     * @param string $mode "status", "apply_then_fail" or "drop_silently"
     */
    public function fault(string $method, string $path, int $status, string $mode = 'status', int $times = 1): void
    {
        $this->control('POST', '/__fault', ['method' => $method, 'path' => $path, 'status' => $status, 'mode' => $mode, 'times' => $times]);
    }

    /**
     * The next write request to the path is lost: before it reaches the simulator, or after it applied.
     */
    public function timeout(string $method, string $path, bool $applied): void
    {
        $this->timeouts[] = ['method' => $method, 'path' => $path, 'applied' => $applied];
    }

    public function crashAfterWrites(int $writes): void
    {
        $this->writes = 0;
        $this->crashAfterWrites = $writes;
    }

    /**
     * @return array<string, mixed> what the writable world holds (WriteWorld::dump())
     */
    public function world(): array
    {
        return $this->control('GET', '/__writes');
    }

    /**
     * @return list<array{method: string, path: string, body: mixed}> every write body received
     */
    public function writeBodies(): array
    {
        return $this->world()['writes'] ?? [];
    }

    /**
     * @return list<string> "METHOD /path" of the requests that change something
     */
    public function writeLines(): array
    {
        return array_values(array_filter($this->lines, static fn (string $l): bool => !str_starts_with($l, 'GET ') && !str_ends_with($l, '/listing_prices')));
    }

    /**
     * @param array<string, mixed> $body
     *
     * @return array<string, mixed>
     */
    private function control(string $method, string $path, array $body = []): array
    {
        $reply = $this->simulator->handle($method, $path, '', [], $body === [] ? '' : json_encode($body, JSON_THROW_ON_ERROR));
        $data = json_decode($reply->body, true);

        return is_array($data) ? $data : [];
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function map(string $url): array
    {
        foreach (self::HOSTS as $base => $prefix) {
            if (str_starts_with($url, $base . '/')) {
                $rest = substr($url, strlen($base));
                $query = (string) parse_url($url, PHP_URL_QUERY);

                return [$prefix . (string) parse_url($rest, PHP_URL_PATH), $query];
            }
        }

        throw new RuntimeException('The in-process simulator only answers the two production base URLs.');
    }

    private function takeTimeout(string $method, string $path): ?bool
    {
        foreach ($this->timeouts as $i => $timeout) {
            if ($timeout['method'] === $method && $timeout['path'] === $path) {
                array_splice($this->timeouts, $i, 1);

                return $timeout['applied'];
            }
        }

        return null;
    }
}
