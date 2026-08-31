<?php

namespace kernpfad\assetvault\tests\unit;

use kernpfad\assetvault\services\PathResolver;
use PHPUnit\Framework\TestCase;

/**
 * PathResolver is the one part of this plugin with no Craft dependency, so
 * it's tested directly — no Craft boot required.
 *
 * The conflict-resolution logic is the part worth pinning down: it runs on
 * every restore, and getting it wrong means either overwriting a file the
 * user still has or looping forever.
 */
class PathResolverTest extends TestCase
{
    private PathResolver $paths;

    protected function setUp(): void
    {
        $this->paths = new PathResolver();
    }

    public function testVaultPathIsKeyedByVolumeAndUidAndKeepsTheExtension(): void
    {
        self::assertSame(
            '.vault/7/abc-123.jpg',
            $this->paths->vaultPath(7, 'abc-123', 'holiday.jpg')
        );
    }

    public function testVaultPathHandlesAFilenameWithNoExtension(): void
    {
        self::assertSame('.vault/7/abc-123', $this->paths->vaultPath(7, 'abc-123', 'README'));
    }

    public function testVaultPathUsesTheUidNotTheFilenameSoTwoFilesOfTheSameNameCannotCollide(): void
    {
        // The whole point of keying on the asset UID: deleting two
        // different files that happen to share a name must produce two
        // distinct vault entries, or the second would overwrite the first
        // and silently destroy it.
        $first = $this->paths->vaultPath(1, 'uid-one', 'invoice.pdf');
        $second = $this->paths->vaultPath(1, 'uid-two', 'invoice.pdf');

        self::assertNotSame($first, $second);
    }

    public function testVaultPathKeepsAMultiDotFilenamesFinalExtension(): void
    {
        self::assertSame('.vault/3/uid.gz', $this->paths->vaultPath(3, 'uid', 'archive.tar.gz'));
    }

    public function testAFreePathIsReturnedUnchanged(): void
    {
        $path = $this->paths->resolveConflict('documents', 'report.pdf', fn() => false);

        self::assertSame('documents/report.pdf', $path);
    }

    public function testAFileInTheVolumeRootGetsNoLeadingSlash(): void
    {
        self::assertSame('report.pdf', $this->paths->resolveConflict('', 'report.pdf', fn() => false));
    }

    public function testSurroundingSlashesOnTheFolderPathAreNormalisedAway(): void
    {
        self::assertSame(
            'documents/report.pdf',
            $this->paths->resolveConflict('/documents/', 'report.pdf', fn() => false)
        );
    }

    public function testASingleCollisionGetsTheRestoredSuffix(): void
    {
        $taken = ['documents/report.pdf'];

        self::assertSame(
            'documents/report_restored.pdf',
            $this->paths->resolveConflict('documents', 'report.pdf', fn($p) => in_array($p, $taken, true))
        );
    }

    public function testRepeatedCollisionsCountUpRatherThanLoopingForever(): void
    {
        $taken = [
            'documents/report.pdf',
            'documents/report_restored.pdf',
            'documents/report_restored-3.pdf',
        ];

        self::assertSame(
            'documents/report_restored-4.pdf',
            $this->paths->resolveConflict('documents', 'report.pdf', fn($p) => in_array($p, $taken, true))
        );
    }

    public function testConflictResolutionPreservesTheExtension(): void
    {
        // A restored file that lost its extension would stop being served
        // with the right content type, so this is worth asserting
        // separately from the naming.
        $path = $this->paths->resolveConflict('img', 'photo.jpeg', fn($p) => $p === 'img/photo.jpeg');

        self::assertStringEndsWith('.jpeg', $path);
    }

    public function testConflictResolutionOnAnExtensionlessFilename(): void
    {
        $path = $this->paths->resolveConflict('', 'LICENSE', fn($p) => $p === 'LICENSE');

        self::assertSame('LICENSE_restored', $path);
    }

    public function testTheExistenceCheckIsCalledWithTheFullCandidatePathNotJustTheFilename(): void
    {
        // Regression guard: if only the bare filename were passed to the
        // callback, VaultService's `fileExists()` check would be asking
        // about the volume root rather than the target folder, and a
        // restore into a subfolder could silently overwrite a real file.
        $seen = [];

        $this->paths->resolveConflict('a/b', 'f.txt', function(string $p) use (&$seen) {
            $seen[] = $p;

            return false;
        });

        self::assertSame(['a/b/f.txt'], $seen);
    }

    public function testBuildPathJoinsAFolderAndFilename(): void
    {
        self::assertSame('documents/report.pdf', $this->paths->buildPath('documents', 'report.pdf'));
    }

    public function testBuildPathOnTheVolumeRootHasNoLeadingSlash(): void
    {
        self::assertSame('report.pdf', $this->paths->buildPath('', 'report.pdf'));
    }

    public function testBuildPathNormalisesSurroundingSlashesOnTheFolderPath(): void
    {
        self::assertSame('documents/report.pdf', $this->paths->buildPath('/documents/', 'report.pdf'));
    }

    public function testBuildPathAgreesWithResolveConflictOnAFreePath(): void
    {
        // previewRestore() compares buildPath()'s output against
        // resolveConflict()'s to decide whether a restore would collide —
        // that only works if the two agree on the unconflicted case.
        self::assertSame(
            $this->paths->buildPath('img', 'photo.jpg'),
            $this->paths->resolveConflict('img', 'photo.jpg', fn() => false)
        );
    }
}
