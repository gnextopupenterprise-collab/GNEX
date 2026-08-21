#!/usr/bin/env bash
set -euo pipefail

source_dir=/opt/gnex-source
live_dir=/var/www/gnex
state_dir=/var/lib/gnex-deploy
lock_file=/run/lock/gnex-autodeploy.lock

exec 9>"$lock_file"
flock -n 9 || exit 0

test -d "$source_dir/.git"
test -d "$live_dir"
install -d -m 750 "$state_dir"

cd "$source_dir"
git_safe=(git -c safe.directory="$source_dir")
"${git_safe[@]}" fetch --quiet origin main
new_commit=$("${git_safe[@]}" rev-parse origin/main)
old_commit=$(cat "$state_dir/last_commit" 2>/dev/null || "${git_safe[@]}" rev-parse HEAD)

if [[ "$new_commit" == "$old_commit" ]]; then
  exit 0
fi

"${git_safe[@]}" merge-base --is-ancestor "$old_commit" "$new_commit"
"${git_safe[@]}" pull --quiet --ff-only origin main

mapfile -t changed_files < <("${git_safe[@]}" diff --name-only --diff-filter=ACMRT "$old_commit" "$new_commit")
if (( ${#changed_files[@]} == 0 )); then
  printf '%s\n' "$new_commit" >"$state_dir/last_commit"
  exit 0
fi

backup_dir="/var/backups/gnex-auto-$(date +%Y%m%d-%H%M%S)-${old_commit:0:8}"
install -d -m 750 "$backup_dir"

for file in "${changed_files[@]}"; do
  [[ "$file" != /* && "$file" != *".."* ]] || exit 1
  [[ -f "$source_dir/$file" ]] || continue
  if [[ -f "$live_dir/$file" ]]; then
    install -d -m 750 "$backup_dir/$(dirname "$file")"
    cp -a "$live_dir/$file" "$backup_dir/$file"
  fi
  install -d -o gnexdeploy -g www-data -m 775 "$live_dir/$(dirname "$file")"
  install -o gnexdeploy -g www-data -m 664 "$source_dir/$file" "$live_dir/$file"
done

printf '%s\n' "$new_commit" >"$state_dir/last_commit"
logger -t gnex-autodeploy "Deployed ${old_commit:0:8} -> ${new_commit:0:8} (${#changed_files[@]} files)"
