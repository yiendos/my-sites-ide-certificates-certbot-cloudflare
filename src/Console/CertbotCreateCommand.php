<?php

namespace Yiendos\MySitesIde\Certificates\CertbotCloudflare\Console;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Yiendos\MySitesIde\Certificates\CertbotCloudflare\Traits\InteractsWithCertbot;

class CertbotCreateCommand extends Command
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
            ->setName('certificates:certbot-create')
            ->setDescription("Issue a trusted Let's Encrypt certificate for a domain on Cloudflare, via the DNS challenge")
            ->addArgument('domains', InputArgument::REQUIRED | InputArgument::IS_ARRAY, 'The domain(s) to cover, e.g. local.example.com - the first names the certificate')
            ->addCredentialsOption()
            ->addOption('dry-run', null, InputOption::VALUE_NONE, "Test against Let's Encrypt staging without saving a certificate")
        ;
    }

    /**
     * The DNS challenge means the domain never has to be reachable from the
     * internet - certbot proves control by adding a TXT record through the
     * Cloudflare API - so a local-only name like local.example.com works,
     * as long as its zone is on Cloudflare.
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

        $domains = $input->getArgument('domains');
        $arguments = 'certonly --dns-cloudflare --dns-cloudflare-credentials ' . escapeshellarg($credentials)
            . ' --cert-name ' . escapeshellarg($domains[0])
            . implode('', array_map(fn (string $domain): string => ' -d ' . escapeshellarg($domain), $domains))
            . ($input->getOption('dry-run') ? ' --dry-run' : '');

        if ($this->certbot($output, $arguments) !== 0) {
            $io->error('Certbot did not issue the certificate - see above.');
            return Command::FAILURE;
        }

        if ($input->getOption('dry-run')) {
            $io->success('Dry run passed - run it again without --dry-run for the real certificate.');
            return Command::SUCCESS;
        }

        $io->success("Certificate {$domains[0]} is in storage/certificates/live/{$domains[0]}/");
        $this->serverHint($io, $domains[0]);

        return Command::SUCCESS;
    }
}
