<?php

namespace app\service;

class AcmeService
{
    private string $acmePath;
    private string $exportPath;
    private string $acmeServer;
    private string $logFile;
    private int $timeoutSeconds = 180;

    public function __construct()
    {
        $config = config('tg');
        $this->acmePath = $config['acme_path'];
        $this->exportPath = rtrim($config['cert_export_path'], '/') . '/';
        $this->acmeServer = $config['acme_server'] ?? 'letsencrypt';
        $this->logFile = $this->resolveLogFile();
    }

    public function issueDryRun($domains): array
    {
        $args = [
            $this->acmePath,
            '--issue',
            '--dns',
            '--server',
            $this->acmeServer,
            '--yes-I-know-dns-manual-mode-enough-go-ahead-please',
        ];
        foreach ($this->normalizeDomains($domains) as $domain) {
            $args[] = '-d';
            $args[] = $domain;
        }

        return $this->run($args);
    }

    public function issueDns($domains): array
    {
        $args = [
            $this->acmePath,
            '--issue',
            '--dns',
            '--server',
            $this->acmeServer,
            '--force',
            '--yes-I-know-dns-manual-mode-enough-go-ahead-please',
        ];
        foreach ($this->normalizeDomains($domains) as $domain) {
            $args[] = '-d';
            $args[] = $domain;
        }

        return $this->run($args);
    }

    public function renew($domains): array
    {
        $args = [
            $this->acmePath,
            '--renew',
            '--dns',
            '--server',
            $this->acmeServer,
            '--yes-I-know-dns-manual-mode-enough-go-ahead-please',
        ];
        foreach ($this->normalizeDomains($domains) as $domain) {
            $args[] = '-d';
            $args[] = $domain;
        }

        return $this->run($args);
    }

    public function installCert(string $domain, ?string $exportDir = null): array
    {
        $exportDir = $this->normalizeExportDir($domain, $exportDir);
        $this->ensureExportDir($exportDir);
        $keyFile = $exportDir . 'key.key';
        $fullchainFile = $exportDir . 'fullchain.cer';
        $certFile = $exportDir . 'cert.cer';
        $caFile = $exportDir . 'ca.cer';

        return $this->run([
            $this->acmePath,
            '--install-cert',
            '-d',
            $domain,
            '--key-file',
            $keyFile,
            '--fullchain-file',
            $fullchainFile,
            '--cert-file',
            $certFile,
            '--ca-file',
            $caFile,
        ]);
    }

    public function exportExistingCert(string $domain, ?string $exportDir = null): array
    {
        $exportDir = $this->normalizeExportDir($domain, $exportDir);
        $this->ensureExportDir($exportDir);
        $sourceDir = $this->resolveAcmeCertDir($domain);
        if (!is_dir($sourceDir)) {
            return [
                'success' => false,
                'output' => "acme cert dir not found: {$sourceDir}",
                'stdout' => '',
                'stderr' => '',
            ];
        }

        $mapping = [
            'cert.cer' => $sourceDir . DIRECTORY_SEPARATOR . "{$domain}.cer",
            'key.key' => $sourceDir . DIRECTORY_SEPARATOR . "{$domain}.key",
            'ca.cer' => $sourceDir . DIRECTORY_SEPARATOR . 'ca.cer',
            'fullchain.cer' => $sourceDir . DIRECTORY_SEPARATOR . 'fullchain.cer',
        ];
        $targets = [
            'cert.cer' => $exportDir . 'cert.cer',
            'key.key' => $exportDir . 'key.key',
            'ca.cer' => $exportDir . 'ca.cer',
            'fullchain.cer' => $exportDir . 'fullchain.cer',
        ];

        foreach ($mapping as $key => $path) {
            if (!is_file($path)) {
                return [
                    'success' => false,
                    'output' => "acme cert file missing: {$path}",
                    'stdout' => '',
                    'stderr' => '',
                ];
            }
            if (!@copy($path, $targets[$key])) {
                return [
                    'success' => false,
                    'output' => "failed to copy {$path} to {$targets[$key]}",
                    'stdout' => '',
                    'stderr' => '',
                ];
            }
        }

        return [
            'success' => true,
            'output' => 'acme cert exported from existing files',
            'stdout' => '',
            'stderr' => '',
        ];
    }

