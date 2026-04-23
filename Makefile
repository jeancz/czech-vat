.PHONY: install qa test phpstan coverage

install:
	composer update

qa: phpstan

test:
	vendor/bin/phpunit

phpstan:
	vendor/bin/phpstan analyse -c phpstan.neon --memory-limit=512M

coverage:
ifdef GITHUB_ACTION
	vendor/bin/tester -s -p phpdbg --colors 1 -C --coverage coverage.xml --coverage-src src tests
else
	vendor/bin/tester -s -p phpdbg --colors 1 -C --coverage coverage.html --coverage-src src tests
endif

