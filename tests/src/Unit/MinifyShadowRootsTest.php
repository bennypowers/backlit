<?php

declare(strict_types=1);

namespace Drupal\Tests\backlit\Unit;

use Drupal\backlit\Service\LitSsrRenderer;
use PHPUnit\Framework\TestCase;

/**
 * @coversDefaultClass \Drupal\backlit\Service\LitSsrRenderer
 * @group backlit
 */
class MinifyShadowRootsTest extends TestCase {

  /**
   * Helper to call the private static method via reflection.
   */
  private static function minify(string $html): string {
    $ref = new \ReflectionMethod(LitSsrRenderer::class, 'minifyShadowRoots');
    return $ref->invoke(NULL, $html);
  }

  // -----------------------------------------------------------
  // CSS comment removal inside shadow roots
  // -----------------------------------------------------------

  /**
   * @covers ::minifyShadowRoots
   */
  public function testRemovesCssCommentInsideShadowRootStyle(): void {
    $html = '<my-el><template shadowroot="open" shadowrootmode="open">'
      . '<style>:host { display: block; /* a comment */ color: red; }</style>'
      . '</template></my-el>';

    $result = self::minify($html);

    $this->assertStringNotContainsString('/* a comment */', $result);
    $this->assertStringContainsString(':host { display: block;  color: red; }', $result);
  }

  /**
   * @covers ::minifyShadowRoots
   */
  public function testRemovesMultipleCssComments(): void {
    $html = '<my-el><template shadowroot="open" shadowrootmode="open">'
      . '<style>/* first */ :host { /* second */ display: block; } /* third */</style>'
      . '</template></my-el>';

    $result = self::minify($html);

    $this->assertStringNotContainsString('/* first */', $result);
    $this->assertStringNotContainsString('/* second */', $result);
    $this->assertStringNotContainsString('/* third */', $result);
    $this->assertStringContainsString(':host {  display: block; }', $result);
  }

  /**
   * @covers ::minifyShadowRoots
   */
  public function testRemovesMultiLineCssComment(): void {
    $css = ":host {\n  /* multi\n   * line\n   * comment */\n  display: block;\n}";
    $html = '<my-el><template shadowroot="open" shadowrootmode="open">'
      . "<style>$css</style>"
      . '</template></my-el>';

    $result = self::minify($html);

    $this->assertStringNotContainsString('multi', $result);
    $this->assertStringContainsString('display: block;', $result);
  }

  /**
   * @covers ::minifyShadowRoots
   */
  public function testPreservesCssContentPropertyWithCommentLikeString(): void {
    $html = '<my-el><template shadowroot="open" shadowrootmode="open">'
      . '<style>:host::before { content: "/* not a comment */"; }</style>'
      . '</template></my-el>';

    $result = self::minify($html);

    $this->assertStringContainsString('content: "/* not a comment */"', $result);
  }

  /**
   * @covers ::minifyShadowRoots
   */
  public function testPreservesCssContentPropertyWithSingleQuotes(): void {
    $html = '<my-el><template shadowroot="open" shadowrootmode="open">'
      . "<style>:host::before { content: '/* also not a comment */'; }</style>"
      . '</template></my-el>';

    $result = self::minify($html);

    $this->assertStringContainsString("content: '/* also not a comment */'", $result);
  }

  /**
   * @covers ::minifyShadowRoots
   */
  public function testPreservesCssUrlWithSlashStar(): void {
    $html = '<my-el><template shadowroot="open" shadowrootmode="open">'
      . '<style>:host { background: url("path/*/img.png"); }</style>'
      . '</template></my-el>';

    $result = self::minify($html);

    $this->assertStringContainsString('url("path/*/img.png")', $result);
  }

  /**
   * @covers ::minifyShadowRoots
   */
  public function testRemovesCssCommentButPreservesAdjacentStringContent(): void {
    $html = '<my-el><template shadowroot="open" shadowrootmode="open">'
      . '<style>/* real comment */ :host::after { content: "/* keep */"; } /* another */</style>'
      . '</template></my-el>';

    $result = self::minify($html);

    $this->assertStringNotContainsString('/* real comment */', $result);
    $this->assertStringNotContainsString('/* another */', $result);
    $this->assertStringContainsString('content: "/* keep */"', $result);
  }