    public function removeOrder(string $domain): array
    {
        return $this->run([
            $this->acmePath,
            '--remove',
            '-d',
            $domain,
        ]);
    }

    private function run(array $args): array
    {
        $safeArgs = array_map('escapeshellarg', $args);
        $command = implode(' ', $safeArgs);
        $this->logAcme('acme_command_start', ['command' => $command]);

        $descriptor = [
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $process = proc_open($command, $descriptor, $pipes);
        if (!is_resource($process)) {
            $this->logAcme('acme_command_failed', ['command' => $command, 'error' => 'proc_open failed']);
            return ['success' => false, 'output' => 'Failed to start acme.sh'];
        }

        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);
        $stdout = '';
        $stderr = '';
        $timedOut = false;
        $start = time();
        while (true) {
            $stdout .= (string) stream_get_contents($pipes[1]);
            $stderr .= (string) stream_get_contents($pipes[2]);

            $status = proc_get_status($process);
            if (!$status['running']) {
                break;
            }
            if ((time() - $start) >= $this->timeoutSeconds) {
                $timedOut = true;
                proc_terminate($process);
                break;
            }
            usleep(100000);
        }
        $stdout .= (string) stream_get_contents($pipes[1]);
        $stderr .= (string) stream_get_contents($pipes[2]);
        foreach ($pipes as $pipe) {
            fclose($pipe);
        }

        $exitCode = proc_close($process);
        if ($timedOut) {
            $exitCode = $exitCode === 0 ? 124 : $exitCode;
        }
        $this->logAcme('acme_command_done', [
            'command' => $command,
            'exit_code' => $exitCode,
            'timed_out' => $timedOut,
            'stdout' => trim($stdout),
            'stderr' => trim($stderr),
        ]);
        return [
            'success' => $exitCode === 0 && !$timedOut,
            'output' => trim($stdout . "\n" . $stderr),
            'stdout' => trim($stdout),
            'stderr' => trim($stderr),
        ];
    }

    private function logAcme(string $message, array $context = []): void
    {
        $line = date('Y-m-d H:i:s') . ' ' . $message;
        if ($context !== []) {
            $line .= ' ' . json_encode($context, JSON_UNESCAPED_UNICODE);
        }
        $line .= PHP_EOL;
        @file_put_contents($this->logFile, $line, FILE_APPEND | LOCK_EX);
    }

    private function resolveLogFile(): string
    {
        $base = function_exists('root_path') ? root_path() : dirname(__DIR__, 2) . DIRECTORY_SEPARATOR;
        $logDir = rtrim($base, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'runtime' . DIRECTORY_SEPARATOR . 'log';
        if (!is_dir($logDir)) {
            @mkdir($logDir, 0755, true);
        }
        return $logDir . DIRECTORY_SEPARATOR . 'acme.log';
    }

    private function normalizeDomains($domains): array
    {
        if (is_array($domains)) {
            return $domains;
        }

        return [$domains];
    }

    private function ensureExportDir(string $exportDir): void
    {
        $dir = rtrim($exportDir, '/');
        if (!@is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
    }

    private function normalizeExportDir(string $domain, ?string $exportDir): string
    {
        if ($exportDir) {
            return rtrim($exportDir, '/') . '/';
        }
        return $this->exportPath . $domain . '/';
    }

    private function resolveAcmeCertDir(string $domain): string
    {
        $home = getenv('HOME');
        if (!$home) {
            $home = function_exists('root_path') ? root_path() : dirname(__DIR__, 2);
        }
        return rtrim($home, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . '.acme.sh' . DIRECTORY_SEPARATOR . $domain . '_ecc';
    }
}
