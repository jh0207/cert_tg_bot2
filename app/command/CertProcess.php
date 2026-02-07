<?php

namespace app\command;

use app\service\AcmeService;
use app\service\CertService;
use app\service\DnsService;
use think\console\Command;
use think\console\Input;
use think\console\Output;

class CertProcess extends Command
{
    protected function configure(): void
    {
        $this->setName('cert:process')
            ->setDescription('Process cert orders (DNS issue/renew/install).');
    }

    protected function execute(Input $input, Output $output): int
    {
        $service = new CertService(new AcmeService(), new DnsService());
        $result = $service->processCertTasks();

        $output->writeln(sprintf(
            'cert:process done. dns=%d issue=%d install=%d',
            (int) ($result['dns']['processed'] ?? 0),
            (int) ($result['issue']['processed'] ?? 0),
            (int) ($result['install']['processed'] ?? 0)
        ));

        return 0;
    }
}
