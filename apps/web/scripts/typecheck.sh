#!/bin/sh
# make typecheck: type-check the web app and the packages it imports.
# Leftover build output (.output, the production build's cache, stale incremental build info) is cleared
# first, then the Nuxt types are regenerated and each TypeScript project checked on its own, so a failure
# always prints its errors.
cd "$(dirname "$0")/.."
rm -rf .output node_modules/.cache/nuxt .nuxt/*.tsbuildinfo
npx nuxi prepare > /dev/null || exit 1

status=0
for project in app shared node server; do
  if ! npx vue-tsc --noEmit -p ".nuxt/tsconfig.$project.json"; then
    echo "Type errors in the $project project (above)."
    status=1
  fi
done
[ $status -eq 0 ] && echo "No type errors."
exit $status
