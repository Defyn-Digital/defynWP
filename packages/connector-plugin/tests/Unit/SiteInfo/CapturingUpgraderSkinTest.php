<?php
declare(strict_types=1);

namespace Defyn\Connector\Tests\Unit\SiteInfo;

use Defyn\Connector\SiteInfo\CapturingUpgraderSkin;
use PHPUnit\Framework\TestCase;

final class CapturingUpgraderSkinTest extends TestCase
{
    public function testFeedbackAccumulates(): void
    {
        $skin = new CapturingUpgraderSkin();
        $skin->feedback('Downloading update from %s.', 'https://example.test/plugin.zip');
        $skin->feedback('Unpacking the update.');

        $this->assertSame([
            'Downloading update from https://example.test/plugin.zip.',
            'Unpacking the update.',
        ], $skin->messages());
    }

    public function testErrorWithStringAccumulates(): void
    {
        $skin = new CapturingUpgraderSkin();
        $skin->error('Could not copy file.');

        $this->assertSame(['Could not copy file.'], $skin->errors());
        $this->assertSame('Could not copy file.', $skin->lastErrorMessage());
    }

    public function testErrorWithWpErrorAccumulatesEveryMessage(): void
    {
        $skin = new CapturingUpgraderSkin();
        $wpError = new \WP_Error('download_failed', 'Download failed.');
        $wpError->add('extract_failed', 'Extraction failed.');
        $skin->error($wpError);

        $this->assertSame(['Download failed.', 'Extraction failed.'], $skin->errors());
        $this->assertSame('Extraction failed.', $skin->lastErrorMessage());
    }

    public function testLastErrorMessageIsNullBeforeAnyError(): void
    {
        $skin = new CapturingUpgraderSkin();
        $this->assertNull($skin->lastErrorMessage());
    }
}
