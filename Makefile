ifneq ("$(wildcard .env)","")
    include .env
	export $(.env)
endif

PHP_VERSION ?= 8.2
DC_RUN_PHP = docker compose run --rm php
PSALM_THREADS ?= 1

all: update all-checks
all-checks: style phan psalm phpstan test
install:
	$(DC_RUN_PHP) env XDEBUG_MODE=off composer install
update:
	$(DC_RUN_PHP) env XDEBUG_MODE=off composer update
test: test-unit test-integration
test-unit:
	$(DC_RUN_PHP) env XDEBUG_MODE=coverage vendor/bin/phpunit --testsuite unit --colors=always --coverage-text --testdox --coverage-clover coverage.clover --coverage-html=tests/coverage/html
test-integration:
	$(DC_RUN_PHP) env XDEBUG_MODE=off vendor/bin/phpunit --testsuite integration  --coverage-text --testdox --colors=always
test-coverage:
	$(DC_RUN_PHP) env XDEBUG_MODE=coverage vendor/bin/phpunit --colors=always --testdox --testsuite unit --coverage-html=tests/coverage/html
phan:
	$(DC_RUN_PHP) env XDEBUG_MODE=off env PHAN_DISABLE_XDEBUG_WARN=1 vendor-bin/phan/vendor/bin/phan
psalm:
	$(DC_RUN_PHP) env XDEBUG_MODE=off vendor-bin/psalm/vendor/bin/psalm --threads=${PSALM_THREADS} --no-cache --php-version=${PHP_VERSION}
psalm-info:
	$(DC_RUN_PHP) env XDEBUG_MODE=off vendor-bin/psalm/vendor/bin/psalm --show-info=true --threads=${PSALM_THREADS}
phpstan:
	$(DC_RUN_PHP) env XDEBUG_MODE=off vendor/bin/phpstan analyse --memory-limit=256M
bash:
	$(DC_RUN_PHP) bash
style:
	$(DC_RUN_PHP) env XDEBUG_MODE=off vendor-bin/php-cs-fixer/vendor/bin/php-cs-fixer fix --config=.php-cs-fixer.php --using-cache=no -vvv
FORCE:
