#!/usr/bin/env zsh
# scan-repo.sh
#
# Collects cache usage facts from a Laravel repository for the
# "cache usage in the wild" report. Read-only: it never modifies the repository.
# Written for zsh (arrays, glob qualifiers and ${(f)...} splitting are zsh syntax).
#
# Usage:
#   ./scan-repo.sh [REPO_DIR] [-o OUTPUT_FILE]
#   ./scan-repo.sh --rank DIR_WITH_REPOS
#
# Examples:
#   ./scan-repo.sh ~/Iceberg-Cache/Repo-Scan/firefly-iii -o firefly-scan.txt
#   ./scan-repo.sh --rank ~/Iceberg-Cache/Repo-Scan
#
# If the file is not executable, run it with:  zsh scan-repo.sh ...
#
# Numbers are usage points in the source, not runtime call counts.
# Search is text based: aliases, variables and indirect usage still need a manual look.

emulate -L zsh

# ---------------------------------------------------------------- patterns --
# \bCache:: avoids matching class names that merely end in Cache (ReportCache::, ConfigCache::).
PAT_ALL='\bCache::|\bcache\(|Redis::|->remember\(|->tags\(|RateLimiter::'
PAT_CACHE='\bCache::|\bcache\('
PAT_FILES='\bCache::|\bcache\(|Contracts\\Cache|Cache\\Repository|CacheManager|\$this->cache|\$cache->|\$store->|app\(.cache.\)|\$app\[.cache.\]'
PAT_IMPORT='Contracts\\Cache|Facades\\Cache|Cache\\Repository|CacheManager| as Cache'
PAT_RECV='\$this->cache\b|\$cache->|\$store->|Cache::store\(|app\(.cache.\)|\$app\[.cache.\]'
PAT_OPS='(\bCache::(store|tags|driver|memo)\([^)]*\)->|\bCache::|cache\(\)->|\$cache->|\$this->cache->)[a-zA-Z]+\('
PAT_KEYS='[kK]ey[[:space:]]*=[^>=]|const .*(TTL|CACHE|TIME)|[tT]tl|[mM]inutes|[sS]econds|86400|3600'
PAT_INVAL_FACADE='forever\(|rememberForever|\bsear\(|\bCache::(forget|flush|clear|has|missing|lock)|cache\(\)->(forget|flush|clear|has|missing|lock)|flexible\('
# forget/has/missing stay out of this list: section 5 already prints them, and in a large
# repository (Pixelfed: 428 forget lines) printing them twice doubles the output.
PAT_DIRECT_OPS='(get|set|put|add|pull|clear|flush|remember|rememberForever|sear|forever|increment|decrement|many|putMany|delete|lock|flexible|memo)'
PAT_DIRECT="\\bCache::${PAT_DIRECT_OPS}\\(|cache\\(\\)->${PAT_DIRECT_OPS}\\("
# cache('key') reads and cache([...]) writes: the helper called with an argument.
PAT_HELPER='\bcache\([^)]'
PAT_WRAPPER_DEF='^[[:space:]]*(final |abstract )?class [A-Za-z_]*Cache[A-Za-z_]*'
PAT_WRAPPER_NEW='new [A-Za-z_\\]*Cache[A-Za-z_]*\('
PAT_INVAL_RECV='->(forget|flush|clear|pull|lock|missing)\('
PAT_MODEL_EVENTS='static::(saved|saving|updated|updating|deleted|deleting|created|creating|restored|replicating)\(|#\[ObservedBy|->observe\(|class [A-Za-z_]*Observer\b'

# ----------------------------------------------------------------- helpers --
usage() {
  print -r -- "Usage:"
  print -r -- "  scan-repo.sh [REPO_DIR] [-o OUTPUT_FILE]"
  print -r -- "  scan-repo.sh --rank DIR_WITH_REPOS"
}

# grep over PHP files only, skipping vendor/tests/etc. Callers add -n, -c, -l, -oh.
g() { grep -rEH --include='*.php' "${EXCL[@]}" "$@"; }

# Prints the output of a command, or "(none)" when it prints nothing.
show() {
  local result
  result=$("$@" 2>/dev/null)
  if [[ -n $result ]]; then
    print -r -- "$result"
  else
    print -r -- "(none)"
  fi
}

# Counts matching lines for a pattern in the scanned paths.
cnt() { g -n -e "$1" "${P[@]}" 2>/dev/null | wc -l | tr -d ' '; }

sec() { print -r -- ""; print -r -- "## $1"; }
sub() { print -r -- ""; print -r -- "### $1"; }

