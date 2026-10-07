<?php

namespace Yiendos\MySitesIde\Certificates\CertbotCloudflare\Console;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Yiendos\MySitesIde\Certificates\CertbotCloudflare\Traits\InteractsWithCertbot;

class CertbotListCommand extends Command
{
    use InteractsWithCertbot;

    /**
     * The ability to configure the console command
     *
     * @return void
     */
    protected function configure(): void
    {
        $this
            ->setName('certificates:certbot-list')
            ->setDescription('List the certificates in storage/certificates, with their domains and expiry dates')
        ;
    }

    /**
     * Read-only - `certbot certificates` never contacts Cloudflare or Let's Encrypt
     *
     * @param OutputInterface $output
     * @param InputInterface $input
     * @return integer
     */
    public function __invoke(OutputInterface $output, InputInterface $input): int
    {
        return $this->certbot($output, 'certificates') === 0 ? Command::SUCCESS : Command::FAILURE;
    }
}
