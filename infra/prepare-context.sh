#!/bin/sh
set -eu
rm -rf backend-public
cp -a ../backend/public backend-public
printf '%s\n' 'Prepared infra/backend-public for the Nginx image.'
