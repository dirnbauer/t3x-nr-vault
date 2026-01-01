<?php

declare(strict_types=1);

namespace Netresearch\NrVault\Task;

use TYPO3\CMS\Core\Type\ContextualFeedbackSeverity;
use TYPO3\CMS\Scheduler\AbstractAdditionalFieldProvider;
use TYPO3\CMS\Scheduler\Controller\SchedulerModuleController;
use TYPO3\CMS\Scheduler\Task\AbstractTask;
use TYPO3\CMS\Scheduler\Task\Enumeration\Action;

/**
 * Additional field provider for the OrphanCleanupTask.
 *
 * Provides configuration fields for:
 * - retentionDays: Number of days to retain orphans before cleanup
 * - tableFilter: Optional filter to only check specific table
 */
final class OrphanCleanupTaskAdditionalFieldProvider extends AbstractAdditionalFieldProvider
{
    public function getAdditionalFields(
        array &$taskInfo,
        $task,
        SchedulerModuleController $schedulerModule,
    ): array {
        $currentAction = $schedulerModule->getCurrentAction();

        // Initialize values
        if ($currentAction === Action::ADD) {
            $taskInfo['retentionDays'] = 7;
            $taskInfo['tableFilter'] = '';
        } elseif ($task instanceof OrphanCleanupTask) {
            $taskInfo['retentionDays'] = $task->retentionDays;
            $taskInfo['tableFilter'] = $task->tableFilter;
        }

        $additionalFields = [];

        // Retention days field
        $fieldId = 'task_orphanCleanup_retentionDays';
        $fieldCode = \sprintf(
            '<input type="number" class="form-control" name="tx_scheduler[%s]" id="%s" value="%d" min="0" max="365">',
            $fieldId,
            $fieldId,
            (int) ($taskInfo['retentionDays'] ?? 7),
        );
        $additionalFields[$fieldId] = [
            'code' => $fieldCode,
            'label' => 'LLL:EXT:nr_vault/Resources/Private/Language/locallang.xlf:task.orphanCleanup.retentionDays',
            'cshKey' => '_MOD_system_txschedulerM1',
            'cshLabel' => $fieldId,
        ];

        // Table filter field
        $fieldId = 'task_orphanCleanup_tableFilter';
        $fieldCode = \sprintf(
            '<input type="text" class="form-control" name="tx_scheduler[%s]" id="%s" value="%s" placeholder="e.g., tx_myext_settings">',
            $fieldId,
            $fieldId,
            htmlspecialchars($taskInfo['tableFilter'] ?? ''),
        );
        $additionalFields[$fieldId] = [
            'code' => $fieldCode,
            'label' => 'LLL:EXT:nr_vault/Resources/Private/Language/locallang.xlf:task.orphanCleanup.tableFilter',
            'cshKey' => '_MOD_system_txschedulerM1',
            'cshLabel' => $fieldId,
        ];

        return $additionalFields;
    }

    public function validateAdditionalFields(
        array &$submittedData,
        SchedulerModuleController $schedulerModule,
    ): bool {
        $isValid = true;

        // Validate retention days
        $retentionDays = (int) ($submittedData['task_orphanCleanup_retentionDays'] ?? 0);
        if ($retentionDays < 0 || $retentionDays > 365) {
            $this->addMessage(
                'Retention days must be between 0 and 365',
                ContextualFeedbackSeverity::ERROR,
            );
            $isValid = false;
        }

        // Table filter is optional, no validation needed

        return $isValid;
    }

    public function saveAdditionalFields(
        array $submittedData,
        AbstractTask $task,
    ): void {
        if (!$task instanceof OrphanCleanupTask) {
            return;
        }

        $task->retentionDays = (int) ($submittedData['task_orphanCleanup_retentionDays'] ?? 7);
        $task->tableFilter = trim($submittedData['task_orphanCleanup_tableFilter'] ?? '');
    }
}
