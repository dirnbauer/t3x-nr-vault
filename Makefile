# nr-vault Makefile
#
# Common development tasks for the nr-vault TYPO3 extension
#

.PHONY: help install test test-unit test-functional lint phpstan fix ci clean ddev-start ddev-stop

# Default target
help:
	@echo "nr-vault Development Commands"
	@echo ""
	@echo "Setup:"
	@echo "  make install        Install Composer dependencies"
	@echo "  make ddev-start     Start DDEV environment"
	@echo "  make ddev-stop      Stop DDEV environment"
	@echo ""
	@echo "Testing:"
	@echo "  make test           Run all tests"
	@echo "  make test-unit      Run unit tests"
	@echo "  make test-functional Run functional tests"
	@echo ""
	@echo "Quality:"
	@echo "  make lint           Check PHP syntax"
	@echo "  make phpstan        Run static analysis"
	@echo "  make cs-check       Check code style"
	@echo "  make fix            Fix code style"
	@echo ""
	@echo "CI:"
	@echo "  make ci             Run all CI checks"
	@echo ""
	@echo "Maintenance:"
	@echo "  make clean          Remove build artifacts"
	@echo ""

# Install dependencies
install:
	composer install --no-progress --prefer-dist

# Run all tests
test:
	Build/Scripts/runTests.sh -s all

# Run unit tests
test-unit:
	Build/Scripts/runTests.sh -s unit

# Run functional tests
test-functional:
	Build/Scripts/runTests.sh -s functional

# Run unit tests with coverage
test-coverage:
	.Build/bin/phpunit -c Tests/Build/phpunit.xml --testsuite Unit --coverage-html .Build/coverage

# PHP lint
lint:
	Build/Scripts/runTests.sh -s lint

# PHPStan static analysis
phpstan:
	Build/Scripts/runTests.sh -s phpstan

# Code style check
cs-check:
	Build/Scripts/runTests.sh -s csfixer

# Fix code style
fix:
	Build/Scripts/runTests.sh -s csfixer -c

# Run all CI checks
ci: lint cs-check phpstan test
	@echo "All CI checks passed!"

# Clean build artifacts
clean:
	rm -rf .Build/vendor
	rm -rf .Build/bin
	rm -rf .Build/public
	rm -rf .Build/var
	rm -rf Tests/Build/.phpunit.cache
	rm -rf var/cache
	rm -rf var/log

# DDEV commands
ddev-start:
	ddev start
	ddev composer install

ddev-stop:
	ddev stop

ddev-install:
	ddev install-v14

ddev-test:
	ddev exec Build/Scripts/runTests.sh -s all
