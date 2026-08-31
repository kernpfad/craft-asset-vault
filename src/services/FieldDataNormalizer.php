<?php

declare(strict_types=1);

namespace kernpfad\assetvault\services;

use Craft;
use craft\base\FieldInterface;
use craft\fields\BaseRelationField;
use craft\fields\ContentBlock;
use craft\fields\Matrix;
use craft\models\FieldLayout;
use yii\db\Query;

/**
 * Prepares serialized custom-field payloads so they can be applied to a
 * brand-new owner element on restore.
 *
 * Craft's getSerializedFieldValues() is a faithful snapshot, but Matrix
 * (and nested entry) maps are keyed by the original nested-entry IDs.
 * Replaying those IDs onto a new owner is unreliable; remapping to new1/new2/…
 * forces Craft to create fresh nested entries with the same content.
 *
 * Relation fields store element IDs — missing/deleted targets are dropped so
 * restore doesn't fail validation on dangling references.
 */
class FieldDataNormalizer
{
    /**
     * @param array<string|int, mixed> $fieldData Handle => serialized value
     * @return array<string, mixed>
     */
    public function prepareForNewOwner(array $fieldData): array
    {
        $prepared = [];

        foreach ($fieldData as $handle => $value) {
            if (!is_string($handle) || $handle === '') {
                continue;
            }

            $prepared[$handle] = $this->prepareValue($value, $this->fieldByHandle($handle));
        }

        return $prepared;
    }

    /**
     * Remap a Matrix-style entry map (or sortOrder/entries payload) so every
     * nested entry is created fresh on the new owner. Pure — no Craft boot.
     *
     * @param mixed $value
     * @return array<string|int, mixed>
     */
    public function remapNestedEntries(mixed $value): array
    {
        if (!is_array($value) || $value === []) {
            return [];
        }

        if ($this->isDeltaFormat($value)) {
            $entries = $value['entries'] ?? $value['blocks'] ?? [];
            if (!is_array($entries)) {
                return [];
            }

            $remapped = $this->remapEntryMap($entries);

            return [
                'sortOrder' => array_keys($remapped),
                'entries' => $remapped,
            ];
        }

        if ($this->looksLikeEntryMap($value)) {
            return $this->remapEntryMap($value);
        }

        return $value;
    }

    /**
     * @param array<string|int, mixed> $value
     */
    public function looksLikeEntryMap(array $value): bool
    {
        if ($value === [] || array_is_list($value)) {
            return false;
        }

        foreach ($value as $entry) {
            if (!is_array($entry) || !isset($entry['type']) || !array_key_exists('fields', $entry)) {
                return false;
            }
        }

        return true;
    }

