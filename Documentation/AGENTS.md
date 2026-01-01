# AGENTS.md - Documentation

> Documentation guidelines for nr-vault using TYPO3 docs standards.

## Structure

```
Documentation/
├── Index.rst              # Main entry point
├── Includes.rst.txt       # Common includes
├── Sitemap.rst            # Auto-generated sitemap
├── guides.xml             # phpDocumentor configuration
├── Configuration/         # Extension configuration docs
├── Developer/             # Developer/API documentation
├── Installation/          # Installation guide
├── Introduction/          # Overview and features
├── Security/              # Security considerations
└── Usage/                 # User guide
```

## Rendering Documentation

```bash
# Render locally
make docs

# Output in Documentation-GENERATED-temp/
```

## RST Conventions

### Headings
```rst
=======
Level 1
=======

Level 2
=======

Level 3
-------

Level 4
~~~~~~~
```

### TYPO3-Specific Directives

```rst
.. confval:: masterKeyPath
   :type: string
   :default: /var/secrets/vault.key

   Path to the master encryption key file.

.. versionadded:: 1.2.0
   Added support for XChaCha20-Poly1305.

.. deprecated:: 1.3.0
   Use `vault:rotate-master-key` instead.
```

### Code Blocks
```rst
.. code-block:: php
   :caption: Example usage

   $vault->store('api-key', $secret);

.. code-block:: bash

   ddev exec bin/typo3 vault:init
```

### Cards (TYPO3 v12+)
```rst
.. card-grid::
   :columns: 2

   .. card:: :ref:`Quick Start <quickstart>`

      Get started in 5 minutes.

   .. card:: :ref:`CLI Reference <cli>`

      Command-line tools.
```

### Cross-References
```rst
See :ref:`installation` for setup instructions.

External: :t3coreapi:`DependencyInjection`
```

## File Naming

- `Index.rst` - Main file in each directory
- Use lowercase with hyphens for other files
- Each major section gets its own directory

## Adding New Documentation

1. Create new `.rst` file in appropriate directory
2. Add to `toctree` in parent `Index.rst`
3. Run `make docs` to verify rendering
4. Check for warnings in build output

## Common Issues

| Issue | Solution |
|-------|----------|
| Missing reference | Add `:ref:` label above heading |
| Broken indentation | RST requires exact spacing (3 spaces) |
| Image not found | Use relative path from current file |
| Build warnings | Check `Documentation-GENERATED-temp/` output |

## External References

Inventories defined in `guides.xml`:
- `t3coreapi` - TYPO3 Core API Reference
- `t3tca` - TCA Reference

Usage: `:t3coreapi:`SectionName``

---

*[n] Netresearch DTT GmbH*
