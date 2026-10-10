<?php

declare(strict_types=1);

namespace Drupal\Tests\literals\Unit;

use Drupal\Tests\UnitTestCase;
use Drupal\literals\ResolvedLiteral;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests the link target a resolved literal offers.
 */
#[Group('literals')]
class ResolvedLiteralTest extends UnitTestCase {

  /**
   * Only safe schemes come back as a link target.
   */
  #[DataProvider('hrefCases')]
  public function testHref(string $kind, string $value, ?string $expected): void {
    $this->assertSame($expected, (new ResolvedLiteral($value, 'Label', $kind))->href());
  }

  /**
   * Cases for testHref(): kind, value, expected target.
   */
  public static function hrefCases(): array {
    return [
      'https url' => [ResolvedLiteral::KIND_URL, 'https://example.com/a', 'https://example.com/a'],
      'http url, any case' => [ResolvedLiteral::KIND_URL, 'HTTP://example.com', 'HTTP://example.com'],
      'javascript url' => [ResolvedLiteral::KIND_URL, 'javascript:alert(1)', NULL],
      'data url' => [ResolvedLiteral::KIND_URL, 'data:text/html,<script>', NULL],
      'protocol-relative url' => [ResolvedLiteral::KIND_URL, '//evil.example/x', NULL],
      'relative path' => [ResolvedLiteral::KIND_URL, '/user/login', NULL],
      'ftp url' => [ResolvedLiteral::KIND_URL, 'ftp://example.com', NULL],
      'phone keeps plus and digits' => [ResolvedLiteral::KIND_PHONE, '+44 (1223) 000-000', 'tel:+441223000000'],
      'phone with injected scheme' => [ResolvedLiteral::KIND_PHONE, 'javascript:1', 'tel:1'],
      'email' => [ResolvedLiteral::KIND_EMAIL, 'help@example.com', 'mailto:help@example.com'],
      'text has none' => [ResolvedLiteral::KIND_TEXT, 'https://example.com', NULL],
    ];
  }

  /**
   * The plain array carries value, label and kind.
   */
  public function testToArray(): void {
    $item = new ResolvedLiteral('v', 'l', ResolvedLiteral::KIND_PHONE);
    $this->assertSame(['value' => 'v', 'label' => 'l', 'kind' => 'phone'], $item->toArray());
  }

}
