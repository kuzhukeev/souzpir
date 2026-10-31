"""Check that a transition changed no inventoried rights except direct grants to runtime."""

import argparse
import copy
import json
from pathlib import Path
import sys


def compare(before, after, runtime):
    keys = {'format', 'database', 'migration_sequence', 'roles', 'role_names', 'memberships', 'parameters', 'schemas',
            'relations', 'columns', 'functions', 'triggers', 'event_triggers', 'constraints', 'indexes', 'defaults'}
    if (set(before) != keys or set(after) != keys
        or before['format'] != 'souzpir-transition-inventory-v2'
        or after['format'] != before['format']):
        raise ValueError('unexpected inventory format')
    role_ids = [row[0] for row in before['role_names'] if row[1] == runtime]
    if len(role_ids) != 1:
        raise ValueError('runtime role not uniquely present in baseline')
    role_oid = role_ids[0]
    categories = ('schemas', 'relations', 'columns', 'functions', 'defaults', 'parameters')

    def acls(snapshot):
        yield snapshot['database'][-1]
        for category in categories:
            for item in snapshot[category] or []:
                yield item[-1]

    if any(entry[1] == role_oid for acl in acls(before) for entry in acl):
        raise ValueError('baseline already contains a direct runtime grant')
    if any(entry[1] in ('0', role_oid) and entry[3] for acl in acls(after) for entry in acl):
        raise ValueError('runtime has a grant option')

    # No role membership is permitted; effective access must come from PUBLIC
    # or from direct grants to this particular role.
    runtime_roles = [row for row in after['roles'] if row[0] == role_oid and row[1] == runtime]
    if len(runtime_roles) != 1:
        raise ValueError('runtime role missing from current inventory')
    role = runtime_roles[0]
    if role[2] or role[4] or role[5] or not role[6] or role[7] or role[8]:
        raise ValueError('runtime role is elevated or cannot log in')
    if any(row[1] == role_oid for row in after['memberships']) or after['database'][1] == role_oid:
        raise ValueError('runtime owns the database or has memberships')
    if any(row[5] != 'D' for row in after['event_triggers']):
        raise ValueError('enabled event triggers require separate review')

    def permits(acl, privilege):
        return any(entry[1] in ('0', role_oid) and entry[2] == privilege for entry in acl)

    if any(entry[1] == role_oid for row in after['parameters'] for entry in row[-1]):
        raise ValueError('runtime has a direct parameter grant')
    if any(row[0] == 'session_replication_role' and permits(row[-1], 'SET') for row in after['parameters']):
        raise ValueError('runtime can set session_replication_role')
    if not all(permits(after['database'][-1], p) for p in ('CONNECT', 'TEMPORARY')):
        raise ValueError('runtime lacks CONNECT or TEMPORARY')
    if permits(after['database'][-1], 'CREATE'):
        raise ValueError('runtime can create schemas')
    public = [row for row in after['schemas'] if row[1] == 'public']
    if len(public) != 1 or public[0][2] == role_oid or not permits(public[0][-1], 'USAGE') or permits(public[0][-1], 'CREATE'):
        raise ValueError('invalid runtime schema permissions or ownership')
    migration = [row for row in after['relations'] if row[1:3] == ['public', 'migrations']]
    if len(migration) != 1:
        raise ValueError('migration history table missing')
    if not any(row[0] == after['migration_sequence'] and row[1] == 'public' and row[3] == 'S'
               for row in after['relations']):
        raise ValueError('migration history sequence missing or outside public')
    for row in after['relations']:
        oid, schema, name, kind, owner, rls, force_rls = row[:7]
        if schema != 'public':
            raise ValueError('unsupported extra schema')
        if owner != after['database'][1] or rls or force_rls:
            raise ValueError('mixed relation owners or row security requires review')
        if name == 'migrations':
            if any(permits(row[-1], p) for p in ('INSERT', 'UPDATE', 'DELETE')):
                raise ValueError('runtime can change migration history')
        elif oid == after['migration_sequence']:
            if any(permits(row[-1], p) for p in ('USAGE', 'UPDATE')):
                raise ValueError('runtime can change migration sequence')
        elif kind in ('r', 'p', 'v', 'm', 'f'):
            if not all(permits(row[-1], p) for p in ('SELECT', 'INSERT', 'UPDATE', 'DELETE')):
                raise ValueError('runtime lacks application table privileges')
        elif kind == 'S' and not all(permits(row[-1], p) for p in ('USAGE', 'SELECT')):
            raise ValueError('runtime lacks application sequence privileges')
        if kind in ('r', 'p', 'v', 'm', 'f') and any(permits(row[-1], p) for p in ('TRUNCATE', 'TRIGGER', 'REFERENCES')):
            raise ValueError('runtime has excessive table privileges')
    for col in after['columns']:
        if col[0] == migration[0][0] and any(permits(col[-1], p) for p in ('INSERT', 'UPDATE')):
            raise ValueError('runtime has a migration-history column grant')
    for row in after['functions']:
        if row[1] != 'public' or row[3] == role_oid or row[4] != 'f' or row[5] or not permits(row[-1], 'EXECUTE'):
            raise ValueError(f'unsupported function owner, type, security or runtime EXECUTE: {row[1]}.{row[2]}')
    owner_oid, schema_oid = after['database'][1], public[0][0]
    defaults = {(row[0], row[1], row[2]): row[-1] for row in after['defaults']}
    for kind, privileges in (('r', ('SELECT', 'INSERT', 'UPDATE', 'DELETE')), ('S', ('USAGE', 'SELECT'))):
        acl = defaults.get((owner_oid, schema_oid, kind), [])
        if not all(any(entry[1] == role_oid and entry[2] == privilege for entry in acl) for privilege in privileges):
            raise ValueError('runtime default privileges missing for future objects')

    def project(snapshot):
        result = copy.deepcopy(snapshot)
        result['database'][-1] = [entry for entry in result['database'][-1] if entry[1] != role_oid]
        for category in categories:
            for item in result[category] or []:
                item[-1] = [entry for entry in item[-1] if entry[1] != role_oid]
        result['defaults'] = [item for item in result['defaults'] if item[-1]]
        return result

    previous, current = project(before), project(after)
    return sorted(key for key in set(previous) | set(current) if previous.get(key) != current.get(key))


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('before', type=Path)
    parser.add_argument('after', type=Path)
    parser.add_argument('--runtime', required=True)
    args = parser.parse_args()
    try:
        before = json.loads(args.before.read_text(encoding='utf-8'))
        after = json.loads(args.after.read_text(encoding='utf-8'))
        changed = compare(before, after, args.runtime)
    except (OSError, ValueError, KeyError, TypeError, IndexError) as exc:
        print(f'Invalid inventory: {exc}', file=sys.stderr)
        return 2
    if changed:
        print('Unexpected inventory changes: ' + ', '.join(changed), file=sys.stderr)
        return 1
    print('Inventoried owners, existing ACL, functions and triggers are unchanged; expected runtime grants are present.')
    return 0


if __name__ == '__main__':
    sys.exit(main())
