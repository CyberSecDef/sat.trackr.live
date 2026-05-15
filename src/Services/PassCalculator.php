<?php

declare(strict_types=1);

namespace SatTrackr\Services;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use RuntimeException;

/**
 * Phase 2 chunk 6 server-side pass calculator.  Shells out to the
 * `bin/sgp4-passes.mjs` Node script via proc_open: TLE + observer +
 * window go in on stdin as JSON, the result comes back on stdout.
 *
 * Process spawn overhead is ~50-100ms cold; the returned passes are
 * cached for 6h by {@see PassCache} so the controller almost always
 * skips this path on the second hit.
 */
final class PassCalculator implements PassCalculatorInterface
{
    private const TIMEOUT_SECONDS = 15;

    public function __construct(
        private readonly string $scriptPath,
        private readonly string $nodeBinary = 'node',
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
    }

    /**
     * @param array{line1: string, line2: string} $tle
     * @param array{latitude: float, longitude: float, altitudeMeters: float} $observer
     * @return array{computed_at: string, count: int, passes: list<array<string, mixed>>}
     */
    public function compute(
        array $tle,
        array $observer,
        int $startMs,
        int $days = 7,
        float $minElevationDeg = 10.0,
        int $stepSeconds = 60,
    ): array {
        $job = [
            'tle'             => $tle,
            'observer'        => $observer,
            'startMs'         => $startMs,
            'days'            => $days,
            'minElevationDeg' => $minElevationDeg,
            'stepSeconds'     => $stepSeconds,
        ];

        $cmd = sprintf('%s %s', escapeshellcmd($this->nodeBinary), escapeshellarg($this->scriptPath));
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $process = proc_open($cmd, $descriptors, $pipes);
        if (!is_resource($process)) {
            throw new RuntimeException('Failed to spawn pass-calculator subprocess');
        }

        try {
            fwrite($pipes[0], json_encode($job, JSON_THROW_ON_ERROR));
            fclose($pipes[0]);

            $stdout = $this->readWithTimeout($pipes[1], self::TIMEOUT_SECONDS);
            $stderr = stream_get_contents($pipes[2]) ?: '';
            fclose($pipes[1]);
            fclose($pipes[2]);
        } finally {
            $exit = proc_close($process);
        }

        if ($exit !== 0) {
            $msg = trim($stderr) !== '' ? trim($stderr) : "exit {$exit}";
            $this->logger->warning('Pass calc failed: ' . $msg);
            throw new RuntimeException("Pass calculator exited {$exit}: {$msg}");
        }

        $decoded = json_decode($stdout, true);
        if (!is_array($decoded) || !isset($decoded['passes']) || !is_array($decoded['passes'])) {
            throw new RuntimeException('Pass calculator returned malformed output: ' . substr($stdout, 0, 200));
        }

        /** @var array{computed_at: string, count: int, passes: list<array<string, mixed>>} $decoded */
        return $decoded;
    }

    /** @param resource $stream */
    private function readWithTimeout($stream, int $seconds): string
    {
        $deadline = microtime(true) + $seconds;
        $buffer = '';
        stream_set_blocking($stream, false);
        while (microtime(true) < $deadline) {
            $chunk = fread($stream, 8192);
            if ($chunk === false) {
                break;
            }
            $buffer .= $chunk;
            if (feof($stream)) {
                return $buffer;
            }
            if ($chunk === '') {
                usleep(20_000); // 20ms
            }
        }
        return $buffer;
    }
}
