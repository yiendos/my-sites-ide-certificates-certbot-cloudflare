# Certbot (Cloudflare DNS) certificates

Real, browser-trusted Let's Encrypt certificates for your local sites in
[my-sites-ide](https://github.com/yiendos/my-sites-ide), issued by Certbot through Cloudflare's DNS
challenge. Certbot proves you control the domain by adding a TXT record through the Cloudflare API,
so the site never has to be reachable from the internet. A name like `local.example.com`, pointing
at `127.0.0.1`, works as long as `example.com` is on Cloudflare.

Written for: developers running sites in my-sites-ide who want a trusted certificate instead of the
IDE's self-signed one, including those moving over from `ide:ssl`, which used to ship inside the IDE.

## Contents

- [Installation](#installation)
- [Upgrading from the built-in ide:ssl](#upgrading-from-the-built-in-idessl)
- [Architecture](#architecture)
- [Issuing a certificate](#issuing-a-certificate)
- [Command reference](#command-reference)
- [Configuration](#configuration)
- [What it uses from the IDE](#what-it-uses-from-the-ide)
- [Troubleshooting](#troubleshooting)
- [Known gaps](#known-gaps)

## Installation

A [my-sites-ide](https://github.com/yiendos/my-sites-ide) plugin. Add it to the `require` section of the IDE's `composer.local.json`:

```json
"yiendos/my-sites-ide-certificates-certbot-cloudflare": "@dev"
```

Then, from the IDE root:

```
composer update
```

Composer's `post-autoload-dump` hook registers the `certificates:certbot-*` commands and the
`certbot-cloudflare` compose service. There's nothing to build, because the service uses the upstream
`certbot/dns-cloudflare` image. It isn't a long-running container, so don't add it to `APP`: each command
runs certbot in a throwaway container and removes it afterwards.

## Upgrading from the built-in ide:ssl

Certbot used to live in the IDE at `_dev/environment/certificates/certbot/`. What moved:

| Before | Now |
|---|---|
| `php my-sites-ide ide:ssl <domain>` | `php my-sites-ide certificates:certbot-create <domain>` |
| `_dev/environment/certificates/certbot/credentials.ini` | `storage/plugins/certbot-cloudflare/credentials.ini` |
| `_dev/environment/certificates/certbot/conf/` | `storage/certificates/` (the IDE's shared store) |
| compose service `certbot` | `certbot-cloudflare` |
| `DNS_CLOUDFLARE_EMAIL` / `DNS_CLOUDFLARE_API_KEY` in `.env` | not used - Certbot only ever read the credentials file |

nginx still finds certificates at `/etc/nginx/ssl/live/<domain>/`, so existing vhosts don't change.

Move your existing certificates and credentials across once, from the IDE root:

```
mkdir -p storage/certificates storage/plugins/certbot-cloudflare
rsync -a --exclude .gitkeep _dev/environment/certificates/certbot/conf/ storage/certificates/
cp -p _dev/environment/certificates/certbot/credentials.ini storage/plugins/certbot-cloudflare/
chmod 600 storage/plugins/certbot-cloudflare/credentials.ini
php my-sites-ide servers:nginx-start   # recreate nginx with the new certificate mounts
php my-sites-ide certificates:certbot-list
```

Once `certificates:certbot-list` shows your certificates, delete the old `_dev/environment/certificates/`
folder. The IDE's `.gitignore` keeps ignoring its old `credentials.ini` path in the meantime, so a
leftover copy can't be committed. The `DNS_CLOUDFLARE_*` lines can come out of your `.env` too.

Certificates issued before the move saved the old credentials path in their renewal config.
`certificates:certbot-renew` always passes the current credentials file, and Certbot updates the saved
path the next time it renews, so you don't need to edit anything.

## Architecture

```
host (my-sites-ide CLI)
  |- certificates:certbot-create <domain>  --> docker compose run --rm certbot-cloudflare certonly --dns-cloudflare ...
  |- certificates:certbot-renew            --> ... renew --dns-cloudflare ...
  |- certificates:certbot-list / -delete   --> ... certificates / delete
                                                  |
certbot-cloudflare container (throwaway)          |
  |- /etc/letsencrypt         <- storage/certificates/                       (read-write)
  |- /storage                 <- storage/plugins/certbot-cloudflare/         (credentials, mounted by the IDE)
  |- Cloudflare API: adds, then removes, a _acme-challenge TXT record
  |- Let's Encrypt: issues the certificate into live/<domain>/ and archive/<domain>/

nginx container (nginx plugin, with or without this plugin)
  |- /etc/nginx/ssl/live     <- storage/certificates/live
  |- /etc/nginx/ssl/archive  <- storage/certificates/archive
```

`storage/certificates/` belongs to the IDE, not to this plugin. Any certificate plugin can write to it,
and certificates stay there if this plugin is uninstalled. Without a certificate plugin it's empty,
and sites use the IDE's self-signed certificate.

The service has a compose profile (`certbot`), so `docker compose up` with no service list never starts it.

## Issuing a certificate

1. Create a Cloudflare API token with **Zone > DNS > Edit** for the zone (Cloudflare dashboard >
   My Profile > API Tokens > Create Token > "Edit zone DNS").
2. Run the create command once. It writes `storage/plugins/certbot-cloudflare/credentials.ini` from
   the sample, readable only by you, and stops:

   ```
   php my-sites-ide certificates:certbot-create local.example.com
   ```

3. Put the token in that file (`dns_cloudflare_api_token = ...`) and run the same command again.
4. Point the site's nginx vhost (`Repos/<site>/_build/config/*-nginx.conf`) at the certificate, as the
   command prints:

   ```
   ssl_certificate /etc/nginx/ssl/live/local.example.com/fullchain.pem;
   ssl_certificate_key /etc/nginx/ssl/live/local.example.com/privkey.pem;
   ```

5. Reload nginx: `php my-sites-ide servers:nginx-reload`.

For the browser to reach it, `local.example.com` has to resolve to your machine. Add an `A` record for
`127.0.0.1` in Cloudflare (DNS only, not proxied), or a line in `/etc/hosts`.

Several names on one certificate: `certificates:certbot-create local.example.com api.local.example.com`.
The first name is the certificate's name (its `live/` folder). Wildcards work too, as the DNS challenge
supports them: `certificates:certbot-create 'local.example.com' '*.local.example.com'`.

## Command reference

| Command | What it does |
|---|---|
| `certificates:certbot-create <domain>...` | Issue a certificate covering every domain given, named after the first. Creates the credentials file from the sample first if it's missing |
| `certificates:certbot-create <domain> --dry-run` | Same, against Let's Encrypt staging, saving nothing. Use it to check the token and zone before using up real issuance quota |
| `certificates:certbot-list` | Every certificate in `storage/certificates/`, with its domains and expiry date. Read-only, so it never contacts Cloudflare or Let's Encrypt |
| `certificates:certbot-renew` | Renew every certificate due to expire within 30 days |
| `certificates:certbot-renew <domain> --force` | Renew that one certificate now, whether it's due or not |
| `certificates:certbot-renew --dry-run` | Test renewal against staging, saving nothing |
| `certificates:certbot-delete <domain>` | Delete a certificate, so it stops being renewed. Certbot asks you to confirm |

`create` and `renew` take `--credentials=<file>` to use another file in `storage/plugins/certbot-cloudflare/`,
e.g. one per Cloudflare account. The default is `credentials.ini`.

Let's Encrypt certificates last 90 days. Nothing renews them automatically, so run
`certificates:certbot-renew` now and then (`certificates:certbot-list` shows what's due), then reload nginx.

## Configuration

The plugin has no `.env` options. Everything it keeps is in the IDE's `storage/`, which is git-ignored:

| Path | What |
|---|---|
| `storage/plugins/certbot-cloudflare/credentials.ini` | Your Cloudflare token (or email + Global API Key). Kept at `chmod 600`, and the commands tighten it if it's looser |
| `storage/certificates/` | Certificates, keys, renewal configs and the Let's Encrypt account - the whole of Certbot's `/etc/letsencrypt` |

**Never commit either.** This package's `.gitignore` also blocks `credentials.ini` and `*.pem`, in case
a copy ends up in a `Packages/` clone.

## What it uses from the IDE

| From the IDE | Used for |
|---|---|
| `storage/certificates/` | the shared certificate store, mounted as `/etc/letsencrypt` |
| `storage/plugins/certbot-cloudflare/` | the credentials - created and mounted at `/storage` by the IDE, as the plugin sets `"storage": true` |
| the [nginx plugin](https://github.com/yiendos/my-sites-ide-servers-nginx)'s `/etc/nginx/ssl/live` and `/archive` mounts | serving the certificates - the plugin doesn't touch nginx itself |
| `IDE_ROOT` (set by the CLI and `_dev/cache/ide.env`) | reaching `storage/` from `vendor/` |

## Troubleshooting

**`Unable to determine zone identifier` / `Error determining zone_id`.** The token can't see that domain's
zone. Check the token's Zone Resources include it, or that the domain's zone really is on Cloudflare.

**`Invalid request headers` / `Authentication error`.** The credentials file has the wrong kind of key:
a token goes in `dns_cloudflare_api_token`, a Global API Key in `dns_cloudflare_api_key` with
`dns_cloudflare_email`. Don't fill in both.

**`Unsafe permissions on credentials configuration file`.** Run any `create`/`renew` command, which
`chmod 600`s it, or do it yourself.

**nginx won't start after `certificates:certbot-delete`, or on a fresh machine.** A vhost still points at
`/etc/nginx/ssl/live/<domain>/`, but that certificate isn't in `storage/certificates/`. Issue it again, or
take the `ssl_certificate` lines out of the vhost.

**The browser still shows the old or self-signed certificate.** nginx only reads certificates when it
loads its config. Run `php my-sites-ide servers:nginx-reload`. If nginx was created before the
upgrade, recreate it once with `php my-sites-ide servers:nginx-start`, so it picks up the `storage/certificates` mounts.

**`too many certificates already issued`.** Let's Encrypt's rate limit (5 identical certificates a week).
Test with `--dry-run` first, which uses staging.

## Known gaps

- Renewal is manual. There's no cron or timer yet.
- The printed directives are for nginx only. The Apache plugin doesn't mount `storage/certificates/`
  yet, so Apache sites keep the self-signed certificate.
- Only Cloudflare DNS. Other providers (Route 53, DigitalOcean...) would be sibling plugins writing to
  the same `storage/certificates/`.
