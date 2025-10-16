<?php

require_once __DIR__ . '/../includes/RateLimiterService.php';

use PHPUnit\Framework\TestCase;

class RateLimiterServiceTest extends TestCase
{
    private string $testIdentifier = 'test_user_123';
    
    protected function tearDown(): void
    {
        // Clean up any test blocks
        $this->cleanupTestBlocks();
    }
    
    private function cleanupTestBlocks(): void
    {
        $blockDir = dirname(__DIR__) . '/../secure/rate_blocks';
        if (is_dir($blockDir)) {
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($blockDir, RecursiveDirectoryIterator::SKIP_DOTS)
            );
            
            foreach ($iterator as $file) {
                if ($file->isFile() && strpos($file->getFilename(), 'test_user_123') !== false) {
                    unlink($file->getPathname());
                }
            }
        }
    }
    
    public function testGetIdentifier()
    {
        // Test without session or user ID
        $identifier = RateLimiterService::getIdentifier();
        $this->assertIsString($identifier);
        $this->assertStringStartsWith('ip_', $identifier);
    }
    
    public function testGetRateLimitConfig()
    {
        $configs = [
            'generate' => ['operations' => 10, 'interval' => 60, 'block_duration' => 300],
            'admin_login' => ['operations' => 5, 'interval' => 300, 'block_duration' => 900],
            'default' => ['operations' => 30, 'interval' => 60, 'block_duration' => 120],
        ];
        
        foreach ($configs as $context => $expectedConfig) {
            $config = RateLimiterService::getRateLimitConfig($context);
            $this->assertIsArray($config);
            $this->assertEquals($expectedConfig, $config);
        }
    }
    
    public function testUnknownContextReturnsDefault()
    {
        $defaultConfig = RateLimiterService::getRateLimitConfig('nonexistent');
        $expectedDefault = RateLimiterService::getRateLimitConfig('default');
        $this->assertEquals($expectedDefault, $defaultConfig);
    }
    
    public function testCheckRateLimitWithinLimits()
    {
        // This should be allowed (working within rate limits)
        $result = RateLimiterService::checkRateLimit($this->testIdentifier, 'test');
        
        $this->assertIsArray($result);
        $this->assertArrayHasKey('allowed', $result);
        $this->assertArrayHasKey('remaining', $result);
        $this->assertArrayHasKey('reset_at', $result);
        $this->assertArrayHasKey('limit', $result);
        $this->assertArrayHasKey('interval', $result);
        $this->assertArrayHasKey('blocked', $result);
        
        $this->assertTrue($result['allowed']);
        $this->assertFalse($result['blocked']);
    }
    
    public function testGetRateLimitStatus()
    {
        $status = RateLimiterService::getRateLimitStatus($this->testIdentifier, 'test');
        
        $this->assertIsArray($status);
        $this->assertArrayHasKey('identifier', $status);
        $this->assertArrayHasKey('context', $status);
        $this->assertArrayHasKey('config', $status);
        $this->assertArrayHasKey('status', $status);
        $this->assertArrayHasKey('timestamp', $status);
        
        $this->assertEquals($this->testIdentifier, $status['identifier']);
        $this->assertEquals('test', $status['context']);
    }
    
    public function testRandomRateLimiterScenario()
    {
        // Test multiple requests to simulate real usage
        $identifier = 'random_user_' . uniqid();
        $requests = [];
        
        // Make several requests
        for ($i = 0; $i < 5; $i++) {
            $request = [
                'request_number' => $i + 1,
                'timestamp' => time(),
            ];
            
            $result = RateLimiterService::checkRateLimit($identifier, 'test');
            $request['result'] = $result;
            $requests[] = $request;
            
            // Small delay to simulate real usage
            usleep(1000); // 1ms
        }
        
        // All requests should be allowed
        $this->assertNotEmpty($requests);
        foreach ($requests as $request) {
            $this->assertTrue($request['result']['allowed'], 
                "Request {$request['request_number']} should be allowed");
        }
    }
    
    public function testDifferentContextsHaveIndependentLimits()
    {
        $identifier = 'context_test_user';
        
        // Check rate limit for context1
        $result1 = RateLimiterService::checkRateLimit($identifier, 'context1');
        $this->assertTrue($result1['allowed']);
        
        // Check rate limit for context2 (should be independent)
        $result2 = RateLimiterService::checkRateLimit($identifier, 'context2');
        $this->assertTrue($result2['allowed']);
        
        // Both contexts should have their own limits
        $this->assertNotEquals($result1['remaining'], $result2['remaining'] ?? null);
    }
    
    public function testGenerateContextLimit()
    {
        $generateConfig = RateLimiterService::getRateLimitConfig('generate');
        
        $this->assertArrayHasKey('operations', $generateConfig);
        $this->assertArrayHasKey('interval', $generateConfig);
        $this->assertArrayHasKey('block_duration', $generateConfig);
        
        $this->assertEquals(10, $generateConfig['operations']);
        $this->assertEquals(60, $generateConfig['interval']);
        $this->assertEquals(300, $generateConfig['block_duration']);
    }
    
    public function testAdminLoginContextLimit()
    {
        $adminConfig = RateLimiterService::getRateLimitConfig('admin_login');
        
        $this->assertArrayHasKey('operations', $adminConfig);
        $this->assertArrayHasKey('interval', $adminConfig);
        $this->assertArrayHasKey('block_duration', $adminConfig);
        
        $this->assertEquals(5, $adminConfig['operations']);
        $this->assertEquals(300, $adminConfig['interval']); // 5 minutes
        $this->assertEquals(900, $adminConfig['block_duration']); // 15 minutes
    }
    
    public function testApiContextLimit()
    {
        $apiConfig = RateLimiterService::getRateLimitConfig('api');
        
        $this->assertArrayHasKey('operations', $apiConfig);
        $this->assertArrayHasKey('interval', $apiConfig);
        $this->assertArrayHasKey('block_duration', $apiConfig);
        
        // API should have higher limits than default
        $defaultConfig = RateLimiterService::getRateLimitConfig('default');
        $this->assertGreaterThan($defaultConfig['operations'], $apiConfig['operations']);
    }
    
    public function testCleanupExpiredBlocks()
    {
        // This test ensures the cleanup method can run without errors
        $cleaned = RateLimiterService::cleanupExpiredBlocks();
        $this->assertIsInt($cleaned);
        $this->assertGreaterThanOrEqual(0, $cleaned);
    }
    
    public function testRealIpDetection()
    {
        // Test that the IP detection method exists and returns a string
        // We can't test specific IPs without setting up complex server environments
        $reflection = new ReflectionClass('RateLimiterService');
        $method = $reflection->getMethod('getRealIpAddress');
        $method->setAccessible(true);
        
        $ip = $method->invoke(null);
        $this->assertIsString($ip);
    }
}
