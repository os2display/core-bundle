# Os2Display core

Installation:

```
composer require 'os2display/core-bundle:^1.0'
```


## Api tests

The Behat api tests uses [SQLite](https://www.sqlite.org/). However,
we need to patch [doctrine/dbal](https://github.com/doctrine/dbal)
(cf. https://github.com/doctrine/dbal/issues/2426) in order to make
auto-increment columns work as expected (i.e. as in
[MySQL](https://www.mysql.com/)):

```
composer install
patch --strip=1 < Features/Fixtures/patch/doctrine-dbal-issues-2426.patch
```

Run the api tests:

```
./vendor/bin/behat --suite=api_features
```
