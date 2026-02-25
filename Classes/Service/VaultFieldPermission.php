<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrVault\Service;

/**
 * Vault field permission types.
 *
 * Used by VaultFieldPermissionService to control access to vault fields
 * in the TYPO3 backend based on TSconfig settings.
 */
enum VaultFieldPermission: string
{
    /** Permission to reveal/view the secret value. */
    case Reveal = 'reveal';

    /** Permission to copy the secret value to clipboard. */
    case Copy = 'copy';

    /** Permission to edit/modify the secret value. */
    case Edit = 'edit';

    /** Field is read-only (no editing allowed). */
    case ReadOnly = 'readOnly';
}
