.. include:: /Includes.rst.txt

.. _adr-023-audit-hash-chain-hmac:

=============================================
ADR-023: Audit hash chain HMAC consideration
=============================================

.. contents:: Table of contents
   :local:
   :depth: 2

Status
======

Proposed

Date
====

2026-03-28

Context
=======

The current audit hash chain (see :ref:`adr-006-audit-logging`) uses plain
SHA-256 hashing without a secret key. While this provides tamper detection
against accidental corruption or naive modification, an attacker with
database-level access can recompute valid hashes after altering audit log
entries, rendering the chain ineffective against adversarial tampering.

The original hash chain was designed for tamper *detection* (corruption,
accidental modification), not for tamper *resistance* against
database-privileged attackers. This threat model gap was identified during
a subsequent security review.

Decision
========

Propose migrating the audit hash chain from plain SHA-256 to HMAC-SHA256,
keyed with an HMAC key derived from the master key:

-  The HMAC key would be derived from the master key using HKDF with a
   dedicated context string (e.g., ``"nr-vault-audit-hmac-v1"``), ensuring
   cryptographic separation from the encryption key.
-  New audit entries would be signed with HMAC-SHA256 instead of plain
   SHA-256.
-  A data migration would rehash existing entries with the HMAC key,
   or a "chain epoch" marker would separate legacy SHA-256 entries from
   new HMAC-SHA256 entries.

Trade-offs
==========

Benefits
--------

-  **Adversarial resistance**: An attacker with database access but without
   the master key cannot forge valid HMAC values.
-  **Cryptographic separation**: HKDF-derived HMAC key is independent of
   the encryption key material.
-  **Standards alignment**: HMAC-SHA256 is the standard construction for
   keyed message authentication.

Risks
-----

-  **Data migration**: Existing hash chain entries must be either rehashed
   (requiring the HMAC key and careful orchestration) or left as a legacy
   epoch with reduced guarantees.
-  **HMAC key lifecycle**: Master key rotation requires re-deriving the HMAC
   key. If the old master key is discarded, verification of historical
   entries derived from it becomes impossible unless the old HMAC key is
   retained separately.
-  **Operational complexity**: Introduces a dependency between the audit
   subsystem and the master key provider, coupling two previously
   independent components.

Why not implemented initially
=============================

The hash chain was designed for tamper *detection* -- catching corruption,
accidental modification, or naive tampering. The threat model did not
originally include database-privileged attackers who could recompute hashes.

This was a deliberate scoping decision: the initial implementation
prioritized self-contained integrity checking without external key
dependencies. The HMAC enhancement represents a threat model upgrade
identified during security review, and is recorded here as a proposed
improvement for future implementation.

Related decisions
=================

-  :ref:`adr-006-audit-logging` - Core audit logging model with hash chain
-  :ref:`adr-003-master-key-management` - Master key from which HMAC key
   would be derived
