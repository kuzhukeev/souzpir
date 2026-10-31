-- Included within a transaction by the isolated transition scripts.
-- Temporary helpers leave no catalog objects after the psql session ends.
CREATE FUNCTION pg_temp.acl_snapshot(items aclitem[]) RETURNS jsonb
LANGUAGE sql STABLE SET search_path = pg_catalog AS $$
    SELECT coalesce(jsonb_agg(jsonb_build_array(grantor, grantee, privilege_type, is_grantable)
                              ORDER BY grantor, grantee, privilege_type, is_grantable), '[]'::jsonb)
    FROM aclexplode(items);
$$;

CREATE FUNCTION pg_temp.transition_snapshot() RETURNS jsonb
LANGUAGE sql STABLE SET search_path = pg_catalog AS $$
SELECT jsonb_build_object(
  'format', 'souzpir-transition-inventory-v2',
  'database', (SELECT jsonb_build_array(datname, datdba, pg_temp.acl_snapshot(coalesce(datacl, acldefault('d', datdba))))
               FROM pg_database WHERE datname=current_database()),
  'migration_sequence', pg_get_serial_sequence('public.migrations', 'id')::regclass::oid,
  'roles', (SELECT jsonb_agg(jsonb_build_array(oid, rolname, rolsuper, rolinherit, rolcreaterole, rolcreatedb,
                                               rolcanlogin, rolreplication, rolbypassrls, rolconnlimit, rolvaliduntil, rolconfig) ORDER BY oid)
            FROM pg_roles WHERE rolname IN (current_user, current_setting('souzpir.transition_runtime'))),
  'role_names', (SELECT jsonb_agg(jsonb_build_array(oid, rolname) ORDER BY oid) FROM pg_roles),
  'memberships', (SELECT coalesce(jsonb_agg(jsonb_build_array(roleid, member, grantor, admin_option, inherit_option, set_option)
                                           ORDER BY roleid, member, grantor), '[]'::jsonb) FROM pg_auth_members),
  'parameters', (SELECT coalesce(jsonb_agg(jsonb_build_array(parname, pg_temp.acl_snapshot(paracl)) ORDER BY parname), '[]'::jsonb)
                 FROM pg_parameter_acl),
  'schemas', (SELECT jsonb_agg(jsonb_build_array(oid, nspname, nspowner, pg_temp.acl_snapshot(coalesce(nspacl, acldefault('n', nspowner)))) ORDER BY oid)
              FROM pg_namespace WHERE nspname !~ '^pg_' AND nspname NOT IN ('information_schema', 'sp_e106_transition')),
  'relations', (SELECT jsonb_agg(jsonb_build_array(c.oid, n.nspname, c.relname, c.relkind, c.relowner,
                  c.relrowsecurity, c.relforcerowsecurity, c.relpersistence,
                  CASE WHEN c.relkind IN ('v','m') THEN pg_get_viewdef(c.oid) ELSE NULL END,
                  pg_temp.acl_snapshot(coalesce(c.relacl, acldefault(CASE WHEN c.relkind='S' THEN 's' ELSE 'r' END::"char", c.relowner)))) ORDER BY c.oid)
                FROM pg_class c JOIN pg_namespace n ON n.oid=c.relnamespace
                WHERE n.nspname !~ '^pg_' AND n.nspname NOT IN ('information_schema', 'sp_e106_transition')),
  'columns', (SELECT coalesce(jsonb_agg(jsonb_build_array(a.attrelid, a.attnum, a.attname,
                  format_type(a.atttypid,a.atttypmod), a.attnotnull, a.attidentity, a.attgenerated, a.attcollation,
                  (SELECT pg_get_expr(ad.adbin, ad.adrelid) FROM pg_attrdef ad WHERE ad.adrelid=a.attrelid AND ad.adnum=a.attnum),
                  pg_temp.acl_snapshot(a.attacl)) ORDER BY a.attrelid, a.attnum), '[]'::jsonb)
              FROM pg_attribute a JOIN pg_class c ON c.oid=a.attrelid JOIN pg_namespace n ON n.oid=c.relnamespace
              WHERE a.attnum > 0 AND NOT a.attisdropped AND n.nspname !~ '^pg_'
                AND n.nspname NOT IN ('information_schema', 'sp_e106_transition')),
  'functions', (SELECT coalesce(jsonb_agg(jsonb_build_array(p.oid, n.nspname, p.proname, p.proowner, p.prokind,
                  p.prosecdef, p.proleakproof, p.proisstrict, p.provolatile, p.proparallel, p.prorettype,
                  pg_get_function_identity_arguments(p.oid), p.prosrc, p.probin, p.proconfig,
                  CASE WHEN p.prokind='f' THEN pg_get_functiondef(p.oid) ELSE NULL END,
                  pg_temp.acl_snapshot(coalesce(p.proacl, acldefault('f', p.proowner)))) ORDER BY p.oid), '[]'::jsonb)
                FROM pg_proc p JOIN pg_namespace n ON n.oid=p.pronamespace
                WHERE n.nspname !~ '^pg_' AND n.nspname NOT IN ('information_schema', 'sp_e106_transition')),
  'triggers', (SELECT coalesce(jsonb_agg(jsonb_build_array(t.oid, t.tgrelid, t.tgfoid, t.tgenabled, pg_get_triggerdef(t.oid)) ORDER BY t.oid), '[]'::jsonb)
               FROM pg_trigger t JOIN pg_class c ON c.oid=t.tgrelid JOIN pg_namespace n ON n.oid=c.relnamespace
               WHERE n.nspname !~ '^pg_' AND n.nspname NOT IN ('information_schema', 'sp_e106_transition')),
  'event_triggers', (SELECT coalesce(jsonb_agg(jsonb_build_array(oid, evtname, evtevent, evtowner, evtfoid, evtenabled, evttags) ORDER BY oid), '[]'::jsonb)
                     FROM pg_event_trigger),
  'constraints', (SELECT coalesce(jsonb_agg(jsonb_build_array(k.oid, k.conrelid, k.conname, k.contype,
                  pg_get_constraintdef(k.oid)) ORDER BY k.oid), '[]'::jsonb)
                  FROM pg_constraint k JOIN pg_class c ON c.oid=k.conrelid JOIN pg_namespace n ON n.oid=c.relnamespace
                  WHERE n.nspname !~ '^pg_' AND n.nspname NOT IN ('information_schema', 'sp_e106_transition')),
  'indexes', (SELECT coalesce(jsonb_agg(jsonb_build_array(i.indexrelid, i.indrelid, pg_get_indexdef(i.indexrelid)) ORDER BY i.indexrelid), '[]'::jsonb)
              FROM pg_index i JOIN pg_class c ON c.oid=i.indrelid JOIN pg_namespace n ON n.oid=c.relnamespace
              WHERE n.nspname !~ '^pg_' AND n.nspname NOT IN ('information_schema', 'sp_e106_transition')),
  'defaults', (SELECT coalesce(jsonb_agg(jsonb_build_array(d.defaclrole, d.defaclnamespace, d.defaclobjtype,
                  pg_temp.acl_snapshot(d.defaclacl)) ORDER BY d.defaclrole, d.defaclnamespace, d.defaclobjtype), '[]'::jsonb)
               FROM pg_default_acl d)
);
$$;

