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

    public function installCert(string $domain): array
    {
        $this->ensureExportDir($domain);
        $keyFile = $this->exportPath . $domain . '/key.key';
        $fullchainFile = $this->exportPath . $domain . '/fullchain.cer';
        $certFile = $this->exportPath . $domain . '/cert.cer';
        $caFile = $this->exportPath . $domain . '/ca.cer';

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

    private function ensureExportDir(string $domain): void
    {
        $dir = $this->exportPath . $domain;
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
    }
}
