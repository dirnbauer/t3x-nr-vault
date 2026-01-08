# nr-vault Makefile
# Docker-based testing following TYPO3 core conventions
# Use runTests.sh for CI-compatible containerized test execution

.PHONY: help test unit functional lint phpstan cs fix ci clean docs docs-open

.DEFAULT_GOAL := help

RUNTESTS = Build/Scripts/runTests.sh

help:
	@echo "nr-vault Development Commands"
	@echo ""
	@echo "  Testing (Docker-based, CI-compatible):"
	@echo "    make test       Run unit tests (default: PHP 8.5, SQLite)"
	@echo "    make unit       Run unit tests"
	@echo "    make functional Run functional tests (SQLite)"
	@echo "    make func-maria Run functional tests (MariaDB 10.11)"
	@echo "    make func-mysql Run functional tests (MySQL 8.0)"
	@echo "    make func-pg    Run functional tests (PostgreSQL 16)"
	@echo ""
	@echo "  Quality (Docker-based):"
	@echo "    make lint       Check PHP syntax"
	@echo "    make phpstan    Run static analysis"
	@echo "    make cs         Check code style"
	@echo "    make fix        Fix code style"
	@echo "    make rector     Apply Rector rules"
	@echo ""
	@echo "  Documentation:"
	@echo "    make docs       Render documentation"
	@echo "    make docs-open  Render and open documentation"
	@echo ""
	@echo "  CI/Maintenance:"
	@echo "    make ci         Run all CI checks"
	@echo "    make clean      Remove build artifacts"
	@echo "    make update     Update Docker images"
	@echo ""
	@echo "  Options (via runTests.sh directly):"
	@echo "    ./$(RUNTESTS) -h           Show all options"
	@echo "    ./$(RUNTESTS) -p 8.4 -s unit   Run with PHP 8.4"
	@echo "    ./$(RUNTESTS) -x -s unit       Run with Xdebug"

# === Testing ===
test: unit

unit:
	$(RUNTESTS) -s unit

functional:
	$(RUNTESTS) -s functional

func-maria:
	$(RUNTESTS) -s functional -d mariadb

func-mysql:
	$(RUNTESTS) -s functional -d mysql

func-pg:
	$(RUNTESTS) -s functional -d postgres

# === Quality ===
lint:
	$(RUNTESTS) -s lint

phpstan:
	$(RUNTESTS) -s phpstan

cs:
	$(RUNTESTS) -s cgl -n

fix:
	$(RUNTESTS) -s cgl

rector:
	$(RUNTESTS) -s rector

# === Documentation ===
docs:
	$(RUNTESTS) -s renderDocumentation

docs-open: docs
	@echo "Opening documentation..."
	@xdg-open Documentation-GENERATED-temp/Index.html 2>/dev/null || open Documentation-GENERATED-temp/Index.html 2>/dev/null || echo "Open Documentation-GENERATED-temp/Index.html in your browser"

# === CI ===
ci: lint cs phpstan unit
	@echo "All CI checks passed!"

# === Maintenance ===
clean:
	$(RUNTESTS) -s clean

update:
	$(RUNTESTS) -u