-- Report categories and up to ten changed relation/function names, never ACL
-- values or function bodies, so operator logs remain useful without dumping data.
CREATE FUNCTION pg_temp.describe_transition_difference(expected jsonb, actual jsonb) RETURNS text
LANGUAGE sql STABLE SET search_path = pg_catalog AS $$
WITH keys AS (
  SELECT key FROM jsonb_object_keys(expected || actual) AS k(key)
  WHERE expected->key IS DISTINCT FROM actual->key
), objects AS (
  SELECT jsonb_build_object('kind','relation','oid',coalesce(b.item->>0,a.item->>0),
                            'schema',coalesce(b.item->>1,a.item->>1),'name',coalesce(b.item->>2,a.item->>2)) AS object
  FROM jsonb_array_elements(coalesce(expected->'relations','[]'::jsonb)) AS b(item)
  FULL JOIN jsonb_array_elements(coalesce(actual->'relations','[]'::jsonb)) AS a(item) ON b.item->>0=a.item->>0
  WHERE b.item IS DISTINCT FROM a.item
  UNION ALL
  SELECT jsonb_build_object('kind','function','oid',coalesce(b.item->>0,a.item->>0),
                            'schema',coalesce(b.item->>1,a.item->>1),'name',coalesce(b.item->>2,a.item->>2))
  FROM jsonb_array_elements(coalesce(expected->'functions','[]'::jsonb)) AS b(item)
  FULL JOIN jsonb_array_elements(coalesce(actual->'functions','[]'::jsonb)) AS a(item) ON b.item->>0=a.item->>0
  WHERE b.item IS DISTINCT FROM a.item
), limited AS (SELECT object FROM objects ORDER BY object::text LIMIT 10)
SELECT format('sections: %s; objects (first 10): %s',
  coalesce((SELECT string_agg(key, ', ' ORDER BY key) FROM keys), 'none'),
  coalesce((SELECT string_agg(object::text, ', ' ORDER BY object::text) FROM limited), 'none'));
$$;
