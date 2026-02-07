<?php

namespace app\service;

class AcmeService
{
    private string $acmePath;
    private string $exportPath;
    private string $acmeServer;

    public function __construct()
    {
        $config = config('tg');
        $this->acmePath = $config['acme_path'];
        $this->exportPath = rtrim($config['cert_export_path'], '/') . '/';
        $this->acmeServer = $config['acme_server'] ?? 'letsencrypt';
    }

    public function issueDryRun($domains): array
    {
        $args = [
            $this->acmePath,
            '--issue',
            '--dns',
            '--dry-run',
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
            '--server',
            $this->acmeServer,
        ];
        foreach ($this->normalizeDomains($domains) as $domain) {
            $args[] = '-d';
            $args[] = $domain;
        }

        return $this->run($args);
    }

    public function installCert(string $domain): array
    {
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

        $descriptor = [
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $process = proc_open($command, $descriptor, $pipes);
        if (!is_resource($process)) {
            return ['success' => false, 'output' => 'Failed to start acme.sh'];
        }

        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        foreach ($pipes as $pipe) {
            fclose($pipe);
        }

        $exitCode = proc_close($process);
        return [
            'success' => $exitCode === 0,
            'output' => trim($stdout . "\n" . $stderr),
        ];
    }

    private function normalizeDomains($domains): array
    {
        if (is_array($domains)) {
            return $domains;
        }

        return [$domains];
    }
}