  /**
   * @covers ::minifyShadowRoots
   */
  public function testDoesNotRemoveCssCommentsOutsideShadowRoot(): void {
    $html = '<style>/* global comment */ body { margin: 0; }</style>'
      . '<my-el><template shadowroot="open" shadowrootmode="open">'
      . '<style>/* shadow comment */ :host { display: block; }</style>'
      . '</template></my-el>';

    $result = self::minify($html);

    $this->assertStringContainsString('/* global comment */', $result);
    $this->assertStringNotContainsString('/* shadow comment */', $result);
  }

  // -----------------------------------------------------------
  // HTML comment removal inside shadow roots
  // -----------------------------------------------------------

  /**
   * @covers ::minifyShadowRoots
   */
  public function testRemovesHtmlCommentInsideShadowRoot(): void {
    $html = '<my-el><template shadowroot="open" shadowrootmode="open">'
      . '<!-- a regular comment --><p>Hello</p>'
      . '</template></my-el>';

    $result = self::minify($html);

    $this->assertStringNotContainsString('<!-- a regular comment -->', $result);
    $this->assertStringContainsString('<p>Hello</p>', $result);
  }

  /**
   * @covers ::minifyShadowRoots
   */
  public function testRemovesMultiLineHtmlComment(): void {
    $html = "<my-el><template shadowroot=\"open\" shadowrootmode=\"open\">"
      . "<!-- multi\n     line\n     comment --><p>Hello</p>"
      . '</template></my-el>';

    $result = self::minify($html);

    $this->assertStringNotContainsString('multi', $result);
    $this->assertStringContainsString('<p>Hello</p>', $result);
  }

  /**
   * @covers ::minifyShadowRoots
   */
  public function testDoesNotRemoveHtmlCommentsOutsideShadowRoot(): void {
    $html = '<!-- page comment --><div><!-- inner comment --></div>'
      . '<my-el><template shadowroot="open" shadowrootmode="open">'
      . '<!-- shadow comment --><p>Hello</p>'
      . '</template></my-el>';

    $result = self::minify($html);

    $this->assertStringContainsString('<!-- page comment -->', $result);
    $this->assertStringContainsString('<!-- inner comment -->', $result);
    $this->assertStringNotContainsString('<!-- shadow comment -->', $result);
  }

  // -----------------------------------------------------------
  // Lit marker preservation
  // -----------------------------------------------------------

  /**
   * @covers ::minifyShadowRoots
   */
  public function testPreservesLitPartMarkersWithHash(): void {
    $html = '<!--lit-part abc=--><my-el><template shadowroot="open" shadowrootmode="open">'
      . '<!--lit-part xyz=--><p>Hello</p><!--/lit-part-->'
      . '</template></my-el><!--/lit-part-->';

    $result = self::minify($html);

    $this->assertStringContainsString('<!--lit-part abc=-->', $result);
    $this->assertStringContainsString('<!--lit-part xyz=-->', $result);
    $this->assertStringContainsString('<!--/lit-part-->', $result);
  }

  /**
   * @covers ::minifyShadowRoots
   */
  public function testPreservesLitPartMarkersWithoutHash(): void {
    $html = '<my-el><template shadowroot="open" shadowrootmode="open">'
      . '<!--lit-part--><p>Hello</p><!--/lit-part-->'
      . '</template></my-el>';

    $result = self::minify($html);

    $this->assertStringContainsString('<!--lit-part-->', $result);
    $this->assertStringContainsString('<!--/lit-part-->', $result);
  }

  /**
   * @covers ::minifyShadowRoots
   */
  public function testPreservesLitNodeMarkers(): void {
    $html = '<my-el><template shadowroot="open" shadowrootmode="open">'
      . '<!--lit-node 0--><p>Hello</p>'
      . '</template></my-el>';

    $result = self::minify($html);

    $this->assertStringContainsString('<!--lit-node 0-->', $result);
  }

