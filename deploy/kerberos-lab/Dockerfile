FROM debian:bookworm-slim

RUN apt-get update \
    && DEBIAN_FRONTEND=noninteractive apt-get install -y --no-install-recommends \
        samba samba-ad-dc samba-ad-provision samba-dsdb-modules samba-vfs-modules \
        krb5-user python3-samba dnsutils \
    && rm -rf /var/lib/apt/lists/* /etc/samba/smb.conf

COPY deploy/kerberos-lab/provision.py /usr/local/bin/provision-ad.py
COPY deploy/kerberos-lab/entrypoint.sh /usr/local/bin/sag-lab-ad
RUN chmod 0755 /usr/local/bin/sag-lab-ad

ENTRYPOINT ["/usr/local/bin/sag-lab-ad"]
