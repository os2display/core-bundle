> [!Important] 
> ### This project is no longer actively maintained. 
> The source code in this repository is no longer maintained. It has been superseded by [version 2](https://os2display.github.io/display-docs/), which offers improved features and better support.
>
> Thank you to all who have contributed to this project. We recommend transitioning to [Os2Display version 2](https://os2display.github.io/display-docs/) for continued support and updates.
>
> **Final Release**: The final stable release is version [2.2.3](https://github.com/os2display/core-bundle/releases/tag/2.2.3).
>


<br>

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
