<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $roles = config('permissions.abilities', [])['seller.dialogs.write'] ?? null;
        if (! is_array($roles) || count($roles) !== 4 || array_diff($roles, ['owner', 'admin', 'editor', 'manager']) || array_diff(['owner', 'admin', 'editor', 'manager'], $roles)) {
            throw new LogicException('Seller assignment policy differs from the supported v1 permission configuration.');
        }
        // Prevent writes between the audit and installation of all three triggers.
        DB::statement('LOCK TABLE public.organizations, public.brands, public.memberships, public.conversations, public.seller_tasks IN SHARE ROW EXCLUSIVE MODE');
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION public.seller_assignment_roles() RETURNS jsonb LANGUAGE sql IMMUTABLE
AS $$ SELECT '["owner", "admin", "editor", "manager"]'::jsonb $$;

CREATE OR REPLACE FUNCTION public.seller_assignment_eligible(
    membership_role text, membership_status text, grants jsonb, member_settings jsonb, target_brand text
) RETURNS boolean LANGUAGE sql IMMUTABLE AS $$
    SELECT membership_status = 'active'
       AND jsonb_typeof(grants) = 'array'
       AND jsonb_typeof(member_settings) = 'object'
       AND (NOT jsonb_exists(member_settings, 'sales_manager') OR jsonb_typeof(member_settings->'sales_manager') = 'boolean')
       AND (COALESCE(member_settings->>'sales_manager', 'false') <> 'true' OR membership_role = 'member')
       AND public.seller_assignment_roles() ? CASE
             WHEN membership_role = 'member' AND member_settings->>'sales_manager' = 'true' THEN 'manager'
             WHEN membership_role = 'member' THEN 'editor'
             ELSE membership_role END
       AND ((membership_role IN ('owner', 'admin') AND grants = '[]'::jsonb)
            OR jsonb_exists(grants, target_brand));
$$;

CREATE OR REPLACE FUNCTION public.seller_assignment_lock(target_org text) RETURNS void LANGUAGE plpgsql VOLATILE AS $$
BEGIN
    IF current_setting('transaction_isolation') <> 'read committed' THEN
        RAISE EXCEPTION 'Seller assignment changes require READ COMMITTED isolation' USING ERRCODE = '25001';
    END IF;
    -- A row-level trigger runs after PostgreSQL may have locked its target row.
    -- Never wait for this lock: an application transaction may already own the
    -- organization lock and be waiting for that row.
    IF NOT pg_try_advisory_xact_lock(hashtextextended('seller-assignment-v1:' || target_org, 0)) THEN
        RAISE EXCEPTION 'Seller assignment is being changed concurrently' USING ERRCODE = '55P03';
    END IF;
END $$;

DO $$
BEGIN
    IF EXISTS (
        SELECT 1 FROM (
            SELECT organization_id, brand_id, assigned_user_id FROM public.conversations WHERE assigned_user_id IS NOT NULL
            UNION ALL
            SELECT organization_id, brand_id, assigned_user_id FROM public.seller_tasks WHERE assigned_user_id IS NOT NULL
        ) a LEFT JOIN public.memberships m
          ON m.organization_id = a.organization_id AND m.user_id = a.assigned_user_id
        WHERE NOT COALESCE(public.seller_assignment_eligible(m.role, m.status, m.brand_ids, m.settings, a.brand_id::text), false)
    ) THEN
        RAISE EXCEPTION 'Existing Seller assignments violate assignment policy; remediate before migration' USING ERRCODE = '23514';
    END IF;
END $$;

CREATE OR REPLACE FUNCTION public.seller_assignment_row_guard() RETURNS trigger LANGUAGE plpgsql VOLATILE AS $$
BEGIN
    IF TG_OP = 'UPDATE' AND (NEW.organization_id IS DISTINCT FROM OLD.organization_id OR NEW.brand_id IS DISTINCT FROM OLD.brand_id) THEN
        RAISE EXCEPTION 'Seller assignment tenant cannot change' USING ERRCODE = '23514';
    END IF;
    IF TG_OP = 'UPDATE' AND NEW.assigned_user_id IS NOT DISTINCT FROM OLD.assigned_user_id THEN
        RETURN NEW;
    END IF;
    IF NEW.assigned_user_id IS NULL AND (TG_OP = 'INSERT' OR OLD.assigned_user_id IS NULL) THEN
        RETURN NEW;
    END IF;
    PERFORM public.seller_assignment_lock(NEW.organization_id::text);
    IF NEW.assigned_user_id IS NOT NULL AND NOT EXISTS (
        SELECT 1 FROM public.memberships m
        JOIN public.brands b ON b.id = NEW.brand_id AND b.organization_id = NEW.organization_id
        JOIN public.organizations o ON o.id = NEW.organization_id
        WHERE m.organization_id = NEW.organization_id AND m.user_id = NEW.assigned_user_id
          AND b.status = 'active' AND o.status = 'active'
          AND public.seller_assignment_eligible(m.role, m.status, m.brand_ids, m.settings, NEW.brand_id::text)
    ) THEN
        RAISE EXCEPTION 'Seller assignee is not eligible for the brand' USING ERRCODE = '23514';
    END IF;
    RETURN NEW;
