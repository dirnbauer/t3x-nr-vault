<?php

declare(strict_types=1);

namespace Netresearch\NrVault\Tests\Unit\Task;

use Netresearch\NrVault\Task\OrphanCleanupTask;
use Netresearch\NrVault\Task\OrphanCleanupTaskAdditionalFieldProvider;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Scheduler\Controller\SchedulerModuleController;
use TYPO3\CMS\Scheduler\Task\Enumeration\Action;

#[CoversClass(OrphanCleanupTaskAdditionalFieldProvider::class)]
final class OrphanCleanupTaskAdditionalFieldProviderTest extends TestCase
{
    private OrphanCleanupTaskAdditionalFieldProvider $provider;

    protected function setUp(): void
    {
        parent::setUp();
        $this->provider = new OrphanCleanupTaskAdditionalFieldProvider();
    }

    #[Test]
    public function getAdditionalFieldsReturnsExpectedFields(): void
    {
        $taskInfo = [];
        $schedulerModule = $this->createMock(SchedulerModuleController::class);
        $schedulerModule->method('getCurrentAction')->willReturn(Action::ADD);

        $fields = $this->provider->getAdditionalFields($taskInfo, null, $schedulerModule);

        self::assertArrayHasKey('task_orphanCleanup_retentionDays', $fields);
        self::assertArrayHasKey('task_orphanCleanup_tableFilter', $fields);
    }

    #[Test]
    public function getAdditionalFieldsSetsDefaultValuesForNewTask(): void
    {
        $taskInfo = [];
        $schedulerModule = $this->createMock(SchedulerModuleController::class);
        $schedulerModule->method('getCurrentAction')->willReturn(Action::ADD);

        $this->provider->getAdditionalFields($taskInfo, null, $schedulerModule);

        self::assertSame(7, $taskInfo['retentionDays']);
        self::assertSame('', $taskInfo['tableFilter']);
    }

    #[Test]
    public function getAdditionalFieldsUsesTaskValuesForExistingTask(): void
    {
        $taskInfo = [];
        $task = new OrphanCleanupTask();
        $task->retentionDays = 30;
        $task->tableFilter = 'tx_myext_settings';

        $schedulerModule = $this->createMock(SchedulerModuleController::class);
        $schedulerModule->method('getCurrentAction')->willReturn(Action::EDIT);

        $this->provider->getAdditionalFields($taskInfo, $task, $schedulerModule);

        self::assertSame(30, $taskInfo['retentionDays']);
        self::assertSame('tx_myext_settings', $taskInfo['tableFilter']);
    }

    #[Test]
    public function getAdditionalFieldsReturnsRetentionDaysWithHtmlInput(): void
    {
        $taskInfo = [];
        $schedulerModule = $this->createMock(SchedulerModuleController::class);
        $schedulerModule->method('getCurrentAction')->willReturn(Action::ADD);

        $fields = $this->provider->getAdditionalFields($taskInfo, null, $schedulerModule);

        $retentionField = $fields['task_orphanCleanup_retentionDays'];
        self::assertStringContainsString('type="number"', $retentionField['code']);
        self::assertStringContainsString('min="0"', $retentionField['code']);
        self::assertStringContainsString('max="365"', $retentionField['code']);
        self::assertStringContainsString('value="7"', $retentionField['code']);
    }

    #[Test]
    public function getAdditionalFieldsReturnsTableFilterWithHtmlInput(): void
    {
        $taskInfo = [];
        $schedulerModule = $this->createMock(SchedulerModuleController::class);
        $schedulerModule->method('getCurrentAction')->willReturn(Action::ADD);

        $fields = $this->provider->getAdditionalFields($taskInfo, null, $schedulerModule);

        $tableField = $fields['task_orphanCleanup_tableFilter'];
        self::assertStringContainsString('type="text"', $tableField['code']);
        self::assertStringContainsString('placeholder=', $tableField['code']);
    }

    #[Test]
    public function validateAdditionalFieldsReturnsTrueForValidData(): void
    {
        $submittedData = [
            'task_orphanCleanup_retentionDays' => 7,
            'task_orphanCleanup_tableFilter' => 'tx_myext_settings',
        ];
        $schedulerModule = $this->createMock(SchedulerModuleController::class);

        $result = $this->provider->validateAdditionalFields($submittedData, $schedulerModule);

        self::assertTrue($result);
    }

    #[Test]
    public function validateAdditionalFieldsReturnsFalseForNegativeRetention(): void
    {
        $submittedData = [
            'task_orphanCleanup_retentionDays' => -1,
        ];
        $schedulerModule = $this->createMock(SchedulerModuleController::class);

        $result = $this->provider->validateAdditionalFields($submittedData, $schedulerModule);

        self::assertFalse($result);
    }

    #[Test]
    public function validateAdditionalFieldsReturnsFalseForRetentionOver365(): void
    {
        $submittedData = [
            'task_orphanCleanup_retentionDays' => 400,
        ];
        $schedulerModule = $this->createMock(SchedulerModuleController::class);

        $result = $this->provider->validateAdditionalFields($submittedData, $schedulerModule);

        self::assertFalse($result);
    }

    #[Test]
    public function validateAdditionalFieldsAcceptsZeroRetention(): void
    {
        $submittedData = [
            'task_orphanCleanup_retentionDays' => 0,
        ];
        $schedulerModule = $this->createMock(SchedulerModuleController::class);

        $result = $this->provider->validateAdditionalFields($submittedData, $schedulerModule);

        self::assertTrue($result);
    }

    #[Test]
    public function validateAdditionalFieldsAcceptsMaxRetention(): void
    {
        $submittedData = [
            'task_orphanCleanup_retentionDays' => 365,
        ];
        $schedulerModule = $this->createMock(SchedulerModuleController::class);

        $result = $this->provider->validateAdditionalFields($submittedData, $schedulerModule);

        self::assertTrue($result);
    }

    #[Test]
    public function saveAdditionalFieldsSavesValuesToTask(): void
    {
        $submittedData = [
            'task_orphanCleanup_retentionDays' => 14,
            'task_orphanCleanup_tableFilter' => 'tx_myext_settings',
        ];
        $task = new OrphanCleanupTask();

        $this->provider->saveAdditionalFields($submittedData, $task);

        self::assertSame(14, $task->retentionDays);
        self::assertSame('tx_myext_settings', $task->tableFilter);
    }

    #[Test]
    public function saveAdditionalFieldsTrimsTableFilter(): void
    {
        $submittedData = [
            'task_orphanCleanup_retentionDays' => 7,
            'task_orphanCleanup_tableFilter' => '  tx_myext_settings  ',
        ];
        $task = new OrphanCleanupTask();

        $this->provider->saveAdditionalFields($submittedData, $task);

        self::assertSame('tx_myext_settings', $task->tableFilter);
    }

    #[Test]
    public function saveAdditionalFieldsUsesDefaultsForMissingData(): void
    {
        $submittedData = [];
        $task = new OrphanCleanupTask();

        $this->provider->saveAdditionalFields($submittedData, $task);

        self::assertSame(7, $task->retentionDays);
        self::assertSame('', $task->tableFilter);
    }

    #[Test]
    public function saveAdditionalFieldsIgnoresNonOrphanCleanupTask(): void
    {
        $submittedData = [
            'task_orphanCleanup_retentionDays' => 14,
        ];
        $task = $this->createMock(\TYPO3\CMS\Scheduler\Task\AbstractTask::class);

        // Should not throw, just return without action
        $this->provider->saveAdditionalFields($submittedData, $task);

        // If we reach here without exception, the test passes
        self::assertTrue(true);
    }
}
