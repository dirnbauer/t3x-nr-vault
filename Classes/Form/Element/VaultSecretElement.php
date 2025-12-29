<?php

declare(strict_types=1);

namespace Netresearch\NrVault\Form\Element;

use Netresearch\NrVault\Service\VaultServiceInterface;
use Throwable;
use TYPO3\CMS\Backend\Form\Element\AbstractFormElement;
use TYPO3\CMS\Core\Page\JavaScriptModuleInstruction;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Core\Utility\StringUtility;

/**
 * TCA form element for vault secrets.
 *
 * Renders a password field that stores values in the vault
 * instead of directly in the database.
 */
final class VaultSecretElement extends AbstractFormElement
{
    private const DEFAULT_FIELD_WIZARDS = [
        'localizationStateSelector' => [
            'renderType' => 'localizationStateSelector',
        ],
        'otherLanguageContent' => [
            'renderType' => 'otherLanguageContent',
            'after' => ['localizationStateSelector'],
        ],
        'defaultLanguageDifferences' => [
            'renderType' => 'defaultLanguageDifferences',
            'after' => ['otherLanguageContent'],
        ],
    ];

    public function render(): array
    {
        $resultArray = $this->initializeResultArray();

        $parameterArray = $this->data['parameterArray'];
        $config = $parameterArray['fieldConf']['config'];

        $itemName = $parameterArray['itemFormElName'];
        $itemValue = $parameterArray['itemFormElValue'];

        $fieldId = StringUtility::getUniqueId('formengine-vault-');
        $width = $this->formMaxWidth($config['size'] ?? 30);

        // Build vault identifier from table, field, and record uid
        $table = $this->data['tableName'];
        $field = $this->data['fieldName'];
        $uid = $this->data['databaseRow']['uid'] ?? 0;
        $vaultIdentifier = $this->buildVaultIdentifier($table, $field, $uid);

        // Check if secret exists in vault
        $hasValue = false;
        $valueChecksum = '';
        if ($uid > 0 && $vaultIdentifier !== '') {
            try {
                $vaultService = GeneralUtility::makeInstance(VaultServiceInterface::class);
                $metadata = $vaultService->getMetadata($vaultIdentifier);
                if ($metadata !== null) {
                    $hasValue = true;
                    $valueChecksum = $metadata['value_checksum'] ?? '';
                }
            } catch (Throwable) {
                // Secret doesn't exist yet
            }
        }

        // Determine placeholder text
        $placeholder = '';
        if ($hasValue) {
            $placeholder = $this->getLanguageService()->sL(
                'LLL:EXT:nr_vault/Resources/Private/Language/locallang.xlf:vault_secret.placeholder_exists',
            ) ?: '••••••••';
        } else {
            $placeholder = $config['placeholder'] ?? '';
        }

        // Build attributes
        $attributes = [
            'type' => 'password',
            'id' => $fieldId,
            'name' => $itemName,
            'value' => '',
            'class' => implode(' ', [
                'form-control',
                't3js-clearable',
                'hasDefaultValue',
            ]),
            'data-formengine-validation-rules' => $this->getValidationDataAsJsonString($config),
            'data-formengine-input-name' => $itemName,
            'data-vault-identifier' => $vaultIdentifier,
            'data-vault-has-value' => $hasValue ? '1' : '0',
            'data-vault-checksum' => $valueChecksum,
            'autocomplete' => 'new-password',
        ];

        if ($placeholder !== '') {
            $attributes['placeholder'] = $placeholder;
        }

        if ($config['max'] ?? 0) {
            $attributes['maxlength'] = (string) (int) $config['max'];
        }

        if ($config['readOnly'] ?? false) {
            $attributes['readonly'] = 'readonly';
        }

        if ($config['required'] ?? false) {
            $attributes['required'] = 'required';
        }

        // Build HTML
        $html = [];
        $html[] = '<div class="formengine-field-item t3js-formengine-field-item">';
        $html[] = '<div class="form-wizards-wrap">';
        $html[] = '<div class="form-wizards-element">';
        $html[] = '<div class="form-control-wrap" style="max-width: ' . $width . 'px">';
        $html[] = '<div class="input-group">';
        $html[] = '<input ' . GeneralUtility::implodeAttributes($attributes, true) . ' />';

        // Toggle visibility button
        $html[] = '<button type="button" class="btn btn-secondary t3js-vault-toggle-visibility" title="Toggle visibility">';
        $html[] = '<span class="t3js-icon icon icon-size-small icon-state-default icon-actions-eye"></span>';
        $html[] = '</button>';

        // Clear button if value exists
        if ($hasValue) {
            $html[] = '<button type="button" class="btn btn-secondary t3js-vault-clear" title="Clear secret">';
            $html[] = '<span class="t3js-icon icon icon-size-small icon-state-default icon-actions-delete"></span>';
            $html[] = '</button>';
        }

        $html[] = '</div>'; // input-group
        $html[] = '</div>'; // form-control-wrap
        $html[] = '</div>'; // form-wizards-element

        // Render field wizards
        $fieldWizardResult = $this->renderFieldWizard();
        $html[] = $fieldWizardResult['html'];
        $resultArray = $this->mergeChildReturnIntoExistingResult($resultArray, $fieldWizardResult, false);

        $html[] = '</div>'; // form-wizards-wrap
        $html[] = '</div>'; // formengine-field-item

        // Hidden field to track vault identifier
        $html[] = '<input type="hidden" name="' . htmlspecialchars($itemName) . '[_vault_identifier]" value="' . htmlspecialchars($vaultIdentifier) . '" />';

        // Hidden field for original checksum (for change detection)
        $html[] = '<input type="hidden" name="' . htmlspecialchars($itemName) . '[_vault_checksum]" value="' . htmlspecialchars($valueChecksum) . '" />';

        $resultArray['html'] = implode(LF, $html);

        // Add JavaScript module
        $resultArray['javaScriptModules'][] = JavaScriptModuleInstruction::create(
            '@netresearch/nr-vault/vault-secret-element.js',
        );

        return $resultArray;
    }

    /**
     * Build vault identifier from table, field, and uid.
     */
    private function buildVaultIdentifier(string $table, string $field, int|string $uid): string
    {
        if ($uid === 0 || $uid === 'NEW' || str_starts_with((string) $uid, 'NEW')) {
            // New record - identifier will be generated after save
            return '';
        }

        // Format: table__field__uid
        return \sprintf('%s__%s__%d', $table, $field, (int) $uid);
    }
}
