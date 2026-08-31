<?php

namespace kernpfad\assetvault\tests\unit;

use kernpfad\assetvault\services\FieldDataNormalizer;
use PHPUnit\Framework\TestCase;

/**
 * FieldDataNormalizer's Matrix remapping is pure PHP and the part that
 * must not silently replay old nested-entry IDs onto a new owner.
 */
class FieldDataNormalizerTest extends TestCase
{
    private FieldDataNormalizer $normalizer;

    protected function setUp(): void
    {
        $this->normalizer = new FieldDataNormalizer();
    }

    public function testRemapNestedEntriesRewritesNumericIdsToNewKeys(): void
    {
        $remapped = $this->normalizer->remapNestedEntries([
            42 => [
                'type' => 'textBlock',
                'fields' => ['body' => 'hello'],
            ],
            99 => [
                'type' => 'textBlock',
                'fields' => ['body' => 'world'],
            ],
        ]);

        self::assertSame(['new1', 'new2'], array_keys($remapped));
        self::assertSame('textBlock', $remapped['new1']['type']);
        self::assertSame('hello', $remapped['new1']['fields']['body']);
        self::assertSame('world', $remapped['new2']['fields']['body']);
    }

    public function testRemapNestedEntriesHandlesDeltaFormat(): void
    {
        $remapped = $this->normalizer->remapNestedEntries([
            'sortOrder' => [10, 11],
            'entries' => [
                10 => [
                    'type' => 'copy',
                    'fields' => ['text' => 'a'],
                ],
                11 => [
                    'type' => 'copy',
                    'fields' => ['text' => 'b'],
                ],
            ],
        ]);

        self::assertSame(['new1', 'new2'], $remapped['sortOrder']);
        self::assertSame(['new1', 'new2'], array_keys($remapped['entries']));
        self::assertSame('a', $remapped['entries']['new1']['fields']['text']);
    }

    public function testRemapNestedEntriesRecursesIntoNestedMatrixFields(): void
    {
        $remapped = $this->normalizer->remapNestedEntries([
            1 => [
                'type' => 'outer',
                'fields' => [
                    'innerMatrix' => [
                        7 => [
                            'type' => 'inner',
                            'fields' => ['label' => 'nested'],
                        ],
                    ],
                ],
            ],
        ]);

        self::assertSame(['new1'], array_keys($remapped));
        self::assertSame(['new1'], array_keys($remapped['new1']['fields']['innerMatrix']));
        self::assertSame('nested', $remapped['new1']['fields']['innerMatrix']['new1']['fields']['label']);
    }

    public function testLooksLikeEntryMapRejectsRelationIdLists(): void
    {
        self::assertFalse($this->normalizer->looksLikeEntryMap([1, 2, 3]));
        self::assertFalse($this->normalizer->looksLikeEntryMap(['title' => 'nope']));
        self::assertTrue($this->normalizer->looksLikeEntryMap([
            5 => ['type' => 'x', 'fields' => []],
        ]));
    }

    public function testPrepareForNewOwnerRemapsMatrixShapedHandlesWithoutCraftFields(): void
    {
        // Without a booted Craft app, fieldByHandle returns null and the
        // heuristic path still remaps entry maps so restores stay safe.
        $prepared = $this->normalizer->prepareForNewOwner([
            'plain' => 'keep me',
            'matrix' => [
                3 => [
                    'type' => 'block',
                    'fields' => ['x' => 1],
                ],
            ],
        ]);

        self::assertSame('keep me', $prepared['plain']);
        self::assertSame(['new1'], array_keys($prepared['matrix']));
        self::assertSame(1, $prepared['matrix']['new1']['fields']['x']);
    }

    public function testRemapNestedEntriesReturnsEmptyArrayForGarbage(): void
    {
        self::assertSame([], $this->normalizer->remapNestedEntries(null));
        self::assertSame([], $this->normalizer->remapNestedEntries('nope'));
        self::assertSame([], $this->normalizer->remapNestedEntries([]));
    }
}
