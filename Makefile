.PHONY: up down build restart logs shell mysql clean status test db-migrate db-migrate-status

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

db-migrate:			## Run geometry migration (adds SPATIAL INDEX for reverse geocoding)
	@echo "Starting geometry migration. This may take 3–5 minutes..."
	docker compose exec app php /var/www/html/db/migrate_geometry.php
	@echo "Migration done. Verify with: make db-migrate-status"

db-migrate-status:	## Check geometry migration status
	docker compose exec mysql mysql -u$${DB_USER:-wilayah} -p$${DB_PASS:-wilayah} wilayah -e "\
	  SELECT \
	    COUNT(*) AS total_village, \
	    SUM(geom IS NOT NULL) AS geom_filled, \
	    ROUND(SUM(geom IS NOT NULL) * 100.0 / COUNT(*), 1) AS pct_done, \
	    SUM(ST_GeometryType(geom) = 'Polygon') AS polygon_count, \
	    SUM(ST_GeometryType(geom) = 'MultiPolygon') AS multipolygon_count \
	  FROM wilayah_boundaries WHERE CHAR_LENGTH(kode) = 13; \
	  SELECT INDEX_NAME, INDEX_TYPE FROM information_schema.STATISTICS \
	  WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='wilayah_boundaries' \
	  AND INDEX_NAME IN ('idx_geom','idx_lat_lng');"

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
