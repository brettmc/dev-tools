<?php

declare(strict_types=1);

namespace OpenTelemetry\DevTools\Tests\Unit\Package\Php;

use InvalidArgumentException;
use OpenTelemetry\DevTools\Package\Php\IssueTemplate;
use OpenTelemetry\DevTools\Tests\Unit\Behavior\UsesVfsConstants;
use OpenTelemetry\DevTools\Tests\Unit\Behavior\UsesVfsTrait;
use org\bovigo\vfs\vfsStream;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OpenTelemetry\DevTools\Package\Php\IssueTemplate
 */
class IssueTemplateTest extends TestCase
{
    use UsesVfsTrait;

    #[\Override]
    public function setUp(): void
    {
        $this->setUpVcs();
    }

    public function test_it_reads_the_front_matter_and_the_body(): void
    {
        $template = IssueTemplate::parse("---\ntitle: \"Drop PHP {{ version }}\"\nlabels: [a, b]\n---\n\nBody line\n\n- [ ] item\n\n");

        $this->assertSame('Drop PHP {{ version }}', $template->title);
        $this->assertSame(['a', 'b'], $template->labels);
        $this->assertSame("Body line\n\n- [ ] item\n", $template->body);
    }

    public function test_the_labels_are_optional(): void
    {
        $template = IssueTemplate::parse("---\ntitle: Hello\n---\nBody");

        $this->assertSame([], $template->labels);
    }

    public function test_it_accepts_a_template_without_a_body(): void
    {
        $this->assertSame("\n", IssueTemplate::parse("---\ntitle: Hello\n---")->body);
    }

    public function test_it_replaces_the_placeholders_in_the_title_and_the_body(): void
    {
        $template = IssueTemplate::parse("---\ntitle: Drop {{version}}\n---\nPHP {{ version }} ends on {{  until  }}.");

        $issue = $template->render(['version' => '8.2', 'until' => '2027-12-31']);

        $this->assertSame('Drop 8.2', $issue->title);
        $this->assertSame("PHP 8.2 ends on 2027-12-31.\n", $issue->body);
        $this->assertSame('Drop {{version}}', $template->title, 'the template is not changed');
    }

    public function test_the_title_is_trimmed_as_github_keeps_it(): void
    {
        $template = IssueTemplate::parse("---\ntitle: \" Drop PHP {{ version }} \"\n---\nBody");

        $this->assertSame('Drop PHP {{ version }}', $template->title);
        $this->assertSame('Drop PHP 8.2', $template->render(['version' => '8.2'])->title);
        $this->assertSame('8.2', IssueTemplate::parse("---\ntitle: '{{ version }} '\n---\nBody")->render(['version' => ' 8.2 '])->title);
    }

    public function test_a_placeholder_without_a_value_is_an_error(): void
    {
        $template = IssueTemplate::parse("---\ntitle: Drop\n---\nPHP {{ versoin }}");

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('unknown placeholder {{ versoin }}, expected one of: version, until');

        $template->render(['version' => '8.2', 'until' => '2027-12-31']);
    }

    /**
     * @dataProvider invalidTemplates
     */
    public function test_it_rejects_a_malformed_template(string $contents, string $message): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($message);

        IssueTemplate::parse($contents);
    }

    public static function invalidTemplates(): iterable
    {
        yield 'no front matter' => ["Just text\n", 'expected front matter'];
        yield 'unclosed front matter' => ["---\ntitle: A\nBody\n", 'expected front matter'];
        yield 'no title' => ["---\nlabels: [a]\n---\nBody", 'needs a one-line "title"'];
        yield 'empty title' => ["---\ntitle: ''\n---\nBody", 'needs a one-line "title"'];
        yield 'title over two lines' => ["---\ntitle: |\n  A\n  B\n---\nBody", 'needs a one-line "title"'];
        yield 'labels not a list' => ["---\ntitle: A\nlabels: bug\n---\nBody", '"labels" must be a list of names'];
        yield 'an empty label' => ["---\ntitle: A\nlabels: ['']\n---\nBody", '"labels" must be a list of names'];
        yield 'a label with a comma' => ["---\ntitle: A\nlabels: ['php, version']\n---\nBody", 'a label cannot contain a comma'];
        yield 'label not a name' => ["---\ntitle: A\nlabels: [[x]]\n---\nBody", '"labels" must be a list of names'];
        yield 'front matter not yaml' => ["---\ntitle: [unclosed\n---\nBody", 'invalid front matter'];
    }

    public function test_it_reads_a_file(): void
    {
        $file = vfsStream::url(UsesVfsConstants::ROOT_DIR . '/drop.md');
        file_put_contents($file, "---\ntitle: Drop\nlabels: [x]\n---\nBody");

        $template = IssueTemplate::fromFile($file);

        $this->assertSame('Drop', $template->title);
        $this->assertSame('Body' . "\n", $template->body);
    }

    public function test_it_names_the_file_it_cannot_read(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('drop.md not found');

        IssueTemplate::fromFile(vfsStream::url(UsesVfsConstants::ROOT_DIR . '/drop.md'));
    }

    public function test_it_names_the_file_with_a_malformed_template(): void
    {
        $file = vfsStream::url(UsesVfsConstants::ROOT_DIR . '/add.md');
        file_put_contents($file, 'no front matter');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('add.md: expected front matter');

        IssueTemplate::fromFile($file);
    }
}
