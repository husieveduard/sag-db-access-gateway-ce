#!/bin/sh
set -eu
# Provisioning is an explicit stdin-only docker exec operation. The container
# exposes no host ports and never contacts an existing organization domain.
while [ ! -f /var/lib/samba/sag-lab-ready ]; do
    sleep 0.2
done
exec samba --interactive --no-process-group --configfile=/var/lib/samba/etc/smb.conf