  /**
   * @covers ::minifyShadowRoots
   */
  public function testRemovesRegularCommentAdjacentToLitMarkers(): void {
    $html = '<my-el><template shadowroot="open" shadowrootmode="open">'
      . '<!--lit-part abc=--><!-- TODO: remove this --><p>Hello</p><!--/lit-part-->'
      . '</template></my-el>';

    $result = self::minify($html);

    $this->assertStringContainsString('<!--lit-part abc=-->', $result);
    $this->assertStringNotContainsString('<!-- TODO: remove this -->', $result);
    $this->assertStringContainsString('<!--/lit-part-->', $result);
  }

  // -----------------------------------------------------------
  // Nested and multiple shadow roots
  // -----------------------------------------------------------

  /**
   * @covers ::minifyShadowRoots
   */
  public function testHandlesNestedShadowRoots(): void {
    $html = '<!--lit-part a=--><outer-el><template shadowroot="open" shadowrootmode="open">'
      . '<style>/* outer comment */ :host { display: block; }</style>'
      . '<!-- outer html comment -->'
      . '<!--lit-part b=--><inner-el><template shadowroot="open" shadowrootmode="open">'
      . '<style>/* inner comment */ :host { color: red; }</style>'
      . '<!-- inner html comment -->'
      . '<p>Inner content</p>'
      . '</template></inner-el><!--/lit-part-->'
      . '</template></outer-el><!--/lit-part-->';

    $result = self::minify($html);

    // Both CSS comments removed.
    $this->assertStringNotContainsString('/* outer comment */', $result);
    $this->assertStringNotContainsString('/* inner comment */', $result);

    // Both HTML comments removed.
    $this->assertStringNotContainsString('<!-- outer html comment -->', $result);
    $this->assertStringNotContainsString('<!-- inner html comment -->', $result);

    // CSS rules preserved.
    $this->assertStringContainsString(':host { display: block; }', $result);
    $this->assertStringContainsString(':host { color: red; }', $result);

    // Lit markers preserved.
    $this->assertStringContainsString('<!--lit-part a=-->', $result);
    $this->assertStringContainsString('<!--lit-part b=-->', $result);

    // Content preserved.
    $this->assertStringContainsString('<p>Inner content</p>', $result);
  }

  /**
   * @covers ::minifyShadowRoots
   */
  public function testHandlesMultipleSibingShadowRoots(): void {
    $html = '<el-a><template shadowroot="open" shadowrootmode="open">'
      . '<style>/* comment a */</style><!-- html a --><p>A</p>'
      . '</template></el-a>'
      . '<el-b><template shadowroot="open" shadowrootmode="open">'
      . '<style>/* comment b */</style><!-- html b --><p>B</p>'
      . '</template></el-b>';

    $result = self::minify($html);

    $this->assertStringNotContainsString('/* comment a */', $result);
    $this->assertStringNotContainsString('/* comment b */', $result);
    $this->assertStringNotContainsString('<!-- html a -->', $result);
    $this->assertStringNotContainsString('<!-- html b -->', $result);
    $this->assertStringContainsString('<p>A</p>', $result);
    $this->assertStringContainsString('<p>B</p>', $result);
  }

  // -----------------------------------------------------------
  // Edge cases
  // -----------------------------------------------------------

  /**
   * @covers ::minifyShadowRoots
   */
  public function testHandlesHtmlWithNoShadowRoots(): void {
    $html = '<div><!-- comment --><p>Hello</p></div>';

    $result = self::minify($html);

    $this->assertSame($html, $result);
  }

  /**
   * @covers ::minifyShadowRoots
   */
  public function testHandlesEmptyString(): void {
    $this->assertSame('', self::minify(''));
  }

  /**
   * @covers ::minifyShadowRoots
   */
  public function testHandlesEmptyShadowRoot(): void {
    $html = '<my-el><template shadowroot="open" shadowrootmode="open">'
      . '</template></my-el>';

    $result = self::minify($html);

    $this->assertSame($html, $result);
  }

  /**
   * @covers ::minifyShadowRoots
   */
  public function testHandlesEmptyStyleTag(): void {
    $html = '<my-el><template shadowroot="open" shadowrootmode="open">'
      . '<style></style><p>Hello</p>'
      . '</template></my-el>';

    $result = self::minify($html);

    $this->assertStringContainsString('<style></style>', $result);
  }

