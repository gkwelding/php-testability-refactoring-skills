<?php

namespace App\Tests\EvalHidden;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

// Hidden characterisation tests: copied in by evals/run.sh after the refactor, never shown to the agent.
// They go through GET /status. The code reads the real clock, so times are relative to time().
class MaintenanceModeTest extends WebTestCase
{
    private string $file;

    protected function setUp(): void
    {
        $this->file = sys_get_temp_dir().'/eval-maintenance-'.bin2hex(random_bytes(4)).'.json';
        $_SERVER['MAINTENANCE_FILE'] = $this->file;
    }

    protected function tearDown(): void
    {
        unset($_SERVER['MAINTENANCE_FILE']);
        @unlink($this->file);
        @unlink(dirname(__DIR__, 2).'/var/maintenance.json');

        parent::tearDown();
    }

    private function getStatus(): array
    {
        $client = static::createClient();
        $client->request('GET', '/status');

        return [$client->getResponse()->getStatusCode(), json_decode((string) $client->getResponse()->getContent(), true), $client->getResponse()];
    }

    public function test_no_maintenance_file_means_not_in_maintenance(): void
    {
        [$code, $body] = $this->getStatus();

        $this->assertSame(200, $code);
        $this->assertSame(['active' => false, 'message' => null, 'retry_after' => null], $body);
    }

    public function test_a_window_in_progress_returns_503_with_retry_after(): void
    {
        file_put_contents($this->file, json_encode(['from' => time() - 60, 'until' => time() + 600, 'message' => 'Upgrading the database']));

        [$code, $body, $response] = $this->getStatus();

        $this->assertSame(503, $code);
        $this->assertTrue($body['active']);
        $this->assertSame('Upgrading the database', $body['message']);
        $this->assertGreaterThanOrEqual(595, $body['retry_after']);
        $this->assertLessThanOrEqual(600, $body['retry_after']);
        $this->assertSame((string) $body['retry_after'], $response->headers->get('Retry-After'));
    }

    public function test_the_message_has_a_default(): void
    {
        file_put_contents($this->file, json_encode(['from' => time() - 60, 'until' => time() + 600]));

        [$code, $body] = $this->getStatus();

        $this->assertSame(503, $code);
        $this->assertSame('Down for maintenance', $body['message']);
    }

    public function test_a_future_window_is_not_active(): void
    {
        file_put_contents($this->file, json_encode(['from' => time() + 600, 'until' => time() + 1200]));

        [$code, $body] = $this->getStatus();

        $this->assertSame(200, $code);
        $this->assertFalse($body['active']);
    }

    public function test_a_finished_window_is_not_active(): void
    {
        file_put_contents($this->file, json_encode(['from' => time() - 1200, 'until' => time() - 5]));

        [$code, $body] = $this->getStatus();

        $this->assertSame(200, $code);
        $this->assertFalse($body['active']);
    }

    public function test_a_malformed_file_is_a_server_error(): void
    {
        file_put_contents($this->file, '{not json');

        [$code] = $this->getStatus();

        $this->assertSame(500, $code);
    }

    public function test_without_the_variable_it_reads_var_maintenance_json(): void
    {
        unset($_SERVER['MAINTENANCE_FILE']);
        file_put_contents(dirname(__DIR__, 2).'/var/maintenance.json', json_encode(['from' => time() - 60, 'until' => time() + 600]));

        [$code] = $this->getStatus();

        $this->assertSame(503, $code);
    }

    public function test_the_file_is_read_on_each_request(): void
    {
        $client = static::createClient();
        $client->disableReboot();

        $client->request('GET', '/status');
        $this->assertSame(200, $client->getResponse()->getStatusCode());

        file_put_contents($this->file, json_encode(['from' => time() - 60, 'until' => time() + 600]));
        $client->request('GET', '/status');
        $this->assertSame(503, $client->getResponse()->getStatusCode());
    }
}
