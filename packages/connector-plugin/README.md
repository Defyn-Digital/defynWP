# DefynWP Connector

A WordPress plugin that turns a managed site into a DefynWP-managed agent. Pairs with the central DefynWP Dashboard plugin via the connection-handshake protocol.

## Install (development)

1. `composer install` in this directory.
2. Symlink or copy this directory into a target WP install's `wp-content/plugins/`.
3. Activate **DefynWP Connector** from the WP admin Plugins screen.

## Generate a connection code

1. Go to **Settings → DefynWP Connector**.
2. Click **Generate Connection Code**.
3. Paste the 12-character code into the DefynWP Dashboard SPA's "Add Site" form (the dashboard's UI lands in F5).

## Run tests

```
composer test
```

(Requires a local MySQL test database. See `tests/wp-tests-config.php.example`.)