    private function prepareValue(mixed $value, ?FieldInterface $field): mixed
    {
        if ($field instanceof Matrix) {
            return $this->prepareMatrixValue($value, $field);
        }

        if ($field instanceof ContentBlock) {
            return $this->prepareContentBlockValue($value, $field);
        }

        if ($field instanceof BaseRelationField) {
            return $this->prepareRelationValue($value);
        }

        if (is_array($value) && ($this->isDeltaFormat($value) || $this->looksLikeEntryMap($value))) {
            return $this->remapNestedEntries($value);
        }

        return $value;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function prepareMatrixValue(mixed $value, Matrix $field): array
    {
        $entryTypesByHandle = [];
        foreach ($field->getEntryTypes() as $entryType) {
            $entryTypesByHandle[$entryType->handle] = $entryType;
        }

        if (!is_array($value) || $value === []) {
            return [];
        }

        $entries = $this->isDeltaFormat($value)
            ? (is_array($value['entries'] ?? null) ? $value['entries'] : (is_array($value['blocks'] ?? null) ? $value['blocks'] : []))
            : $value;

        if (!$this->looksLikeEntryMap($entries) && !$this->isDeltaFormat($value)) {
            // Unexpected shape — don't invent data.
            return [];
        }

        if (!$this->looksLikeEntryMap($entries)) {
            return [];
        }

        $remapped = [];
        $n = 0;

        foreach ($entries as $entry) {
            if (!is_array($entry) || !isset($entry['type'])) {
                continue;
            }

            $typeHandle = is_string($entry['type']) ? $entry['type'] : null;
            $entryType = $typeHandle !== null ? ($entryTypesByHandle[$typeHandle] ?? null) : null;
            $fields = is_array($entry['fields'] ?? null) ? $entry['fields'] : [];

            if ($entryType !== null) {
                $fields = $this->prepareFieldsForLayout($fields, $entryType->getFieldLayout());
            } else {
                $fields = $this->prepareFieldsHeuristic($fields);
            }

            $entry['fields'] = $fields;
            $remapped['new' . (++$n)] = $entry;
        }

        return $remapped;
    }

    private function prepareContentBlockValue(mixed $value, ContentBlock $field): mixed
    {
        if (!is_array($value) || !isset($value['fields']) || !is_array($value['fields'])) {
            return $value;
        }

        return [
            'fields' => $this->prepareFieldsForLayout($value['fields'], $field->getFieldLayout()),
        ];
    }

    /**
     * @param array<string|int, mixed> $fields
     * @return array<string, mixed>
     */
    private function prepareFieldsForLayout(array $fields, ?FieldLayout $layout): array
    {
        if ($layout === null) {
            return $this->prepareFieldsHeuristic($fields);
        }

        $prepared = [];

        foreach ($fields as $handle => $value) {
            if (!is_string($handle)) {
                continue;
            }

            $field = $layout->getFieldByHandle($handle) ?? $this->fieldByHandle($handle);
            $prepared[$handle] = $this->prepareValue($value, $field);
        }

        return $prepared;
    }

    /**
     * @param array<string|int, mixed> $fields
     * @return array<string, mixed>
     */
    private function prepareFieldsHeuristic(array $fields): array
    {
        $prepared = [];

        foreach ($fields as $handle => $value) {
            if (!is_string($handle)) {
                continue;
            }

            if (is_array($value) && ($this->isDeltaFormat($value) || $this->looksLikeEntryMap($value))) {
                $prepared[$handle] = $this->remapNestedEntries($value);
            } elseif ($this->looksLikeRelationIdList($value)) {
                $prepared[$handle] = $this->prepareRelationValue($value);
            } else {
                $prepared[$handle] = $value;
            }
        }

        return $prepared;
    }

    /**
     * @param array<string|int, mixed> $entries
     * @return array<string, array<string, mixed>>
     */
    private function remapEntryMap(array $entries): array
    {
        $remapped = [];
        $n = 0;

        foreach ($entries as $entry) {
            if (!is_array($entry) || !isset($entry['type'])) {
                continue;
            }

            $fields = is_array($entry['fields'] ?? null) ? $entry['fields'] : [];
            $entry['fields'] = $this->prepareFieldsHeuristic($fields);
            $remapped['new' . (++$n)] = $entry;
        }

        return $remapped;
    }

    /**
     * @return list<int>
     */
    private function prepareRelationValue(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        $ids = [];
        foreach ($value as $id) {
            if (is_int($id) || (is_string($id) && ctype_digit($id))) {
                $ids[] = (int)$id;
            }
        }

        if ($ids === []) {
            return [];
        }

        try {
            /** @var list<int|string> $existing */
            $existing = (new Query())
                ->select(['id'])
                ->from(['{{%elements}}'])
                ->where([
                    'id' => $ids,
                    'dateDeleted' => null,
                ])
                ->column();
        } catch (\Throwable) {
            // No DB (unit tests / early boot) — keep the IDs as stored.
            return $ids;
        }

        $existingSet = array_fill_keys(array_map('intval', $existing), true);

        return array_values(array_filter($ids, static fn(int $id) => isset($existingSet[$id])));
    }

    /**
     * @param array<string|int, mixed> $value
     */
    private function isDeltaFormat(array $value): bool
    {
        return isset($value['entries']) || isset($value['blocks']) || isset($value['sortOrder']);
    }

    private function looksLikeRelationIdList(mixed $value): bool
    {
        if (!is_array($value) || $value === [] || !array_is_list($value)) {
            return false;
        }

        foreach ($value as $id) {
            if (!(is_int($id) || (is_string($id) && ctype_digit($id)))) {
                return false;
            }
        }

        return true;
    }

    private function fieldByHandle(string $handle): ?FieldInterface
    {
        try {
            $field = Craft::$app->getFields()->getFieldByHandle($handle);
        } catch (\Throwable) {
            return null;
        }

        return $field instanceof FieldInterface ? $field : null;
    }
}
