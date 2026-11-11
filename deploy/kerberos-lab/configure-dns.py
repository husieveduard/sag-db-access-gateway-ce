#!/usr/bin/python3
"""Add private NetBIOS/PTR records required by SQL Server's AD lookup."""
import ipaddress
import json
import os
import sys
from types import SimpleNamespace

from samba.credentials import Credentials, MUST_USE_KERBEROS
from samba.param import LoadParm
from samba.netcmd.dns import cmd_add_record, cmd_zonecreate

if os.environ.get('SAG_KERBEROS_LAB') != '1':
    raise SystemExit(2)
data = json.load(sys.stdin)
dc = ipaddress.IPv4Address(data['dc_ip'])
sql = ipaddress.IPv4Address(data['sql_ip'])
lp = LoadParm()
lp.load('/var/lib/samba/etc/smb.conf')
creds = Credentials()
creds.guess(lp)
creds.set_username('Administrator')
creds.set_domain('SAG')
creds.set_realm('SAG.TEST')
creds.set_password(data['admin_password'])
creds.set_kerberos_state(MUST_USE_KERBEROS)
sambaopts = SimpleNamespace(get_loadparm=lambda: lp)
credopts = SimpleNamespace(get_credentials=lambda unused: creds)
options = dict(sambaopts=sambaopts, credopts=credopts)
reverse = '.'.join(str(dc).split('.')[:3][::-1]) + '.in-addr.arpa'
cmd_zonecreate().run('dc.sag.test', reverse, 'longhorn', **options)
for zone, name, record_type, value in [
        ('sag.test', 'sag', 'A', str(dc)),
        (reverse, str(dc).split('.')[-1], 'PTR', 'dc.sag.test'),
        (reverse, str(sql).split('.')[-1], 'PTR', 'sql.sag.test')]:
    cmd_add_record().run('dc.sag.test', zone, name, record_type, value, **options)
print('private_ad_dns_verified')
