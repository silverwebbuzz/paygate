-- PayGate: privileges for the runtime (application) database user.
--
-- Run as the OWNER user after EVERY `php artisan migrate` on staging/production:
--   psql "$OWNER_DATABASE_URL" -v app_role=paygate_app -f database/sql/app-privileges.sql
--
-- The owner runs migrations; the app user only reads and writes data.
-- It can never alter the schema, drop triggers, or change history tables.
-- Safe to run repeatedly.

\set ON_ERROR_STOP on

GRANT USAGE ON SCHEMA public TO :"app_role";

-- Normal data access on every table and sequence.
GRANT SELECT, INSERT, UPDATE, DELETE ON ALL TABLES IN SCHEMA public TO :"app_role";
GRANT USAGE, SELECT ON ALL SEQUENCES IN SCHEMA public TO :"app_role";

-- History tables are append-only: the app may read and add rows, never change or remove them.
-- (Database triggers enforce this too; this is a second, independent layer.)
REVOKE UPDATE, DELETE, TRUNCATE ON
    audit_logs,
    security_logs,
    transaction_events,
    ledger_journals,
    ledger_entries,
    webhook_attempts
FROM :"app_role";

-- The app never changes the migration history.
REVOKE INSERT, UPDATE, DELETE, TRUNCATE ON migrations FROM :"app_role";
