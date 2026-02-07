<?php

namespace app\service;

class DnsService
{
    public function parseTxtRecords(string $output): ?array
    {
        $lines = preg_split('/\r?\n/', $output);
        $host = '';
        $values = [];
        $currentHost = '';

        foreach ($lines as $line) {
            if (preg_match("/Domain:\s*'([^']+)'/i", $line, $matches)) {
                $currentHost = trim($matches[1]);
                if ($host === '') {
                    $host = $currentHost;
                }
                continue;
            }

            if (preg_match("/TXT\s+value:\s*'([^']+)'/i", $line, $matches)) {
                $value = trim($matches[1]);
                if ($currentHost !== '' && $host === '') {
                    $host = $currentHost;
                }
                if ($value !== '') {
                    $values[] = $value;
                }
                continue;
            }

            if (strpos($line, '_acme-challenge.') !== false) {
                if (preg_match('/(_acme-challenge\.[^\s]+)\s+TXT\s+value:\s+(.+)/', $line, $matches)) {
                    $host = $host !== '' ? $host : trim($matches[1]);
                    $value = trim($matches[2], " \t\n\r\0\x0B'\"");
                    if ($value !== '') {
                        $values[] = $value;
                    }
                }
            }
        }

        $values = array_values(array_unique(array_filter($values, static function ($value) {
            return $value !== '';
        })));

        if ($host === '' || $values === []) {
            return null;
        }

        return [
            'host' => $host,
            'values' => $values,
        ];
    }

    public function verifyTxt(string $host, array $values): bool
    {
        if ($host === '' || $values === []) {
            return false;
        }

        $records = dns_get_record($host, DNS_TXT);
        if (!is_array($records) || $records === []) {
            return false;
        }

        foreach ($values as $value) {
            $expected = trim($value, '\"');
            $matched = false;
            foreach ($records as $record) {
                $txt = $record['txt'] ?? '';
                if ($txt === $value || $txt === $expected || strpos($txt, $expected) !== false) {
                    $matched = true;
                    break;
                }
            }
            if (!$matched) {
                return false;
            }
        }

        return true;
    }
}
