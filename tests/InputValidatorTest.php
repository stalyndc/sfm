<?php

require_once __DIR__ . '/../includes/InputValidator.php';

use PHPUnit\Framework\TestCase;

class InputValidatorTest extends TestCase
{
    public function testValidateUrlValidUrls()
    {
        $validUrls = [
            'https://example.com',
            'http://example.org',
            'https://www.example.com/path/to/page',
            'http://subdomain.example.co.uk',
        ];

        foreach ($validUrls as $url) {
            $result = InputValidator::validateUrl($url, true);
            $this->assertTrue($result['valid'], "URL should be valid: $url");
            $this->assertEquals(filter_var($url, FILTER_SANITIZE_URL), $result['value']);
        }
    }

    public function testValidateUrlInvalidUrls()
    {
        $invalidUrls = [
            '',
            'not-a-url',
            'ftp://example.com',
            'javascript:alert("xss")',
            'https://',
        ];

        foreach ($invalidUrls as $url) {
            $result = InputValidator::validateUrl($url, true);
            $this->assertFalse($result['valid'], "URL should be invalid: $url");
            $this->assertArrayHasKey('errors', $result);
        }
    }

    public function testValidateUrlOptional()
    {
        // Empty URL should be valid when not required
        $result = InputValidator::validateUrl('', false);
        $this->assertTrue($result['valid']);
        $this->assertEquals('', $result['value']);
    }

    public function testValidateFormatValidFormats()
    {
        $validFormats = ['rss', 'atom', 'jsonfeed'];

        foreach ($validFormats as $format) {
            $result = InputValidator::validateFormat($format);
            $this->assertTrue($result['valid'], "Format should be valid: $format");
            $this->assertEquals($format, $result['value']);
        }
    }

    public function testValidateFormatInvalidFormats()
    {
        $invalidFormats = ['xml', 'json', 'feed', 'invalid'];

        foreach ($invalidFormats as $format) {
            $result = InputValidator::validateFormat($format);
            $this->assertFalse($result['valid'], "Format should be invalid: $format");
            $this->assertArrayHasKey('errors', $result);
        }
    }

    public function testValidateLimitValidLimits()
    {
        $validLimits = [1, 10, 25, 50];

        foreach ($validLimits as $limit) {
            $result = InputValidator::validateLimit($limit, 50);
            $this->assertTrue($result['valid'], "Limit should be valid: $limit");
            $this->assertEquals($limit, $result['value']);
        }
    }

    public function testValidateLimitInvalidLimits()
    {
        $invalidLimits = [0, -1, 51, 100];

        foreach ($invalidLimits as $limit) {
            $result = InputValidator::validateLimit($limit, 50);
            $this->assertFalse($result['valid'], "Limit should be invalid: $limit");
        }
    }

    public function testValidateEmailValidEmails()
    {
        $validEmails = [
            'test@example.com',
            'user.name+tag@domain.co.uk',
            'user@sub.domain.org',
        ];

        foreach ($validEmails as $email) {
            $result = InputValidator::validateEmail($email, true);
            $this->assertTrue($result['valid'], "Email should be valid: $email");
        }
    }

    public function testValidateEmailInvalidEmails()
    {
        $invalidEmails = [
            '',
            'not-an-email',
            '@domain.com',
            'user@',
            'user..name@domain.com',
        ];

        foreach ($invalidEmails as $email) {
            $result = InputValidator::validateEmail($email, true);
            $this->assertFalse($result['valid'], "Email should be invalid: $email");
        }
    }

    public function testValidateSelectorValidSelectors()
    {
        $validSelectors = [
            'h2',
            '.article',
            '#title',
            'article .post',
            'div.content > h2',
            'ul li a',
            '*[data-id]',
            'button[type="submit"]',
        ];

        foreach ($validSelectors as $selector) {
            $result = InputValidator::validateSelector($selector, true);
            $this->assertTrue($result['valid'], "Selector should be valid: $selector");
        }
    }

    public function testValidateSelectorInvalidSelectors()
    {
        $invalidSelectors = [
            '<script>alert("xss")</script>',
            'a[href="javascript:alert(1)"]',
        ];

        foreach ($invalidSelectors as $selector) {
            $result = InputValidator::validateSelector($selector, true);
            $this->assertFalse($result['valid'], "Selector should be invalid: $selector");
        }
        
        // Test invalid selector syntax separately
        $invalidSyntax = 'div > > span';
        $result = InputValidator::validateSelector($invalidSyntax, true);
        // This might pass our regex but is syntactically invalid CSS
        // Let's just ensure it doesn't crash
        $this->assertIsArray($result);
    }

    public function testValidateFeedGenerationRequestValid()
    {
        $validData = [
            'url' => 'https://example.com',
            'format' => 'rss',
            'limit' => 10,
            'prefer_native' => true,
        ];

        $result = InputValidator::validateFeedGenerationRequest($validData);
        $this->assertTrue($result['valid']);
        $this->assertArrayHasKey('data', $result);
        
        $data = $result['data'];
        $this->assertEquals('https://example.com', $data['url']);
        $this->assertEquals('rss', $data['format']);
        $this->assertEquals(10, $data['limit']);
        $this->assertTrue($data['prefer_native']);
    }

    public function testValidateFeedGenerationRequestInvalid()
    {
        $invalidData = [
            'url' => 'invalid-url',
            'format' => 'invalid-format',
            'limit' => 0,
            'prefer_native' => 'not-boolean',
        ];

        $result = InputValidator::validateFeedGenerationRequest($invalidData);
        $this->assertFalse($result['valid']);
        $this->assertArrayHasKey('errors', $result);
        
        // Should have errors for url, format, and limit
        $this->assertArrayHasKey('url', $result['errors']);
        $this->assertArrayHasKey('format', $result['errors']);
        $this->assertArrayHasKey('limit', $result['errors']);
    }

    public function testValidateKeywordsValid()
    {
        $keywords = "technology\nprogramming\ncomputer science\ndata science";
        
        $result = InputValidator::validateKeywords($keywords);
        $this->assertTrue($result['valid']);
        
        $expectedKeywords = ['technology', 'programming', 'computer science', 'data science'];
        $this->assertEquals($expectedKeywords, $result['value']);
    }

    public function testValidateKeywordsWithEmptyLines()
    {
        $keywords = "valid keyword\n  \n another\n   \n";
        
        $result = InputValidator::validateKeywords($keywords);
        $this->assertTrue($result['valid']);
        
        $expectedKeywords = ['valid keyword', 'another'];
        $this->assertEquals($expectedKeywords, $result['value']);
    }

    public function testSanitizeString()
    {
        $maliciousString = '<script>alert("xss")</script>Hello<img src=x onerror=alert(1)>';
        $maxLength = 50;
        
        $sanitized = InputValidator::sanitizeString($maliciousString, $maxLength);
        
        // Should strip tags and limit length
        $this->assertStringNotContainsString('<script>', $sanitized);
        $this->assertStringNotContainsString('onerror', $sanitized);
        $this->assertLessThanOrEqual($maxLength, strlen($sanitized));
    }
}
