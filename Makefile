SHELL := /bin/sh
COMPOSE := docker compose

.DEFAULT_GOAL := help

.PHONY: help setup up down restart dev-web dev-worker build migrate seed key test test-api test-web test-e2e lint format typecheck logs shell-api shell-web

help:
	@echo "ReviewOrbit commands"
	@echo "  make setup      Copy environment files, build images, and install dependencies"
	@echo "  make up         Start the local stack"
	@echo "  make dev-web    Start Next.js for Herd/local development"
	@echo "  make dev-worker Start Laravel queues for Herd/local development"
	@echo "  make down       Stop the local stack"
	@echo "  make migrate    Run Laravel migrations"
	@echo "  make seed       Seed development data"
	@echo "  make test       Run API and web tests"
	@echo "  make lint       Run backend formatter check and frontend lint"
	@echo "  make typecheck  Run frontend TypeScript checks"
	@echo "  make build      Build production artifacts"
	@echo "  make logs       Follow all service logs"

setup:
	test -f .env || cp .env.example .env
	test -f apps/api/.env || cp apps/api/.env.example apps/api/.env
	$(COMPOSE) build
	$(COMPOSE) run --rm api composer install
	$(COMPOSE) run --rm web npm install
	$(COMPOSE) run --rm api php artisan key:generate --force

up:
	$(COMPOSE) up -d

down:
	$(COMPOSE) down

restart: down up

dev-web:
	cd apps/web && npm run dev -- --hostname 127.0.0.1

dev-worker:
	cd apps/api && php artisan queue:work --queue=webhooks,integrations,media,messaging,default --tries=3

migrate:
	$(COMPOSE) exec api php artisan migrate

seed:
	$(COMPOSE) exec api php artisan db:seed

key:
	$(COMPOSE) run --rm api php artisan key:generate --force

test: test-api test-web

test-api:
	$(COMPOSE) run --rm -e APP_ENV=testing api php artisan test

test-web:
	$(COMPOSE) run --rm web npm test -- --run

test-e2e:
	$(COMPOSE) run --rm web npm run test:e2e

lint:
	$(COMPOSE) run --rm api ./vendor/bin/pint --test
	$(COMPOSE) run --rm web npm run lint

format:
	$(COMPOSE) run --rm api ./vendor/bin/pint
	$(COMPOSE) run --rm web npm run format

typecheck:
	$(COMPOSE) run --rm web npm run typecheck

build:
	$(COMPOSE) build api nginx web
	$(COMPOSE) run --rm web npm run build

logs:
	$(COMPOSE) logs -f

shell-api:
	$(COMPOSE) exec api sh

shell-web:
	$(COMPOSE) exec web sh
