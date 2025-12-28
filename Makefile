# nr-vault Makefile - DDEV-based development

.PHONY: help up down test unit functional lint phpstan cs fix ci clean shell docs docs-open

.DEFAULT_GOAL := help

help:
	@echo "nr-vault Development Commands"
	@echo ""
	@echo "  make up         Start environment and install TYPO3"
	@echo "  make down       Stop environment"
	@echo "  make shell      Open shell in container"
	@echo ""
	@echo "  make test       Run all tests"
	@echo "  make unit       Run unit tests"
	@echo "  make functional Run functional tests"
	@echo ""
	@echo "  make lint       Check PHP syntax"
	@echo "  make phpstan    Run static analysis"
	@echo "  make cs         Check code style"
	@echo "  make fix        Fix code style"
	@echo ""
	@echo "  make docs       Render documentation"
	@echo "  make docs-open  Render and open documentation"
	@echo ""
	@echo "  make ci         Run all CI checks"
	@echo "  make clean      Remove build artifacts"

# === Environment ===
up:
	ddev start
	ddev install-v14

down:
	ddev stop

shell:
	ddev ssh

# === Testing (runs in container) ===
test: unit functional

unit:
	ddev exec .Build/bin/phpunit -c Tests/Build/phpunit.xml --testsuite Unit --no-coverage

functional:
	ddev exec .Build/bin/phpunit -c Tests/Build/phpunit.xml --testsuite Functional

# === Quality (runs in container) ===
lint:
	ddev exec find Classes Tests -name '*.php' -print0 | xargs -0 -n1 php -l

phpstan:
	ddev exec .Build/bin/phpstan analyse

cs:
	ddev exec .Build/bin/php-cs-fixer fix --dry-run --diff

fix:
	ddev exec .Build/bin/php-cs-fixer fix

# === Documentation ===
docs:
	docker run --rm -v "$(PWD)":/project -t ghcr.io/typo3-documentation/render-guides:latest --config=Documentation

docs-open: docs
	@echo "Opening documentation..."
	@xdg-open Documentation-GENERATED-temp/Index.html 2>/dev/null || open Documentation-GENERATED-temp/Index.html 2>/dev/null || echo "Open Documentation-GENERATED-temp/Index.html in your browser"

# === CI ===
ci: lint cs phpstan unit
	@echo "All CI checks passed!"

# === Maintenance ===
clean:
	rm -rf .Build/vendor .Build/bin .Build/public .Build/var
	rm -rf Tests/Build/.phpunit.cache var/
	rm -rf Documentation-GENERATED-temp
