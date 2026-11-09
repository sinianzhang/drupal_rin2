# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project overview

A Drupal 11 site built from the `drupal/recommended-project` Composer template and run locally with DDEV. It is currently a near-empty scaffold: there are no custom modules or themes yet, and the site is not under git.

The project rebuilds a Laravel coding assignment ("Web app with Notifications", code name RIN2) in Drupal 11. The spec is `requirements/Web app with Notifications.pdf`. The step-by-step build plan, open decisions and progress log are in `requirements/PLAN.md`. Read it before starting work and keep its checkboxes and progress log up to date.

## Environment

- DDEV project `my-drupal11-site` (type `drupal11`), PHP 8.4, nginx-fpm, MariaDB 11.8, docroot `web/`.
- Run every PHP, Composer and Drush command inside the container with the `ddev` prefix. The host PHP is not the runtime.
- `web/sites/default/settings.php` includes `settings.ddev.php`, which DDEV generates and which holds the DB credentials. Don't edit the generated file. `settings.local.php` inclusion is commented out at the bottom of `settings.php`.
- Config sync directory is `config/sync` at the project root (set in `settings.php` as `../config/sync`, relative to `web/`). Commit exported config.

## Common commands

```bash
ddev start                       # start containers
ddev launch                      # open the site in a browser
ddev composer require drupal/<module>   # add contrib (lands in web/modules/contrib)
ddev drush site:install --account-name=admin -y   # install Drupal if not yet installed
ddev drush uli                   # one-time admin login link
ddev drush en <module> -y        # enable a module
ddev drush cr                    # rebuild caches (needed after adding hooks, services, routes, plugins)
ddev drush cex -y / ddev drush cim -y   # export / import configuration
ddev drush updb -y               # run database updates after composer updates
ddev xdebug on|off               # toggle Xdebug (disabled by default)
```

No dev tooling is installed yet: no `drupal/core-dev`, PHPUnit, PHPCS or PHPStan. To add testing and linting, run `ddev composer require --dev drupal/core-dev`. Then:

```bash
ddev exec vendor/bin/phpunit -c web/core web/modules/custom/<module>                 # module tests
ddev exec vendor/bin/phpunit -c web/core --filter <testMethod> path/to/SomeTest.php  # single test
ddev exec vendor/bin/phpcs --standard=Drupal,DrupalPractice web/modules/custom
```

Use `ddev drush generate` (Drush Code Generator) to scaffold modules, plugins, forms, services, etc.

## Code layout (from composer.json `installer-paths`)

- `web/core`, `web/modules/contrib`, `web/themes/contrib`, `web/profiles/contrib`, `vendor/`: managed by Composer. Never edit these; use patches if a change is needed.
- `web/modules/custom/`, `web/themes/custom/`: project code goes here. These directories don't exist yet.
- `recipes/`: Drupal recipes (`type:drupal-recipe`). `drupal/core-recipe-unpack` is enabled, so when a recipe is required its dependencies are unpacked into the root `composer.json`.
- Scaffold files in `web/` (`index.php`, `robots.txt`, `.htaccess`, etc.) are written by `drupal/core-composer-scaffold` and are overwritten on `composer install` or `composer update`.
