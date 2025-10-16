<?php
/**
 * includes/InputValidator.php
 * 
 * Input validation layer using Respect/Validation
 * Centralized validation for all user inputs
 */

declare(strict_types=1);

use Respect\Validation\Validator as v;
use Respect\Validation\Exceptions\NestedValidationException;

class InputValidator
{
    /**
     * Validate URL input
     */
    public static function validateUrl(string $url, bool $required = true): array
    {
        $validator = v::url()
            ->length(5, 2048);

        if (!$required && empty($url)) {
            return ['valid' => true, 'value' => ''];
        }

        try {
            $validator->assert($url);
            
            // Additional check for http/https scheme
            $parsedUrl = parse_url($url);
            if (!$parsedUrl || !in_array($parsedUrl['scheme'] ?? '', ['http', 'https'])) {
                return ['valid' => false, 'errors' => ['URL must use http or https scheme']];
            }
            
            return ['valid' => true, 'value' => filter_var($url, FILTER_SANITIZE_URL)];
        } catch (NestedValidationException $e) {
            return ['valid' => false, 'errors' => $e->getMessages()];
        }
    }

    /**
     * Validate feed format
     */
    public static function validateFormat(string $format): array
    {
        $validator = v::in(['rss', 'atom', 'jsonfeed']);

        try {
            $validator->assert($format);
            return ['valid' => true, 'value' => $format];
        } catch (NestedValidationException $e) {
            return ['valid' => false, 'errors' => $e->getMessages()];
        }
    }

    /**
     * Validate limit (number of items)
     */
    public static function validateLimit(mixed $limit, int $max = 50): array
    {
        $validator = v::intType()
            ->between(1, $max);

        try {
            $validator->assert((int)$limit);
            return ['valid' => true, 'value' => (int)$limit];
        } catch (NestedValidationException $e) {
            return ['valid' => false, 'errors' => $e->getMessages()];
        }
    }

    /**
     * Validate email address
     */
    public static function validateEmail(string $email, bool $required = false): array
    {
        $validator = v::email()->length(1, 254);

        if (!$required && empty($email)) {
            return ['valid' => true, 'value' => ''];
        }

        try {
            $validator->assert($email);
            return ['valid' => true, 'value' => filter_var($email, FILTER_SANITIZE_EMAIL)];
        } catch (NestedValidationException $e) {
            return ['valid' => false, 'errors' => $e->getMessages()];
        }
    }

    /**
     * Validate feed selector for admin tools
     */
    public static function validateSelector(string $selector, bool $required = false): array
    {
        $validator = v::stringType()
            ->length(1, 500)
            ->regex('/^[a-zA-Z0-9\s\-\.\#\[\]\(\),\:\>\+\*~\=\"]+$/')
            ->not(v::regex('/javascript:|on\w+\s*=/i'));

        if (!$required && empty($selector)) {
            return ['valid' => true, 'value' => ''];
        }

        try {
            $validator->assert($selector);
            return ['valid' => true, 'value' => trim($selector)];
        } catch (NestedValidationException $e) {
            return ['valid' => false, 'errors' => $e->getMessages()];
        }
    }

    /**
     * Validate domain/IP for trusted proxies
     */
    public static function validateTrustedProxy(string $proxy): array
    {
        $validator = v::oneOf(
            v::ip(),
            v::domain()
        );

        try {
            $validator->assert($proxy);
            return ['valid' => true, 'value' => $proxy];
        } catch (NestedValidationException $e) {
            return ['valid' => false, 'errors' => $e->getMessages()];
        }
    }

    /**
     * Validate search term for admin
     */
    public static function validateSearch(string $search): array
    {
        $validator = v::stringType()
            ->length(1, 100)
            ->noWhitespace();

        if (empty($search)) {
            return ['valid' => true, 'value' => ''];
        }

        try {
            $validator->assert(trim($search));
            return ['valid' => true, 'value' => trim($search)];
        } catch (NestedValidationException $e) {
            return ['valid' => false, 'errors' => $e->getMessages()];
        }
    }

    /**
     * Validate exclude/include keywords
     */
    public static function validateKeywords(string $keywords): array
    {
        // Split by newlines and validate each keyword
        $keywordList = array_filter(
            array_map('trim', explode("\n", $keywords)),
            static fn(string $keyword): bool => $keyword !== ''
        );

        $validator = v::stringType()
            ->length(1, 100);

        $errors = [];
        $validKeywords = [];

        foreach ($keywordList as $keyword) {
            try {
                if (!empty($keyword)) {
                    $validator->assert($keyword);
                    $validKeywords[] = $keyword;
                }
            } catch (NestedValidationException $e) {
                $errors[] = "Invalid keyword: " . implode(', ', $e->getMessages());
            }
        }

        if (!empty($errors)) {
            return ['valid' => false, 'errors' => $errors];
        }

        return ['valid' => true, 'value' => $validKeywords];
    }

    /**
     * Sanitize string input (basic XSS protection)
     */
    public static function sanitizeString(string $input, int $maxLength = 255): string
    {
        return mb_substr(
            strip_tags($input),
            0,
            $maxLength
        );
    }

    /**
     * Validate feed generation request
     */
    public static function validateFeedGenerationRequest(array $data): array
    {
        $errors = [];
        $validated = [];

        // Validate URL
        $urlValidation = self::validateUrl($data['url'] ?? '', true);
        if (!$urlValidation['valid']) {
            $errors['url'] = $urlValidation['errors'];
        } else {
            $validated['url'] = $urlValidation['value'];
        }

        // Validate format
        $formatValidation = self::validateFormat($data['format'] ?? 'rss');
        if (!$formatValidation['valid']) {
            $errors['format'] = $formatValidation['errors'];
        } else {
            $validated['format'] = $formatValidation['value'];
        }

        // Validate limit
        $limitValidation = self::validateLimit($data['limit'] ?? 10, 50);
        if (!$limitValidation['valid']) {
            $errors['limit'] = $limitValidation['errors'];
        } else {
            $validated['limit'] = $limitValidation['value'];
        }

        // Validate preference for native feeds
        $preferNative = isset($data['prefer_native']) ? (bool)$data['prefer_native'] : false;
        $validated['prefer_native'] = $preferNative;

        if (empty($errors)) {
            return ['valid' => true, 'data' => $validated];
        }

        return ['valid' => false, 'errors' => $errors];
    }
}
