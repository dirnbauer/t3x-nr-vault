.. include:: /Includes.rst.txt

.. _adr-020-master-key-request-lifetime-caching:

=============================================
ADR-020: Master key request-lifetime caching
=============================================

.. contents:: Table of contents
   :local:
   :depth: 2

Status
======

Accepted

Date
====

2026-03-28

Context
=======

Master key providers re-read the key material from disk, environment
variables, or re-derived it via HKDF on every decrypt operation. In
requests that decrypt multiple secrets (e.g., frontend rendering with
several vault-backed content elements), this caused repeated filesystem
reads or HKDF computations for the same key material.

The master key does not change within a single HTTP request, so repeated
derivation is pure overhead.

Decision
========

Cache the derived master key in memory for the lifetime of the current
request:

-  On first access, the master key provider reads/derives the key and
   stores it in a private property.
-  Subsequent decrypt operations within the same request reuse the cached
   key without additional I/O or derivation.
-  On object destruction (end of request), the cached key material is
   securely wiped using ``sodium_memzero()`` to prevent it from lingering
   in process memory.

This follows the principle of minimizing key material exposure: the key
exists in memory only for the duration of the request and is actively
cleared rather than left for garbage collection.

Consequences
============

Positive
--------

-  **One key derivation per request** instead of per-decrypt, eliminating
   redundant I/O and HKDF computations.
-  **Secure cleanup**: ``sodium_memzero()`` on destruct ensures key material
   does not persist in memory beyond the request.
-  **Transparent**: No API changes; callers are unaware of the caching.

Negative
--------

-  **Memory residency**: The master key remains in process memory for the
   full request duration rather than being immediately discarded after each
   use.
-  **Destructor dependency**: Relies on PHP object lifecycle for cleanup;
   long-running processes (e.g., workers) must ensure timely destruction.

Related decisions
=================

-  :ref:`adr-003-master-key-management` - Master key management architecture
-  :ref:`adr-002-envelope-encryption` - Envelope encryption model
