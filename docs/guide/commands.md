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

Terminal input is always text, so `inspect` converts `--param` values to the
declared type (`42` for an `int` parameter). Application code gets no such
conversion; see [strict parameters](definitions.md#parameters).

A secret parameter is never accepted with `--param`: `inspect` asks for it
without showing what you type, and prints only its hash. See
[secret parameters](definitions.md#secret-parameters).

A mistyped ID gets a suggestion:

```
   ERROR  Unknown cache definition "user.profle".

  Did you mean: user.profile?
```
