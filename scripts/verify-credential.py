#!/usr/bin/env python3
"""Dev-only INDEPENDENT Ed25519 VC-JWS verifier (ADR-001 follow-up #2).

This is a third-party verification path that does NOT use the app's own PHP/sodium code:
it re-checks an issued VC-JWT signature with Python `cryptography` (Ed25519). It exists to
prove that anyone — not just learning-nc — can verify a credential issued by this instance.

NOT an app dependency and NEVER shipped: it lives in repo-root scripts/, which the app
release Makefile allowlist (appinfo/css/img/js/lib/templates) and the deploy bundle both
exclude. (Re-audited by the 155-07 leakage gate.)

Usage:
    verify-credential.py <compact-jwt> <base64url-public-key-x>

Where <base64url-public-key-x> is the JWK `x` of the did.json verificationMethod (the raw
32-byte Ed25519 public key, base64url). Verifies the Ed25519 signature over the JWS signing
input `header.payload` — it does NOT validate VC claims, because the payload is an OB3
credential object, not JWT registered claims.

Exit codes: 0 = signature valid; 1 = signature invalid; 2 = bad usage; 3 = malformed JWT.
"""
import base64
import sys

from cryptography.exceptions import InvalidSignature
from cryptography.hazmat.primitives.asymmetric.ed25519 import Ed25519PublicKey


def b64u_decode(segment: str) -> bytes:
    """Base64url-decode, restoring any stripped '=' padding."""
    return base64.urlsafe_b64decode(segment + "=" * (-len(segment) % 4))


def main() -> int:
    if len(sys.argv) != 3:
        print("usage: verify-credential.py <jwt> <base64url-public-key-x>", file=sys.stderr)
        return 2

    jwt, x_b64u = sys.argv[1], sys.argv[2]
    parts = jwt.split(".")
    if len(parts) != 3:
        print("FAIL: malformed JWT (expected 3 segments)", file=sys.stderr)
        return 3

    signing_input = (parts[0] + "." + parts[1]).encode("ascii")
    signature = b64u_decode(parts[2])
    public_key = Ed25519PublicKey.from_public_bytes(b64u_decode(x_b64u))

    try:
        public_key.verify(signature, signing_input)
    except InvalidSignature:
        print("FAIL: Ed25519 signature is invalid")
        return 1

    print("OK: Ed25519 signature is valid (independently verified by Python cryptography)")
    return 0


if __name__ == "__main__":
    sys.exit(main())
