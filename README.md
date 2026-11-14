# Omnitrix Installer

Creates new Omnitrix applications.

```sh
composer global require omnitrix/installer
omnitrix new my-api
```

`omnitrix new` checks that the PHP running it has what Omnitrix needs (PHP 8.3+,
ext-openswoole 26.2+, ext-redis 6.3+, ext-pdo_mysql), then runs
`composer create-project omnitrix/omnitrix my-api` and prints the next steps. Installing PHP and
the extensions is up to you; the installer only reports what is missing.

| Option | |
|---|---|
| `--dev` | install the latest development version |
| `--composer=PATH` | Composer executable to use |
| `--repository=JSON\|URL` | extra Composer repository, also added to the new project (repeatable) |
| `--skip-requirements` | create the project even if the platform check fails |

Make sure Composer's global `vendor/bin` directory is on your `PATH`
(`composer global config bin-dir --absolute`).

## Development

```sh
composer install
composer check      # validate, coding standards, static analysis, tests
```

## License

MIT, see [LICENSE](LICENSE).
