<?php

declare(strict_types=1);

use Netresearch\NrVault\Task\OrphanCleanupTask;
use TYPO3\CMS\Core\Utility\ExtensionManagementUtility;

defined('TYPO3') or die();

if (\is_array($GLOBALS['TCA'] ?? null) && isset($GLOBALS['TCA']['tx_scheduler_task'])) {
    // Add custom fields for the OrphanCleanupTask
    ExtensionManagementUtility::addTCAcolumns(
        'tx_scheduler_task',
        [
            'nr_vault_retention_days' => [
                'label' => 'LLL:EXT:nr_vault/Resources/Private/Language/locallang.xlf:task.orphanCleanup.retentionDays',
                'description' => 'LLL:EXT:nr_vault/Resources/Private/Language/locallang.xlf:task.orphanCleanup.retentionDays.description',
                'config' => [
                    'type' => 'number',
                    'size' => 5,
                    'range' => [
                        'lower' => 0,
                        'upper' => 365,
                    ],
                    'default' => 7,
                ],
            ],
            'nr_vault_table_filter' => [
                'label' => 'LLL:EXT:nr_vault/Resources/Private/Language/locallang.xlf:task.orphanCleanup.tableFilter',
                'description' => 'LLL:EXT:nr_vault/Resources/Private/Language/locallang.xlf:task.orphanCleanup.tableFilter.description',
                'config' => [
                    'type' => 'input',
                    'size' => 30,
                    'max' => 255,
                    'placeholder' => 'e.g., tx_myext_settings',
                ],
            ],
        ],
    );

    // Register the OrphanCleanupTask as a native TCA task type
    ExtensionManagementUtility::addRecordType(
        [
            'label' => 'LLL:EXT:nr_vault/Resources/Private/Language/locallang.xlf:task.orphanCleanup.title',
            'description' => 'LLL:EXT:nr_vault/Resources/Private/Language/locallang.xlf:task.orphanCleanup.description',
            'value' => OrphanCleanupTask::class,
            'icon' => 'actions-database-clean',
            'group' => 'nr_vault',
        ],
        '
            --div--;LLL:EXT:core/Resources/Private/Language/locallang_core.xlf:labels.generalTab,
                tasktype,
                task_group,
                description,
                nr_vault_retention_days,
                nr_vault_table_filter,
            --div--;LLL:EXT:scheduler/Resources/Private/Language/locallang.xlf:scheduler.form.palettes.timing,
                execution_details,
                nextexecution,
                --palette--;;lastexecution,
            --div--;LLL:EXT:core/Resources/Private/Language/locallang_core.xlf:labels.accessTab,
                disable,
            --div--;LLL:EXT:core/Resources/Private/Language/locallang_core.xlf:labels.extended,
        ',
        [],
        '',
        'tx_scheduler_task',
    );
}
