"""Create a self-signed HTTPS certificate for LAN use (needed for phone-camera scanning).

Usage:  python scripts/make_cert.py [extra-ip-or-hostname ...]
Writes config/cert.pem and config/key.pem, valid for localhost + this PC's IPs.
Then set in config/config.json:
    "ssl_certfile": "config/cert.pem", "ssl_keyfile": "config/key.pem"
"""
import datetime
import ipaddress
import socket
import sys
from pathlib import Path

from cryptography import x509
from cryptography.hazmat.primitives import hashes, serialization
from cryptography.hazmat.primitives.asymmetric import rsa
from cryptography.x509.oid import NameOID

OUT = Path(__file__).resolve().parent.parent / "config"


def local_ips():
    ips = {"127.0.0.1"}
    try:
        for info in socket.getaddrinfo(socket.gethostname(), None, socket.AF_INET):
            ips.add(info[4][0])
    except OSError:
        pass
    return sorted(ips)


def main():
    names = ["localhost", socket.gethostname()]
    ips = local_ips()
    for extra in sys.argv[1:]:
        try:
            ipaddress.ip_address(extra)
            ips.append(extra)
        except ValueError:
            names.append(extra)
    key = rsa.generate_private_key(public_exponent=65537, key_size=2048)
    subject = x509.Name([x509.NameAttribute(NameOID.COMMON_NAME, "Gold Scanner (local)")])
    san = [x509.DNSName(n) for n in dict.fromkeys(names)] + \
          [x509.IPAddress(ipaddress.ip_address(i)) for i in dict.fromkeys(ips)]
    now = datetime.datetime.now(datetime.timezone.utc)
    cert = (x509.CertificateBuilder().subject_name(subject).issuer_name(subject)
            .public_key(key.public_key()).serial_number(x509.random_serial_number())
            .not_valid_before(now - datetime.timedelta(days=1))
            .not_valid_after(now + datetime.timedelta(days=825))
            .add_extension(x509.SubjectAlternativeName(san), critical=False)
            .add_extension(x509.BasicConstraints(ca=False, path_length=None), critical=True)
            .sign(key, hashes.SHA256()))
    OUT.mkdir(exist_ok=True)
    (OUT / "cert.pem").write_bytes(cert.public_bytes(serialization.Encoding.PEM))
    (OUT / "key.pem").write_bytes(key.private_bytes(serialization.Encoding.PEM,
                                  serialization.PrivateFormat.TraditionalOpenSSL, serialization.NoEncryption()))
    print("Created config/cert.pem and config/key.pem for:", ", ".join(names + ips))


if __name__ == "__main__":
    main()
