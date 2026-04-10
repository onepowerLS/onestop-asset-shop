<?php
/**
 * Firebase SSO Middleware for Laravel HR Portal
 * 
 * Install in the HR Portal at:
 *   app/Http/Middleware/FirebaseSSO.php
 * 
 * Then register in app/Http/Kernel.php:
 *   protected $middlewareGroups = [
 *       'web' => [
 *           // ... other middleware
 *           \App\Http\Middleware\FirebaseSSO::class,
 *       ],
 *   ];
 * 
 * Requirements:
 *   composer require firebase/php-jwt
 * 
 * Add to .env:
 *   FIREBASE_PROJECT_ID=pr-system-4ea55
 */

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Firebase\JWT\JWT;
use Firebase\JWT\JWK;
use Firebase\JWT\Key;

class FirebaseSSO
{
    private const TOKEN_EXPIRY_MS = 5 * 60 * 1000; // 5 minutes
    private const GOOGLE_KEYS_URL = 'https://www.googleapis.com/robot/v1/metadata/x509/securetoken@system.gserviceaccount.com';
    
    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next)
    {
        $ssoToken = $request->query('sso_token');
        $fromNexus = $request->query('from') === 'nexus';
        
        if ($ssoToken && $fromNexus && !Auth::check()) {
            $this->handleSSO($ssoToken, $request);
        }
        
        return $next($request);
    }
    
    /**
     * Process SSO token and authenticate user.
     */
    private function handleSSO(string $token, Request $request): void
    {
        try {
            // Decode the SSO payload
            $payload = json_decode(base64_decode($token), true);
            
            if (!$payload) {
                Log::warning('SSO: Invalid token format');
                return;
            }
            
            // Validate timestamp (token must be recent)
            $tokenAge = (time() * 1000) - ($payload['timestamp'] ?? 0);
            if ($tokenAge > self::TOKEN_EXPIRY_MS) {
                Log::warning('SSO: Token expired', ['age_ms' => $tokenAge]);
                return;
            }
            
            // Validate the Firebase ID token
            $firebaseUser = $this->validateFirebaseToken($payload['idToken'] ?? '');
            
            if (!$firebaseUser) {
                Log::warning('SSO: Firebase token validation failed');
                return;
            }
            
            // Find local user by email
            $localUser = \App\Models\User::where('email', $firebaseUser['email'])
                ->where('active', true)
                ->first();
            
            if ($localUser) {
                // Log the user in
                Auth::login($localUser, true); // Remember the user
                
                // Update last login
                $localUser->update([
                    'last_login_at' => now(),
                    'firebase_uid' => $firebaseUser['uid'],
                ]);
                
                Log::info('SSO: User authenticated', [
                    'email' => $firebaseUser['email'],
                    'user_id' => $localUser->id,
                ]);
                
                // Redirect to clean URL (remove SSO params)
                $cleanUrl = $request->url();
                header("Location: {$cleanUrl}");
                exit;
            } else {
                Log::warning('SSO: User not found in HR Portal', [
                    'email' => $firebaseUser['email'],
                ]);
            }
        } catch (\Exception $e) {
            Log::error('SSO: Error processing token', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
        }
    }
    
    /**
     * Validate Firebase ID token and extract user info.
     */
    private function validateFirebaseToken(string $idToken): ?array
    {
        if (empty($idToken)) {
            return null;
        }
        
        $projectId = config('services.firebase.project_id', env('FIREBASE_PROJECT_ID', 'pr-system-4ea55'));
        
        try {
            // Fetch Google's public keys for Firebase
            $keysJson = file_get_contents(self::GOOGLE_KEYS_URL);
            if (!$keysJson) {
                Log::error('SSO: Failed to fetch Google public keys');
                return null;
            }
            
            $keys = json_decode($keysJson, true);
            
            // Extract the key ID from the token header
            $tokenParts = explode('.', $idToken);
            if (count($tokenParts) !== 3) {
                return null;
            }
            
            $header = json_decode(base64_decode($tokenParts[0]), true);
            $kid = $header['kid'] ?? null;
            
            if (!$kid || !isset($keys[$kid])) {
                Log::error('SSO: Key ID not found in Google keys');
                return null;
            }
            
            // Decode and verify the token
            $publicKey = openssl_pkey_get_public($keys[$kid]);
            $decoded = JWT::decode($idToken, new Key($publicKey, 'RS256'));
            
            // Verify claims
            if ($decoded->aud !== $projectId) {
                Log::warning('SSO: Invalid audience', [
                    'expected' => $projectId,
                    'actual' => $decoded->aud,
                ]);
                return null;
            }
            
            $expectedIssuer = "https://securetoken.google.com/{$projectId}";
            if ($decoded->iss !== $expectedIssuer) {
                Log::warning('SSO: Invalid issuer', [
                    'expected' => $expectedIssuer,
                    'actual' => $decoded->iss,
                ]);
                return null;
            }
            
            // Check expiry
            if ($decoded->exp < time()) {
                Log::warning('SSO: Firebase token expired');
                return null;
            }
            
            return [
                'uid' => $decoded->sub,
                'email' => $decoded->email ?? '',
                'name' => $decoded->name ?? '',
                'email_verified' => $decoded->email_verified ?? false,
            ];
        } catch (\Exception $e) {
            Log::error('SSO: JWT decode error', [
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }
}
