-- =============================================================================
-- ISPWatch — Supabase emergency lockdown
-- Closes the "rls_disabled_in_public" + "sensitive columns exposed" alerts.
--
-- WHAT THIS DOES
--   Blocks ALL access from the public PostgREST API roles (`anon`,
--   `authenticated`) to every table/view in `public`. Your Laravel backend
--   is UNAFFECTED: it connects as the `postgres` table-owner role, and RLS
--   does not apply to a table's owner (we deliberately do NOT use FORCE).
--
-- IMPACT
--   The ~12 frontend pages that still call supabase.from(...) directly will
--   STOP WORKING until they are migrated to the Laravel API. That migration
--   is the agreed follow-up.
--
-- HOW TO RUN
--   Supabase Dashboard → SQL Editor → paste → Run. Re-runnable (idempotent).
-- =============================================================================

-- 1) Enable RLS on every table in `public`. With NO policies attached, this
--    denies all access to anon/authenticated while leaving the owner alone.
--    Per-table EXCEPTION handling so a system table we don't own (e.g. PostGIS
--    spatial_ref_sys) doesn't abort the whole run.
DO $$
DECLARE r record;
BEGIN
  FOR r IN SELECT tablename FROM pg_tables WHERE schemaname = 'public'
  LOOP
    BEGIN
      EXECUTE format('ALTER TABLE public.%I ENABLE ROW LEVEL SECURITY;', r.tablename);
    EXCEPTION WHEN OTHERS THEN
      RAISE NOTICE 'Skipped table %: %', r.tablename, SQLERRM;
    END;
  END LOOP;
END $$;

-- 2) Defense in depth: revoke the API roles' privileges on existing objects.
--    REVOKE ON ALL TABLES also covers VIEWS (RLS can't be set on a view, so
--    this is what protects any view in public).
REVOKE ALL ON ALL TABLES    IN SCHEMA public FROM anon, authenticated;
REVOKE ALL ON ALL SEQUENCES IN SCHEMA public FROM anon, authenticated;
REVOKE ALL ON ALL FUNCTIONS IN SCHEMA public FROM anon, authenticated;

-- 3) Protect FUTURE objects. Supabase's default privileges auto-grant new
--    tables to anon/authenticated, so a new Laravel migration table would be
--    silently exposed (RLS defaults to OFF). Revoke those defaults for the
--    role(s) that create objects (postgres runs your migrations + SQL editor).
ALTER DEFAULT PRIVILEGES FOR ROLE postgres IN SCHEMA public
  REVOKE ALL ON TABLES    FROM anon, authenticated;
ALTER DEFAULT PRIVILEGES FOR ROLE postgres IN SCHEMA public
  REVOKE ALL ON SEQUENCES FROM anon, authenticated;
ALTER DEFAULT PRIVILEGES FOR ROLE postgres IN SCHEMA public
  REVOKE ALL ON FUNCTIONS FROM anon, authenticated;

-- =============================================================================
-- VERIFY (run separately; expect ZERO rows = every table now has RLS on)
-- =============================================================================
-- SELECT tablename
-- FROM pg_tables
-- WHERE schemaname = 'public' AND rowsecurity = false;
--
-- External check (should return 401/permission-denied, NOT data):
--   curl "$VITE_SUPABASE_URL/rest/v1/customer_profile?select=*" \
--        -H "apikey: $VITE_SUPABASE_ANON_KEY"
