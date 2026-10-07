<?php

namespace Yiendos\MySitesIde\Certificates\CertbotCloudflare\Console;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Yiendos\MySitesIde\Certificates\CertbotCloudflare\Traits\InteractsWithCertbot;

class CertbotRenewCommand extends Command
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
            ->setName('certificates:certbot-renew')
            ->setDescription('Renew certificates due to expire within 30 days - or one certificate, with --force')
            ->addArgument('domain', InputArgument::OPTIONAL, 'Only this certificate (its name, as certificates:certbot-list shows)')
            ->addCredentialsOption()
            ->addOption('force', null, InputOption::VALUE_NONE, 'Renew even if the certificate is not due yet')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, "Test renewal against Let's Encrypt staging without saving anything")
        ;
    }

    /**
     * Passes the credentials explicitly rather than trusting each renewal
     * config's saved path: certificates issued before this was a plugin
     * saved the old in-IDE path, which no longer exists. Certbot rewrites
     * the saved path on a successful renewal.
     *
     * @param OutputInterface $output
     * @param InputInterface $input
     * @param SymfonyStyle $io
     * @return integer
     */
    public function __invoke(OutputInterface $output, InputInterface $input, SymfonyStyle $io): int
    {
        $credentials = $this->certbotCredentials($input, $io);

        if ($credentials === null) {
            return Command::FAILURE;
        }

        $domain = $input->getArgument('domain');
        $arguments = 'renew --dns-cloudflare --dns-cloudflare-credentials ' . escapeshellarg($credentials)
            . ($domain ? ' --cert-name ' . escapeshellarg($domain) : '')
            . ($input->getOption('force') ? ' --force-renewal' : '')
            . ($input->getOption('dry-run') ? ' --dry-run' : '');

        if ($this->certbot($output, $arguments) !== 0) {
            $io->error('Renewal failed - see above.');
            return Command::FAILURE;
        }

        if (!$input->getOption('dry-run')) {
            $io->text(['Servers keep using the old certificate until they reload:', 'docker compose exec nginx nginx -s reload', '']);
        }

        return Command::SUCCESS;
    }
}
