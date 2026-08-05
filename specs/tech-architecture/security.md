# Security

- BASIC AUTH password encrypted with authenticated ciphertext derived from WordPress salts.
- Capability and nonce checks on every mutation.
- Prepared dynamic SQL and explicit query allowlists.
- Output escaping at render boundaries.
- Atomic synchronization lock, 60-second HTTP timeout, transactions, and safe error logs.
- Credentials and Authorization headers never enter logs or HTML.
