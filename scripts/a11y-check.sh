#!/bin/bash
# Peanut Connect - Accessibility Test Runner
# Usage: ./scripts/a11y-check.sh [--watch]

set -e

RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m'

echo -e "${BLUE}━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━${NC}"
echo -e "${BLUE}  Peanut Connect - Accessibility Tests${NC}"
echo -e "${BLUE}━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━${NC}"
echo ""

cd "$(dirname "$0")/../frontend"

if [ "$1" = "--watch" ]; then
    echo -e "${YELLOW}Running in watch mode...${NC}"
    npx vitest --reporter=verbose -- a11y
elif [ "$1" = "--help" ]; then
    echo "Usage: ./scripts/a11y-check.sh [options]"
    echo ""
    echo "Options:"
    echo "  --watch    Run tests in watch mode"
    echo "  --help     Show this help message"
    exit 0
else
    echo -e "${YELLOW}Running accessibility tests...${NC}"
    npx vitest run --reporter=verbose -- a11y

    if [ $? -eq 0 ]; then
        echo -e "${GREEN}✓ All accessibility tests passed!${NC}"
    else
        echo -e "${RED}✗ Some accessibility tests failed.${NC}"
        exit 1
    fi
fi
