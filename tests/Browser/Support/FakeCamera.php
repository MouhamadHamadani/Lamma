<?php

namespace Tests\Browser\Support;

use Pest\Browser\Playwright\Client;
use Pest\Browser\Playwright\Playwright;
use Pest\Browser\ServerManager;
use ReflectionProperty;
use RuntimeException;

use function Amp\Websocket\Client\connect;

/**
 * Chromium with a fake camera, for the selfie tests. The Pest browser plugin starts its Playwright server without `--unsafe`, which makes the
 * server ignore a client's launch `args`, so a camera flag can never reach the browser. This starts a second Playwright server of our own with
 * `--unsafe` and points the plugin's client at it, asking for `--use-fake-device-for-media-stream` (a synthetic video, no real webcam needed) and
 * `--use-fake-ui-for-media-stream` (the permission prompt answers itself). start() at the top of a test, stop() when it is done: the next test
 * goes back to the plugin's own server and plain browser (a camera it cannot open without a permission).
 */
class FakeCamera
{
    /** @var resource|null */
    private static $process = null;

    public static function start(): void
    {
        self::stopServer();

        $socket = stream_socket_server('tcp://127.0.0.1:0');
        $port = (int) substr((string) strrchr((string) stream_socket_get_name($socket, false), ':'), 1);
        fclose($socket);

        $command = '.'.DIRECTORY_SEPARATOR.'node_modules'.DIRECTORY_SEPARATOR.'.bin'.DIRECTORY_SEPARATOR."playwright run-server --host 127.0.0.1 --port {$port} --unsafe";
        $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, base_path());
        if (! is_resource($process)) {
            throw new RuntimeException('Could not start a Playwright server.');
        }
        self::$process = $process;
        stream_set_blocking($pipes[1], false);

        $output = '';
        for ($waited = 0; ! str_contains($output, 'Listening on') && $waited < 150; $waited++) {
            $output .= (string) fread($pipes[1], 4096);
            usleep(100_000);
        }
        if (! str_contains($output, 'Listening on')) {
            self::stopServer();

            throw new RuntimeException("The Playwright server did not start: {$output}");
        }

        self::connectTo("127.0.0.1:{$port}", ['args' => ['--use-fake-device-for-media-stream', '--use-fake-ui-for-media-stream']]);
    }

    public static function stop(): void
    {
        self::connectTo(ServerManager::instance()->playwright()->url(), []);
        self::stopServer();
    }

    /** @param  array<string, mixed>  $extra */
    private static function connectTo(string $url, array $extra): void
    {
        Playwright::close();

        $client = Client::instance();
        $connection = new ReflectionProperty($client, 'websocketConnection');
        $connection->getValue($client)?->close();

        $options = json_encode(['headless' => Playwright::isHeadless(), 'ignoreHTTPSErrors' => true, 'bypassCSP' => true, ...$extra]);
        $connection->setValue($client, connect("ws://{$url}?browser=chromium&launch-options={$options}"));
    }

    private static function stopServer(): void
    {
        if (self::$process === null) {
            return;
        }

        $pid = proc_get_status(self::$process)['pid'];
        PHP_OS_FAMILY === 'Windows' ? exec("taskkill /F /T /PID {$pid} 2>NUL") : proc_terminate(self::$process);
        proc_close(self::$process);
        self::$process = null;
    }
}
