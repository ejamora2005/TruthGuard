<?php

namespace Tests\Unit;

use App\Support\WindowsProcessEnvironment;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class WindowsProcessEnvironmentTest extends TestCase
{
    public function test_python_starts_when_web_sapi_environment_omits_windows_system_root(): void
    {
        if (PHP_OS_FAMILY !== 'Windows') {
            $this->assertSame([], WindowsProcessEnvironment::variables());

            return;
        }
        $server = $_SERVER;
        $environment = $_ENV;
        try {
            // Reproduce PHP cli-server's narrowed $_SERVER seen on real requests.
            $_SERVER = ['PATH' => getenv('PATH', true)];
            $_ENV = [];
            $process = new Process([(string) config('services.deepfake_cnn.python_binary', 'python'), '-c', 'import secrets; print("started" if secrets.token_bytes(16) else "failed")']);
            $method = new \ReflectionMethod($process, 'getDefaultEnv');
            $defaults = array_change_key_case($method->invoke($process), CASE_LOWER);
            $this->assertArrayNotHasKey('systemroot', $defaults);
            $restored = WindowsProcessEnvironment::variables();
            $this->assertSame(getenv('SystemRoot', true), $restored['SystemRoot']);
            $this->assertSame([], array_diff(array_keys($restored), ['SystemRoot', 'WINDIR', 'PATH', 'TEMP', 'TMP']));
            $process->setEnv($restored)->setTimeout(20)->run();
            $this->assertTrue($process->isSuccessful(), $process->getErrorOutput());
            $this->assertSame('started', trim($process->getOutput()));
        } finally {
            $_SERVER = $server;
            $_ENV = $environment;
        }
    }
}
