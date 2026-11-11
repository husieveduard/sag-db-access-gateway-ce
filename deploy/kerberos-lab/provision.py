#!/usr/bin/python3
"""Provision only a new disposable SAG.TEST domain; passwords enter on stdin."""
import json
import logging
import ipaddress
import os
from pathlib import Path
import sys
import subprocess

import ldb
from samba.auth import system_session
from samba.ndr import ndr_pack
from samba import dsdb
from samba.dcerpc import dnsp
from samba.provision import provision
from samba.samdb import SamDB


root = Path('/var/lib/samba')
if (root / 'private/sam.ldb').exists() or os.environ.get('SAG_KERBEROS_LAB') != '1':
    raise SystemExit(2)
data = json.load(sys.stdin)
logger = logging.getLogger('sag-lab')
logger.addHandler(logging.StreamHandler(sys.stderr))
logger.setLevel(logging.WARNING)
result = provision(logger, system_session(), realm='SAG.TEST', domain='SAG',
                   hostname='dc', serverrole='active directory domain controller',
                   dns_backend='SAMBA_INTERNAL', targetdir=str(root),
                   adminpass=data['admin_password'], use_rfc2307=True,
                   dom_for_fun_level=dsdb.DS_DOMAIN_FUNCTION_2008_R2,
                   hostip=data.get('dc_ip'),
                   skip_sysvolacl=True)
sam = SamDB(url=result.paths.samdb, session_info=system_session(), lp=result.lp)
for username in ('sqlservice', 'gateway'):
    sam.newuser(username, data[username + '_password'])
    record = sam.search(base=sam.domain_dn(), expression='(sAMAccountName=' + username + ')',
                        attrs=['distinguishedName'])[0]
    change = ldb.Message()
    change.dn = record.dn
    change['msDS-SupportedEncryptionTypes'] = ldb.MessageElement('24', ldb.FLAG_MOD_REPLACE, 'msDS-SupportedEncryptionTypes')
    if username == 'sqlservice':
        change['servicePrincipalName'] = ldb.MessageElement([
            'MSSQLSvc/sql.sag.test:1433', 'MSSQLSvc/sql.sag.test'], ldb.FLAG_MOD_REPLACE, 'servicePrincipalName')
    sam.modify(change)
if 'sql_ip' in data:
    record = dnsp.DnssrvRpcRecord()
    record.wType, record.rank, record.dwSerial, record.dwTtlSeconds = dnsp.DNS_TYPE_A, dnsp.DNS_RANK_ZONE, 1, 300
    record.data = str(ipaddress.IPv4Address(data['sql_ip']))
    sam.add({'dn': 'DC=sql,DC=sag.test,CN=MicrosoftDNS,DC=DomainDnsZones,' + sam.domain_dn(),
             'objectClass': ['top', 'dnsNode'], 'dnsRecord': [ndr_pack(record)], 'dNSTombstoned': 'FALSE'})
os.umask(0o077)
keys = root / 'private/sag-keys'
keys.mkdir(mode=0o700)
for filename, principals in [('gateway.keytab', ['gateway']), ('sql.keytab',
        ['sqlservice', 'MSSQLSvc/sql.sag.test:1433', 'MSSQLSvc/sql.sag.test'])]:
    for principal in principals:
        subprocess.run(['samba-tool', 'domain', 'exportkeytab', str(keys / filename),
                        '--principal=' + principal, '--configfile=' + result.paths.smbconf], check=True,
                       stdout=subprocess.DEVNULL)
    (keys / filename).chmod(0o600)
kdc = str(ipaddress.IPv4Address(data.get('dc_ip', '127.0.0.1')))
Path('/etc/krb5.conf').write_text('[libdefaults]\n default_realm = SAG.TEST\n dns_lookup_kdc = false\n dns_lookup_realm = false\n[realms]\n SAG.TEST = {\n  kdc = ' + kdc + '\n }\n')
(root / 'sag-lab-ready').touch()
print('disposable_ad_provisioned')
