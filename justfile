# List available recipes
default:
    @just --list

# Install dependencies
install:
    composer install
    npm install

# Update dependencies
update:
    composer update
    npm update

# Watch and build assets in development
dev:
    npm run dev

# Build assets for production
build:
    npm run build
