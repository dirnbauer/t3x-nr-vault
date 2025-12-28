#!/usr/bin/env bash

#
# TYPO3 extension test runner script
#
# Usage: Build/Scripts/runTests.sh [options] [arguments]
#
# Options:
#   -s <suite>    Test suite to run:
#                   - unit: Run unit tests (default)
#                   - functional: Run functional tests
#                   - all: Run all tests
#                   - lint: PHP syntax check
#                   - phpstan: Static analysis
#                   - csfixer: Code style check/fix
#   -c            Run csfixer in fix mode (only with -s csfixer)
#   -v            Verbose output
#   -h            Show this help
#

set -e

# Colors for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
NC='\033[0m' # No Color

# Default values
SUITE="unit"
VERBOSE=0
CSFIXER_FIX=0

# Script directory
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ROOT_DIR="$(cd "${SCRIPT_DIR}/../.." && pwd)"

# Change to root directory
cd "${ROOT_DIR}"

# Help function
show_help() {
    echo "TYPO3 Extension Test Runner"
    echo ""
    echo "Usage: $0 [options]"
    echo ""
    echo "Options:"
    echo "  -s <suite>  Test suite: unit, functional, all, lint, phpstan, csfixer"
    echo "  -c          Fix mode for csfixer"
    echo "  -v          Verbose output"
    echo "  -h          Show this help"
    echo ""
    echo "Examples:"
    echo "  $0 -s unit           Run unit tests"
    echo "  $0 -s functional     Run functional tests"
    echo "  $0 -s all            Run all tests"
    echo "  $0 -s phpstan        Run static analysis"
    echo "  $0 -s csfixer        Check code style"
    echo "  $0 -s csfixer -c     Fix code style"
    echo ""
}

# Parse options
while getopts "s:cvh" opt; do
    case ${opt} in
        s)
            SUITE="${OPTARG}"
            ;;
        c)
            CSFIXER_FIX=1
            ;;
        v)
            VERBOSE=1
            ;;
        h)
            show_help
            exit 0
            ;;
        \?)
            echo -e "${RED}Invalid option: -${OPTARG}${NC}" >&2
            show_help
            exit 1
            ;;
    esac
done

# Check if composer dependencies are installed
if [[ ! -d ".Build/vendor" ]]; then
    echo -e "${YELLOW}Installing dependencies...${NC}"
    composer install --no-progress --prefer-dist
fi

# Determine PHPUnit binary
PHPUNIT=".Build/bin/phpunit"
PHPSTAN=".Build/bin/phpstan"
CSFIXER=".Build/bin/php-cs-fixer"

# Run selected suite
case ${SUITE} in
    unit)
        echo -e "${GREEN}Running unit tests...${NC}"
        if [[ ${VERBOSE} -eq 1 ]]; then
            ${PHPUNIT} -c Tests/Build/phpunit.xml --testsuite Unit --verbose
        else
            ${PHPUNIT} -c Tests/Build/phpunit.xml --testsuite Unit
        fi
        ;;

    functional)
        echo -e "${GREEN}Running functional tests...${NC}"
        if [[ ${VERBOSE} -eq 1 ]]; then
            ${PHPUNIT} -c Tests/Build/phpunit.xml --testsuite Functional --verbose
        else
            ${PHPUNIT} -c Tests/Build/phpunit.xml --testsuite Functional
        fi
        ;;

    all)
        echo -e "${GREEN}Running all tests...${NC}"
        if [[ ${VERBOSE} -eq 1 ]]; then
            ${PHPUNIT} -c Tests/Build/phpunit.xml --verbose
        else
            ${PHPUNIT} -c Tests/Build/phpunit.xml
        fi
        ;;

    lint)
        echo -e "${GREEN}Running PHP lint...${NC}"
        find Classes Tests -name '*.php' -print0 | xargs -0 -n1 php -l
        echo -e "${GREEN}No syntax errors found.${NC}"
        ;;

    phpstan)
        echo -e "${GREEN}Running PHPStan...${NC}"
        if [[ ${VERBOSE} -eq 1 ]]; then
            ${PHPSTAN} analyse -v
        else
            ${PHPSTAN} analyse
        fi
        ;;

    csfixer)
        if [[ ${CSFIXER_FIX} -eq 1 ]]; then
            echo -e "${GREEN}Fixing code style...${NC}"
            ${CSFIXER} fix
        else
            echo -e "${GREEN}Checking code style...${NC}"
            ${CSFIXER} fix --dry-run --diff
        fi
        ;;

    *)
        echo -e "${RED}Unknown suite: ${SUITE}${NC}" >&2
        show_help
        exit 1
        ;;
esac

echo -e "${GREEN}Done!${NC}"
