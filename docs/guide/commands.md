# Commands

## cache-contract:list

Lists every definition in `config/cache-contract.php`:

```bash
php artisan cache-contract:list
```

```
+------------------+---------+------------+----------------+------+---------+---------------------------+
| ID               | Subject | Type       | Params         | TTL  | Version | Description               |
+------------------+---------+------------+----------------+------+---------+---------------------------+
| user.profile     | User    | array      | user_id: int   | 3600 | 2       | Profile page data.        |
| user.active_list | User    | collection | tenant_id: int | 300  | 1       | Active users of a tenant. |
+------------------+---------+------------+----------------+------+---------+---------------------------+
```

The TTL column is in seconds, also for TTLs written with units (`'1 hour'`).

| Option | Meaning |
| :--- | :--- |
| `--section=user` | Only definitions in this section. |
| `--subject=User` | Only definitions about this model; the class name or its short name. |
| `--json` | Machine-readable JSON instead of the table. |

Check this list before adding a definition: the data you need may already be
cached under another name.

## cache-contract:inspect

Shows one definition and the key it generates:

```bash
php artisan cache-contract:inspect user.profile
```

```
  ID ............................................................ user.profile
  Subject ................................................... App\Models\User
  Purpose ............................................................ profile
  Type ................................................................. array
  TTL ........................................................ 3600 s (1 hour)
  Version .................................................................. 2
  Params ........................................................ user_id: int
  Tags ..................................................................... -
  Invalidated by ........................................................... -
  Counters ................................................................. -
  Description ............................................. Profile page data.
  Key pattern ............................... cc:user:profile:v2:user_id={int}

   INFO  Pass --param=name=value for every parameter to see a concrete key.
```

Give a value for every parameter to see the exact key, which helps when you
look for an entry in Redis:

```bash
php artisan cache-contract:inspect user.profile --param=user_id=42 --context=locale=tr
```

```
  ...
  Key pattern ............................... cc:user:profile:v2:user_id={int}
  Key ............................... cc:user:profile:v2:user_id=42:@locale=tr
```

| Option | Meaning |
| :--- | :--- |
| `--param=name=value` | A parameter value; repeat it for each parameter. Arrays as JSON: `--param=ids=[1,2]`. |
| `--context=name=value` | A context value, e.g. `--context=tenant_id=7`; repeatable. |
| `--generation=name=value` | A generation counter value, e.g. `--generation=users=8123`; repeat it for each counter. |
| `--live` | Read the generation counters from Redis. It only reads: nothing is written. |

Terminal input is always text, so `inspect` converts `--param` values to the
declared type (`42` for an `int` parameter). Application code gets no such
conversion; see [strict parameters](definitions.md#parameters).

### Generation counters

`Counters` lists the definition's generation counters: one per tag, and
`def:<id>` for a definition with `invalidated_by`. Their values end the key,
after `~g`, so a change can invalidate every entry at once (see
[invalidated_by](definitions.md#invalidated_by)). The key pattern shows them as
placeholders, in name order: `~g{admin}.{users}`.

By default `inspect` does not connect to Redis, so for such a definition it
prints the key without that last segment:

```
  Counters ............................................ def:member.active_list
  Key pattern  cc:member:active_list:v1:organization_id={int}:~g{def:member.active_list}
  Key ............................. cc:member:active_list:v1:organization_id=1

   INFO  At runtime the key also ends with "~g" and the value of each counter. Pass --generation=name=value for every counter, or --live to read them from Redis.
```

Give the counter values yourself to build the full key:

```bash
php artisan cache-contract:inspect member.active_list --param=organization_id=1 --generation=def:member.active_list=8123
```

```
  Key ...................... cc:member:active_list:v1:organization_id=1:~g8123
```

Or pass `--live` to read the current values from Redis and see the key the
application uses right now, the one to look for with `redis-cli`. `--live`
only reads. A counter that does not exist yet is reported and not created:
the next cache call creates it, so no entry of the definition is cached at
that moment. When Redis cannot be reached, `inspect` prints one error line
and exits with a non-zero code. `--generation` and `--live` cannot be used
together.

A secret parameter is never accepted with `--param`: `inspect` asks for it
without showing what you type, and prints only its hash. See
[secret parameters](definitions.md#secret-parameters).

A mistyped ID gets a suggestion:

```
   ERROR  Unknown cache definition "user.profle".

  Did you mean: user.profile?
```

## cache-contract:scan

Lists the cache calls in your source code: where each one is, what it does
and how much of its key can be read.

```bash
php artisan cache-contract:scan --path=app
```

```
+--------------------------------------------+------+---------------------------+--------+-----------+----------+--------------+
| File                                       | Line | In                        | Style  | Operation | Key kind | Key          |
+--------------------------------------------+------+---------------------------+--------+-----------+----------+--------------+
| app/Http/Controllers/ProfileController.php | 11   | ProfileController::show   | facade | remember  | template | profile:{id} |
| app/Http/Controllers/ProfileController.php | 16   | ProfileController::update | facade | forget    | template | profile:{id} |
| app/Http/Controllers/ProfileController.php | 17   | ProfileController::update | facade | forget    | exact    | settings     |
+--------------------------------------------+------+---------------------------+--------+-----------+----------+--------------+

3 usage points in 1 files. A usage point is a place in the source, not a runtime call count.
Keys: 1 exact, 2 template, 0 opaque, 0 unresolved, 0 none
```

| Option | Meaning |
| :--- | :--- |
| `--path=app/Billing` | Scan only this file or directory instead of `scanner.paths`; relative to the project root or absolute, repeatable. `scanner.exclude` still applies. |
| `--json` | Machine-readable JSON instead of the table. |

The command exits with a non-zero code when a path could not be scanned, so
a typo in `--path` or a file that does not parse is never missed. What the
scanner finds, how it reads keys and its limits: [Scanner](scanner.md).
