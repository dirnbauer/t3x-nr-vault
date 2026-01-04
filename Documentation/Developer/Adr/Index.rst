.. include:: /Includes.rst.txt

.. _adr-index:

=================================
Architecture decision records
=================================

This section documents significant architectural decisions made during the
development of nr-vault, along with the context and consequences of each
decision.

Architecture Decision Records (ADRs) capture important decisions along with
their context and consequences. They provide a historical record of why
certain decisions were made, helping future maintainers understand the
codebase.

.. contents:: Table of contents
   :local:
   :depth: 1

Overview
========

=======  ==========================================  ========
ADR      Title                                       Status
=======  ==========================================  ========
001      :ref:`adr-001-uuid-v7`                      Accepted
002      :ref:`adr-002-envelope-encryption`          Accepted
003      :ref:`adr-003-master-key-management`        Accepted
004      :ref:`adr-004-tca-integration`              Accepted
005      :ref:`adr-005-access-control`               Accepted
006      :ref:`adr-006-audit-logging`                Accepted
007      :ref:`adr-007-secret-metadata`              Accepted
008      :ref:`adr-008-http-client`                  Accepted
009      :ref:`adr-009`                              Accepted
=======  ==========================================  ========

.. toctree::
   :maxdepth: 1
   :titlesonly:

   ADR-001-UuidV7Identifiers
   ADR-002-EnvelopeEncryption
   ADR-003-MasterKeyManagement
   ADR-004-TcaIntegration
   ADR-005-AccessControl
   ADR-006-AuditLogging
   ADR-007-SecretMetadata
   ADR-008-HttpClient
   ADR-009-ExtensionConfigurationSecrets
