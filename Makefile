.DEFAULT_GOAL := help

.PHONY: help up down shell cgl cgl-fix phpstan rector test test-unit test-functional test-mutation lint ci docs clean

help: ## Show this help
	@grep -E '^[a-zA-Z_-]+:.*?## .*$$' $(MAKEFILE_LIST) | sort | awk 'BEGIN {FS = ":.*?## "}; {printf "\033[36m%-20s\033[0m %s\n", $$1, $$2}'

# Environment
up: ## Start DDEV + install TYPO3 v14
	ddev start && ddev install-v14

down: ## Stop DDEV
	ddev stop

shell: ## Open container shell
	ddev ssh

# Quality
cgl: ## Check code style (dry-run)
	composer ci:test:php:cgl

cgl-fix: ## Fix code style
	composer ci:cgl

fix: cgl-fix ## Alias for cgl-fix

phpstan: ## Run PHPStan static analysis
	composer ci:test:php:phpstan

rector: ## Run Rector dry-run
	composer ci:test:php:rector

lint: ## PHP syntax check
	find Classes Configuration Tests -name '*.php' -exec php -l {} \; | grep -v 'No syntax errors'

# Testing
test: test-unit test-functional ## Run all tests

test-unit: ## Run unit tests
	composer ci:test:php:unit

test-functional: ## Run functional tests
	composer ci:test:php:functional

test-mutation: ## Run mutation tests
	composer ci:test:php:mutation

# CI
ci: ## Run all CI checks locally
	@echo "Running code style check..."
	@composer ci:test:php:cgl
	@echo "Running PHPStan..."
	@composer ci:test:php:phpstan
	@echo "Running unit tests..."
	@composer ci:test:php:unit
	@echo "Running fuzz tests..."
	@composer ci:test:php:fuzz
	@echo "All CI checks passed!"

# Documentation
docs: ## Render documentation
	docker run --rm --pull always -v "$(shell pwd)":/project -t ghcr.io/typo3-documentation/render-guides:latest --config=Documentation
