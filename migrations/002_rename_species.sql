-- =============================================================
--  Migration 002 — 2026-04-19 (rev.2: English-only, avoids mojibake)
--  Rename species on running DB
--    tank_id = 2 : Shrimp -> Dory
--    tank_id = 4 : Prawn  -> Clownfish
--
--  How to run:
--    phpMyAdmin -> smartaqua -> SQL -> paste -> Go
--    OR: docker exec -i db mysql -uuser -ppassword smartaqua
--          < migrations/002_rename_species.sql
--
--  Idempotent — safe to re-run.
-- =============================================================

USE smartaqua;

UPDATE tanks
SET tank_name = 'Tank B2 - Dory',
    species   = 'Dory'
WHERE tank_id = 2;

UPDATE tanks
SET tank_name = 'Tank D3 - Clownfish',
    species   = 'Clownfish'
WHERE tank_id = 4;

SELECT tank_id, tank_name, species, HEX(tank_name) FROM tanks ORDER BY tank_id;
SELECT 'Migration 002 done' AS result;
