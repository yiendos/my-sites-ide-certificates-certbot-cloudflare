<?php

namespace Yiendos\MySitesIde\Certificates\CertbotCloudflare\Console;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Yiendos\MySitesIde\Certificates\CertbotCloudflare\Traits\InteractsWithCertbot;

class CertbotDeleteCommand extends Command
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
            ->setName('certificates:certbot-delete')
            ->setDescription('Delete a certificate from storage/certificates, so it is no longer renewed')
            ->addArgument('domain', InputArgument::REQUIRED, 'The certificate name, as certificates:certbot-list shows')
        ;
    }

    /**
     * Certbot asks for confirmation itself. Any vhost still pointing at the
     * certificate will stop nginx from loading - fix those first.
     *
     * @param OutputInterface $output
     * @param InputInterface $input
     * @param SymfonyStyle $io
     * @return integer
     */
    public function __invoke(OutputInterface $output, InputInterface $input, SymfonyStyle $io): int
    {
        $domain = $input->getArgument('domain');

        $io->note("Remove {$domain}'s ssl_certificate lines from any vhost first - nginx refuses to load a config pointing at a missing certificate.");

        return $this->certbot($output, 'delete --cert-name ' . escapeshellarg($domain)) === 0 ? Command::SUCCESS : Command::FAILURE;
    }
}
