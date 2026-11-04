# Frootbox Workbench

Runs many local Frootbox websites from one host and one `vendor/` with the
current packages of the monorepo (decisions 0098 and 0254). A website in
the workbench is a normal project folder (`composer.json`, `config/`,
`extensions/`, `.env`, `storage/`) without a `vendor/` of its own. Live,
every website keeps its own installation with the versions of its
`composer.lock`; the workbench is for development only.

## Set up

```bash
php path/to/frootbox2/packages/tools/workbench/bin/workbench init ~/Sites/frootbox-sites
cd ~/Sites/frootbox-sites && composer install
```

`init` writes `workbench.yaml`, `composer.json`, `public/index.php`,
`public/.htaccess` and `.gitignore` lines. Then create a host in a web
server with PHP 8.5, the name `fb.localhost`, the alias `*.fb.localhost`
and the document root `public/` of that folder. Browsers resolve
`*.localhost` without further setup. Without a web server,
`vendor/bin/workbench serve` runs the web server of PHP on port 8300.

In MAMP PRO:

1. Hosts, `+`: name `fb.localhost`, document root
   `~/Sites/frootbox-sites/public`, PHP 8.5.
2. Tab SSL: off. MAMP makes a certificate for `fb.localhost` only, so
   every website would show a warning, and it redirects HTTP to HTTPS.
3. Tab Web Server, additional parameters for `<VirtualHost>`:
   `ServerAlias *.fb.localhost`. The list of aliases in the tab General
   does not take `*`. AllowOverride stays `All` for `.htaccess`.
4. Save. With the ports of MAMP the addresses carry `:8888`, e.g.
   `http://fb.localhost:8888`; the links of the workbench leave the port
   out.

## Addresses

Every `<Vendor>/<Site>/` below a root of `workbench.yaml` answers at
`<site>.<vendor>.fb.localhost` and, while no other project has the same
site name, at `<site>.fb.localhost`. `fb.localhost` lists all projects.
Projects outside the roots are named under `projects:`.

```yaml
domain: fb.localhost
monorepo: ../frootbox2
roots:
  - .
projects:
  sandbox: ../frootbox2/sandbox
```

`workbench.local.yaml` (not in Git) adds roots and projects for one
computer.

## Packages

Active in a project is what its `composer.json` requires, like live,
plus its `require-dev` as in a development install (e.g. the migration
tool `frootbox/migrate-frootbox1`): packages from the central `vendor/` and packages in its path repositories
inside the project (`extensions/*`), whose classes the workbench loads at
runtime (PSR-4). Versions do not count. Shared code of a vendor lives in
`<Vendor>/packages/<name>/`, a library a website needs is added once with
`composer require` in the folder of the workbench. A package that is
missing shows on the page and on the start page.

The variables `APP_ENV`, `APP_SECRET`, `DATABASE_URL`, `MAILER_DSN` and
everything in the `.env` of a project never come from the environment of
the workbench, so each project keeps its own database.

## Commands

```bash
vendor/bin/workbench projects                 # addresses, folders, status
vendor/bin/workbench new Holl/Holl24          # skeleton, child theme, SQLite, superuser
vendor/bin/workbench run holl24 cache:clear   # any command of bin/frootbox
vendor/bin/workbench migrate holl24           # or --all
vendor/bin/workbench check                    # before a release: packages, blocks, migrations, pages
vendor/bin/workbench update                   # new packages of the monorepo, composer update
vendor/bin/workbench serve                    # without MAMP
vendor/bin/workbench build holl24 --zip       # a folder to upload, see below
```

With open migrations a project shows a notice with a button instead of
its pages; the database is backed up to `storage/backups/` first.
`new` and `run … install` take the superuser from `FROOTBOX_ADMIN_EMAIL`,
`FROOTBOX_ADMIN_NAME` and `FROOTBOX_ADMIN_PASSWORD`, from the shell or
from `sandbox.env` of the monorepo.

## Building a website to upload

`build` turns a project into a folder under `var/workbench/builds/` that
runs on a webspace as it is: its own `vendor/` with the packages copied
instead of linked, no `require-dev`, the files of the extensions
published, no symlinks, so FTP works. Composer leaves out what the
`.gitattributes` of a package mark `export-ignore`. Next to it are
`.env.live`, to rename to `.env` on the server, and `DEPLOY.md` with the
steps there. `--zip` adds a ZIP file.

The build holds code only, so an update never replaces the database or
the media of a running website. `--with-content` adds `storage/` (without
backups) and `public/media/`, for the first upload of a website made in
the workbench. Set `app.url` in `config/app.yaml` before; the build warns
without it.

## License

MIT
