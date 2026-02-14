.PHONY: up down build restart logs shell mysql clean status test

# ── Start / Stop ────────────────────────────────────────────
up:					## Start all services
	docker compose up -d

up-build:			## Build & start
	docker compose up -d --build

down:				## Stop all services
	docker compose down

restart:			## Restart all services
	docker compose restart

# ── Logs ────────────────────────────────────────────────────
logs:				## Tail logs (all services)
	docker compose logs -f

logs-app:			## Tail app logs only
	docker compose logs -f app

logs-mysql:			## Tail MySQL logs only
	docker compose logs -f mysql

# ── Shell Access ────────────────────────────────────────────
shell:				## Open bash in PHP container
	docker compose exec app bash

mysql:				## Open MySQL CLI
	docker compose exec mysql mysql -u root -psecret wilayah

# ── Database ────────────────────────────────────────────────
db-reset:			## Destroy volume & re-import all SQL
	docker compose down -v
	docker compose up -d --build

db-import:			## Re-import SQL into running MySQL (destructive!)
	docker compose exec -T mysql mysql -u root -psecret wilayah < db/wilayah_level_1_2.sql
	docker compose exec -T mysql mysql -u root -psecret wilayah < db/wilayah.sql
	docker compose exec -T mysql mysql -u root -psecret wilayah < db/wilayah_luas.sql
	docker compose exec -T mysql mysql -u root -psecret wilayah < db/wilayah_penduduk.sql
	docker compose exec -T mysql mysql -u root -psecret wilayah < db/wilayah_pulau.sql

# ── Test ────────────────────────────────────────────────────
test:				## Run API smoke test
	@echo "=== Wilayah API Smoke Test ==="
	@curl -s http://localhost:8080/api/test.php | head -100
	@echo ""

test-api:			## Quick endpoint test
	@echo "--- Health ---"
	@curl -s http://localhost:8080/api/health | python3 -m json.tool 2>/dev/null || curl -s http://localhost:8080/api/health
	@echo "\n--- Provinces ---"
	@curl -s "http://localhost:8080/api/provinces?limit=3" | python3 -m json.tool 2>/dev/null || curl -s "http://localhost:8080/api/provinces?limit=3"
	@echo ""

# ── Cleanup ─────────────────────────────────────────────────
clean:				## Stop containers, remove volumes & images
	docker compose down -v --rmi local

status:				## Show running containers
	docker compose ps

# ── Help ────────────────────────────────────────────────────
help:				## Show this help
	@grep -E '^[a-zA-Z_-]+:.*?## .*$$' $(MAKEFILE_LIST) | \
		awk 'BEGIN {FS = ":.*?## "}; {printf "  \033[36m%-15s\033[0m %s\n", $$1, $$2}'
