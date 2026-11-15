<?php

namespace Tests\Feature;

use App\Services\AuditLogger;
use Tests\TestCase;

class SecurityHardeningTest extends TestCase
{
    public function test_cors_does_not_contain_wildcard_origin_when_credentials_supported(): void
    {
        $supportsCredentials = config('cors.supports_credentials');
        $allowedOrigins = config('cors.allowed_origins', []);

        $this->assertTrue($supportsCredentials, 'CORS must support credentials for Sanctum SPA cookies');
        $this->assertNotContains('*', $allowedOrigins, 'CORS allowed_origins must NEVER contain wildcard (*) when credentials are supported');
    }

    public function test_audit_logger_redacts_all_sensitive_tokens_and_secrets(): void
    {
        $sensitiveData = [
            'username' => 'admin_user',
            'password' => 'super_secret_password',
            'password_confirmation' => 'super_secret_password',
            'app_key' => 'base64:uniquesecretkey==',
            'token' => 'plain_text_pat_token',
            'access_token' => 'jwt_or_bearer_token',
            'visitor_token' => 'vis_token_raw_12345',
            'reverb_secret' => 'super_secret_reverb_key',
            'csrf_token' => 'csrf_protection_token_value',
            'nested' => [
                'api_key' => 'nested_api_secret',
                'regular_field' => 'visible_value',
            ],
        ];

        $sanitized = AuditLogger::sanitize($sensitiveData);

        $this->assertEquals('[REDACTED]', $sanitized['password']);
        $this->assertEquals('[REDACTED]', $sanitized['password_confirmation']);
        $this->assertEquals('[REDACTED]', $sanitized['app_key']);
        $this->assertEquals('[REDACTED]', $sanitized['token']);
        $this->assertEquals('[REDACTED]', $sanitized['access_token']);
        $this->assertEquals('[REDACTED]', $sanitized['visitor_token']);
        $this->assertEquals('[REDACTED]', $sanitized['reverb_secret']);
        $this->assertEquals('[REDACTED]', $sanitized['csrf_token']);
        $this->assertEquals('[REDACTED]', $sanitized['nested']['api_key']);
        $this->assertEquals('visible_value', $sanitized['nested']['regular_field']);
        $this->assertEquals('admin_user', $sanitized['username']);
    }

    public function test_security_headers_are_attached_to_responses(): void
    {
        $response = $this->get('/health');

        $response->assertHeader('X-Content-Type-Options', 'nosniff');
        $response->assertHeader('X-XSS-Protection', '0');
        $response->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin');
        $response->assertHeader('X-Frame-Options', 'SAMEORIGIN');
    }

    public function test_webchat_public_endpoints_do_not_block_framing(): void
    {
        $response = $this->postJson('/api/v1/public/webchat/nonexistent/embed');

        $response->assertHeader('X-Content-Type-Options', 'nosniff');
        $response->assertHeader('X-XSS-Protection', '0');
        $response->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin');
        $this->assertFalse($response->headers->has('X-Frame-Options'));
    }
}