# Directories to scan (only the ones that exist) and directories to skip.
setup_paths() {
  P=()
  local d
  for d in app routes database config src modules; do
    [[ -d $d ]] && P+=("$d")
  done
  EXCL=(--exclude-dir=vendor --exclude-dir=node_modules --exclude-dir=tests --exclude-dir=Tests --exclude-dir=storage)
}

ops_list()  { g -oh -e "$PAT_OPS" "${P[@]}" | sort | uniq -c | sort -nr; }
indirect()  { g -n -e 'RateLimiter::' -e 'ThrottleRequests' -e 'throttle:' -e 'Cache::store\(' "${P[@]}" | head -40; }
density()   { g -c -e "$PAT_FILES" "${P[@]}" | grep -v ':0$' | sort -t: -k2 -nr | head -40; }

# ------------------------------------------------------------ single repo --
scan_repo() {
  setup_paths
  if (( ${#P} == 0 )); then
    print -u2 "No app/src/routes/config/database/modules directory found in $PWD"
    return 1
  fi

  print -r -- "# Cache scan: ${PWD:t}"
  print -r -- "Generated: $(date '+%Y-%m-%d %H:%M')"

  # ---- 0. repository ------------------------------------------------------
  sec "0. Repository"
  print -r -- "- scanned paths: ${P[*]}"
  print -r -- "- remote: $(git remote get-url origin 2>/dev/null || print unknown)"
  print -r -- "- rev-head: $(git rev-parse HEAD 2>/dev/null || print unknown)"
  print -r -- "- branch: $(git rev-parse --abbrev-ref HEAD 2>/dev/null || print unknown)"

  local lv=""
  if [[ -f composer.lock ]]; then
    lv=$(awk '/"name": "laravel\/framework"/{f=1} f && /"version"/{print; exit}' composer.lock \
         | tr -d ' ",' | sed 's/^version://')
  fi
  print -r -- "- Laravel (installed, from composer.lock): ${lv:-not available}"
  print -r -- "- composer.json constraints:"
  grep -E '"(php|laravel/framework)"[[:space:]]*:' composer.json 2>/dev/null | sed 's/^/    /'

  local -a lic
  lic=(LICENSE*(N) license*(N) COPYING*(N))
  if (( ${#lic} )); then
    print -r -- "- license file: ${lic[1]}"
    head -3 -- "${lic[1]}" | sed 's/^/    /'
  else
    print -r -- "- license file: not found"
  fi

  print -r -- "- directories named config:"
  find . \( -path ./vendor -o -path ./node_modules \) -prune -o -type d -iname config -print | sed 's/^/    /'

  # ---- 1. counts ----------------------------------------------------------
  sec "1. Counts"
  print -r -- "- raw matches (all patterns): $(cnt "$PAT_ALL")"
  print -r -- "- Cache:: / cache( matches:  $(cnt "$PAT_CACHE")"
  print -r -- "- ->remember( matches:       $(cnt '->remember\(')"
  print -r -- "- ->tags( matches:           $(cnt '->tags\(')"
  print -r -- "- Redis:: matches:           $(cnt 'Redis::')"
  print -r -- "- RateLimiter:: matches:     $(cnt 'RateLimiter::')"

  sub "->tags( matches (separate Eloquent relations from cache tags by hand)"
  show g -n -e '->tags\(' "${P[@]}"
  sub "Real cache tags (Cache::tags / cache()->tags)"
  show g -n -e 'Cache::tags\(' -e 'cache\(\)->tags\(' "${P[@]}"
  sub "Direct Redis usage"
  show g -n -e 'Redis::' "${P[@]}"

  # ---- 2. call styles -----------------------------------------------------
  sec "2. Call styles"
  sub "Imports, aliases and injected cache types"
  show g -n -e "$PAT_IMPORT" "${P[@]}"
  sub "Other receivers (\$this->cache, \$cache->, \$store->, Cache::store())"
  show g -n -e "$PAT_RECV" "${P[@]}"
  print -r -- ""
  print -r -- "(For every plain \$cache / \$store receiver, check where it is assigned:"
  print -r -- " a Laravel cache store, or a library's own cache?)"

  sub "Custom cache wrapper candidates (classes with Cache in the name, and where they are created)"
  show g -n -e "$PAT_WRAPPER_DEF" "${P[@]}"
  show g -n -e "$PAT_WRAPPER_NEW" "${P[@]}"
  print -r -- ""
  print -r -- "(A wrapper hides cache usage from Cache:: / cache( searches. If a class is listed,"
  print -r -- " count its method calls in section 3 and read its key-building code by hand.)"

  # ---- 3. operations ------------------------------------------------------
  sec "3. Operations seen (count, receiver and method)"
  show ops_list

  sub "Locations of direct calls (get, set, put, add, pull, clear, flush, remember, forever, increment, many, delete, lock, flexible)"
  show g -n -e "$PAT_DIRECT" "${P[@]}"
  sub "Helper form cache('key') / cache([...]) (reads and bulk writes; not counted in the list above)"
  show g -n -e "$PAT_HELPER" "${P[@]}"

  # ---- 4. files, keys and TTL --------------------------------------------
  sec "4. Files using the cache, keys and TTL"
  local -a FILES
  FILES=(${(f)"$(g -l -e "$PAT_FILES" "${P[@]}")"})
  print -r -- "- ${#FILES} files use the cache"
  if (( ${#FILES} )); then
    printf '    %s\n' "${FILES[@]}"

    sub "Key assignments, TTL constants and duration expressions (first 200 lines)"
    grep -nHE "$PAT_KEYS" "${FILES[@]}" 2>/dev/null | head -200

    sub "Methods that look like key builders (read their bodies by hand)"
    grep -nHE 'function [A-Za-z_]*([Cc]ache|[Kk]ey)[A-Za-z_]*\(' "${FILES[@]}" 2>/dev/null
  fi

  # ---- 5. invalidation, forever, locks -----------------------------------
  sec "5. Invalidation, forever, locks, flexible"
  sub "Facade and helper forms"
  show g -n -e "$PAT_INVAL_FACADE" "${P[@]}"
  if (( ${#FILES} )); then
    sub "Other receivers (->forget, ->flush, ->clear, ->pull, ->lock, ->missing) inside files that use the cache"
    show grep -nHE "$PAT_INVAL_RECV" "${FILES[@]}"

    sub "Model lifecycle hooks inside cache-using files (candidates for manual invalidation)"
    show grep -nHE "$PAT_MODEL_EVENTS" "${FILES[@]}"
  fi

  # ---- 6. driver and environment -----------------------------------------
  sec "6. Driver and environment"
  local -a envs
  envs=(.env.example*(N))
  if (( ${#envs} )); then
    grep -nE 'CACHE|REDIS|SESSION_DRIVER|QUEUE' "${envs[@]}"
  else
    print -r -- "(no .env.example* file)"
  fi
  sub "cache.php config files"
  local f
  find . \( -path ./vendor -o -path ./node_modules \) -prune -o -type f -name cache.php -print | while read -r f; do
    print -r -- "- $f"
    grep -nE "'default'|'prefix'|'stores'" "$f" | sed 's/^/    /'
  done

  # ---- 7. indirect users -------------------------------------------------
  sec "7. Indirect users of the cache store (rate limiter, throttle, Cache::store)"
  show indirect

  # ---- 8. density --------------------------------------------------------
  sec "8. Usage density per file (file:count)"
  show density

  print -r -- ""
  print -r -- "---"
  print -r -- "Notes: numbers are usage points, not runtime call counts."
  print -r -- "Fill in by hand: tags false positives, key family table, TTL units,"
  print -r -- "key-builder method bodies, origin of plain \$cache receivers."
}

# ------------------------------------------------------------- rank mode ----
# Counts real cache matches (no ->tags(, no rate limiter) per repository so you
# can pick the repositories with the heaviest cache usage first.
rank_repos() {
  local base=${1:A} d p n
  local -a paths
  for d in $base/*(N/); do
    d=${d%/}
    paths=()
    for p in app src modules; do
      [[ -d $d/$p ]] && paths+=("$d/$p")
    done
    (( ${#paths} )) || continue
    n=$(grep -rEn --include='*.php' --exclude-dir=vendor --exclude-dir=node_modules --exclude-dir=tests --exclude-dir=Tests \
          -e 'Cache::|\bcache\(|Redis::|->remember\(' "${paths[@]}" 2>/dev/null | wc -l | tr -d ' ')
    print -r -- "$n ${d:t}"
  done | sort -nr
}

# -------------------------------------------------------------------- main --
rank_mode=0
out=""
repo="."

while (( $# )); do
  case $1 in
    -h|--help)   usage; exit 0 ;;
    -o|--output)
      if [[ -z ${2:-} ]]; then print -u2 "Missing file name after $1"; usage; exit 1; fi
      out=$2; shift 2 ;;
    --rank)      rank_mode=1; shift ;;
    -*)          print -u2 "Unknown option: $1"; usage; exit 1 ;;
    *)           repo=$1; shift ;;
  esac
done

[[ -n $out ]] && out=${out:A}

if [[ ! -d $repo ]]; then
  print -u2 "Not a directory: $repo"
  exit 1
fi

if (( rank_mode )); then
  rank_repos "$repo"
  exit 0
fi

cd -- "$repo" || exit 1

if [[ -n $out ]]; then
  scan_repo 2>&1 | tee -- "$out"
else
  scan_repo
fi