END $$;

CREATE OR REPLACE FUNCTION public.seller_membership_assignment_guard() RETURNS trigger LANGUAGE plpgsql VOLATILE AS $$
BEGIN
    IF TG_OP = 'UPDATE' AND (NEW.organization_id IS DISTINCT FROM OLD.organization_id OR NEW.user_id IS DISTINCT FROM OLD.user_id) THEN
        RAISE EXCEPTION 'Membership identity cannot change' USING ERRCODE = '23514';
    END IF;
    IF TG_OP = 'UPDATE' AND NEW.role IS NOT DISTINCT FROM OLD.role AND NEW.status IS NOT DISTINCT FROM OLD.status
       AND NEW.brand_ids IS NOT DISTINCT FROM OLD.brand_ids AND NEW.settings IS NOT DISTINCT FROM OLD.settings THEN
        RETURN NEW;
    END IF;
    PERFORM public.seller_assignment_lock(OLD.organization_id::text);
    IF EXISTS (
        SELECT 1 FROM (
            SELECT brand_id FROM public.conversations WHERE organization_id = OLD.organization_id AND assigned_user_id = OLD.user_id
            UNION ALL
            SELECT brand_id FROM public.seller_tasks WHERE organization_id = OLD.organization_id AND assigned_user_id = OLD.user_id
        ) a
        WHERE TG_OP = 'DELETE' OR NOT COALESCE(public.seller_assignment_eligible(NEW.role, NEW.status, NEW.brand_ids, NEW.settings, a.brand_id::text), false)
    ) THEN
        RAISE EXCEPTION 'Membership change would invalidate a Seller assignment' USING ERRCODE = '23514';
    END IF;
    IF TG_OP = 'DELETE' THEN RETURN OLD; END IF;
    RETURN NEW;
END $$;

CREATE OR REPLACE FUNCTION public.seller_tenant_availability_guard() RETURNS trigger LANGUAGE plpgsql VOLATILE AS $$
BEGIN
    IF NEW.status IS DISTINCT FROM OLD.status THEN
        IF TG_TABLE_NAME = 'brands' THEN
            PERFORM public.seller_assignment_lock(OLD.organization_id::text);
        ELSE
            PERFORM public.seller_assignment_lock(OLD.id::text);
        END IF;
    END IF;
    RETURN NEW;
END $$;

CREATE INDEX conversations_assignee_guard_idx ON public.conversations (organization_id, assigned_user_id, brand_id) WHERE assigned_user_id IS NOT NULL;
CREATE INDEX seller_tasks_assignee_guard_idx ON public.seller_tasks (organization_id, assigned_user_id, brand_id) WHERE assigned_user_id IS NOT NULL;
CREATE TRIGGER seller_conversation_assignment_guard BEFORE INSERT OR UPDATE OF organization_id, brand_id, assigned_user_id ON public.conversations FOR EACH ROW EXECUTE FUNCTION public.seller_assignment_row_guard();
CREATE TRIGGER seller_task_assignment_guard BEFORE INSERT OR UPDATE OF organization_id, brand_id, assigned_user_id ON public.seller_tasks FOR EACH ROW EXECUTE FUNCTION public.seller_assignment_row_guard();
CREATE TRIGGER seller_membership_assignment_guard BEFORE DELETE OR UPDATE OF organization_id, user_id, role, status, brand_ids, settings ON public.memberships FOR EACH ROW EXECUTE FUNCTION public.seller_membership_assignment_guard();
CREATE TRIGGER seller_brand_availability_guard BEFORE UPDATE OF status ON public.brands FOR EACH ROW EXECUTE FUNCTION public.seller_tenant_availability_guard();
CREATE TRIGGER seller_organization_availability_guard BEFORE UPDATE OF status ON public.organizations FOR EACH ROW EXECUTE FUNCTION public.seller_tenant_availability_guard();
SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
DROP TRIGGER IF EXISTS seller_conversation_assignment_guard ON public.conversations;
DROP TRIGGER IF EXISTS seller_task_assignment_guard ON public.seller_tasks;
DROP TRIGGER IF EXISTS seller_membership_assignment_guard ON public.memberships;
DROP TRIGGER IF EXISTS seller_brand_availability_guard ON public.brands;
DROP TRIGGER IF EXISTS seller_organization_availability_guard ON public.organizations;
DROP INDEX IF EXISTS public.conversations_assignee_guard_idx;
DROP INDEX IF EXISTS public.seller_tasks_assignee_guard_idx;
DROP FUNCTION IF EXISTS public.seller_assignment_row_guard();
DROP FUNCTION IF EXISTS public.seller_membership_assignment_guard();
DROP FUNCTION IF EXISTS public.seller_tenant_availability_guard();
DROP FUNCTION IF EXISTS public.seller_assignment_lock(text);
DROP FUNCTION IF EXISTS public.seller_assignment_eligible(text, text, jsonb, jsonb, text);
DROP FUNCTION IF EXISTS public.seller_assignment_roles();
SQL);
    }
};