  /**
   * @covers ::minifyShadowRoots
   */
  public function testPreservesNonShadowRootTemplates(): void {
    $html = '<my-el><template shadowroot="open" shadowrootmode="open">'
      . '<!-- shadow comment -->'
      . '<template id="inner"><!-- keep this template comment --></template>'
      . '</template></my-el>';

    $result = self::minify($html);

    $this->assertStringNotContainsString('<!-- shadow comment -->', $result);
    // The inner template's comment is inside the shadow root, so it gets
    // stripped too -- it's still inside a shadow root even if nested in
    // a non-shadow template.
    $this->assertStringContainsString('<template id="inner">', $result);
  }

  /**
   * @covers ::minifyShadowRoots
   */
  public function testPreservesAttributesContainingCommentLikeSyntax(): void {
    $html = '<my-el><template shadowroot="open" shadowrootmode="open">'
      . '<div data-info="<!-- not a comment -->">Hello</div>'
      . '</template></my-el>';

    $result = self::minify($html);

    $this->assertStringContainsString('data-info="<!-- not a comment -->"', $result);
  }

  /**
   * @covers ::minifyShadowRoots
   */
  public function testMultipleStyleBlocksInOneShadowRoot(): void {
    $html = '<my-el><template shadowroot="open" shadowrootmode="open">'
      . '<style>/* comment 1 */ :host { display: block; }</style>'
      . '<style>/* comment 2 */ .inner { color: red; }</style>'
      . '</template></my-el>';

    $result = self::minify($html);

    $this->assertStringNotContainsString('/* comment 1 */', $result);
    $this->assertStringNotContainsString('/* comment 2 */', $result);
    $this->assertStringContainsString(':host { display: block; }', $result);
    $this->assertStringContainsString('.inner { color: red; }', $result);
  }

  /**
   * @covers ::minifyShadowRoots
   */
  public function testCssCommentOnlyStyle(): void {
    $html = '<my-el><template shadowroot="open" shadowrootmode="open">'
      . '<style>/* only a comment */</style>'
      . '</template></my-el>';

    $result = self::minify($html);

    $this->assertStringContainsString('<style></style>', $result);
  }

  /**
   * @covers ::minifyShadowRoots
   */
  public function testPreservesBackslashEscapedQuotesInCssStrings(): void {
    $html = '<my-el><template shadowroot="open" shadowrootmode="open">'
      . '<style>:host::before { content: "he said \\"/* hi */\\""; } /* remove me */</style>'
      . '</template></my-el>';

    $result = self::minify($html);

    $this->assertStringContainsString('content: "he said \\"/* hi */\\""', $result);
    $this->assertStringNotContainsString('/* remove me */', $result);
  }

  /**
   * @covers ::minifyShadowRoots
   */
  public function testRealWorldLitSsrOutput(): void {
    // Mimics actual lit-ssr binary output structure.
    $html = '<!--lit-part Vivs1llxtaw=--><test-el>'
      . '<template shadowroot="open" shadowrootmode="open">'
      . '<style>:host { display: block; color: red; /* a comment */ }</style>'
      . '<!--lit-part Q3Hd6wWV3sI=-->'
      . '<!-- a regular comment -->'
      . '<p>Hello</p>'
      . '<!--/lit-part-->'
      . '</template>'
      . '</test-el><!--/lit-part-->';

    $result = self::minify($html);

    // Lit markers preserved.
    $this->assertStringContainsString('<!--lit-part Vivs1llxtaw=-->', $result);
    $this->assertStringContainsString('<!--lit-part Q3Hd6wWV3sI=-->', $result);
    $this->assertStringContainsString('<!--/lit-part-->', $result);

    // Comments removed.
    $this->assertStringNotContainsString('/* a comment */', $result);
    $this->assertStringNotContainsString('<!-- a regular comment -->', $result);

    // Content preserved.
    $this->assertStringContainsString('<p>Hello</p>', $result);
    $this->assertStringContainsString(':host { display: block; color: red;  }', $result);
  }

}
