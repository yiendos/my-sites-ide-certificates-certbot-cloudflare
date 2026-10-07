<?php

namespace Yiendos\MySitesIde\Certificates\CertbotCloudflare\Traits;

use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Yiendos\MySitesIde\Certificates\CertbotCloudflare\Paths;

/**
 * Runs certbot in a throwaway certbot-cloudflare container from the host.
 * Every call goes through `docker compose` from the IDE root, as the
 * my-sites-ide CLI is run there.
 */
trait InteractsWithCertbot
{
    /**
     * Adds --credentials, for commands that talk to Cloudflare
     *
     * @return static
     */
    protected function addCredentialsOption(): static
    {
        return $this->addOption('credentials', null, InputOption::VALUE_REQUIRED, 'Credentials file in ' . Paths::STORAGE . '/ - one per Cloudflare account', 'credentials.ini');
    }

    /**
     * The credentials file as certbot sees it, inside the container - or null,
     * having told the user what to do, when it isn't ready to use.
     *
     * A missing file is created from the stub, private to the user, so all
     * that's left is filling in the token. Certbot itself refuses (well,
     * warns about) a file other users can read.
     *
     * @param InputInterface $input
     * @param SymfonyStyle $io
     * @return string|null
     */
    protected function certbotCredentials(InputInterface $input, SymfonyStyle $io): ?string
    {
        $name = basename((string) $input->getOption('credentials'));
        $file = Paths::credentials($name);

        if (!file_exists($file)) {
            copy(Paths::package('stubs/credentials.ini'), $file);
            chmod($file, 0600);

            $io->warning([
                'Created ' . Paths::relative($file) . ' from the sample.',
                'Add your Cloudflare API token to it (it is git-ignored and private to you), then run this again.',
            ]);

            return null;
        }

        if (!$this->credentialsFilled($file)) {
            $io->warning('No Cloudflare credentials in ' . Paths::relative($file) . ' yet - fill in dns_cloudflare_api_token, then run this again.');
            return null;
        }

        if ((fileperms($file) & 0077) !== 0) {
            chmod($file, 0600);
            $io->note('Made ' . Paths::relative($file) . ' private to you (chmod 600) - it holds your Cloudflare credentials.');
        }

        return Paths::CONTAINER_CREDENTIALS . "/{$name}";
    }

    /**
     * Runs `certbot <arguments>` in a throwaway certbot-cloudflare container,
     * echoing the command first like the core commands do
     *
     * @param OutputInterface $output
     * @param string $arguments already shell-escaped
     * @return int the exit code
     */
    protected function certbot(OutputInterface $output, string $arguments): int
    {
        // make sure the store exists before Docker creates it as root
        Paths::certificates();

        $command = "docker compose run --rm certbot-cloudflare {$arguments}";

        $output->writeLn($command);
        passthru($command, $code);

        return $code;
    }

    /**
     * What to do once a certificate has been issued or renewed: servers only
     * read certificates when they (re)load
     *
     * @param SymfonyStyle $io
     * @param string $domain the certificate name, i.e. its live/ folder
     * @return void
     */
    protected function serverHint(SymfonyStyle $io, string $domain): void
    {
        $io->text([
            "For nginx, point the site's vhost (Repos/<site>/_build/config/*-nginx.conf) at it:",
            '',
            "    ssl_certificate /etc/nginx/ssl/live/{$domain}/fullchain.pem;",
            "    ssl_certificate_key /etc/nginx/ssl/live/{$domain}/privkey.pem;",
            '',
            'then reload nginx: php my-sites-ide servers:nginx-reload',
            '',
        ]);
    }

    /**
     * Whether any credential line has a value - checked without ever
     * echoing the file
     *
     * @param string $file
     * @return bool
     */
    private function credentialsFilled(string $file): bool
    {
        foreach (file($file, FILE_IGNORE_NEW_LINES) ?: [] as $line) {
            if (preg_match('/^\s*dns_cloudflare_(api_token|api_key)\s*=\s*\S/', $line)) {
                return true;
            }
        }

        return false;
    }
}
