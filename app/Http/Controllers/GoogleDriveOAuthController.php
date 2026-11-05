<?php

namespace App\Http\Controllers;

use Google\Client as GoogleClient;
use Google\Service\Drive as GoogleDriveService;
use Illuminate\Http\Request;

class GoogleDriveOAuthController extends Controller
{
    /**
     * Redirects user to Google OAuth 2.0 Consent Screen to obtain an offline Refresh Token.
     */
    public function redirect(Request $request)
    {
        $clientId = trim((string) $request->query('client_id', ''));
        $clientSecret = trim((string) $request->query('client_secret', ''));
        $redirectUri = trim((string) $request->query('redirect_uri', url('/auth/google/callback')));

        if ($clientId === '' || $clientSecret === '') {
            return response()->make(
                '<div style="font-family:sans-serif;padding:30px;text-align:center;">
                    <h3 style="color:#dc2626;">Missing Google OAuth Client ID or Client Secret</h3>
                    <p>Please enter both Client ID and Client Secret in the Storage Connection form first.</p>
                </div>',
                422
            );
        }

        session([
            'google_oauth_client_id' => $clientId,
            'google_oauth_client_secret' => $clientSecret,
            'google_oauth_redirect_uri' => $redirectUri,
        ]);

        $statePayload = json_encode([
            'client_id' => $clientId,
            'client_secret' => $clientSecret,
            'redirect_uri' => $redirectUri,
        ]);

        $client = new GoogleClient();
        $client->setClientId($clientId);
        $client->setClientSecret($clientSecret);
        $client->setRedirectUri($redirectUri);
        $client->setAccessType('offline');
        $client->setPrompt('consent');
        $client->setState(encrypt($statePayload));
        $client->addScope(GoogleDriveService::DRIVE);

        return redirect()->away($client->createAuthUrl());
    }

    /**
     * Handles the OAuth 2.0 callback from Google Cloud Console
     * (Supports both /vaultix/auth/google/callback and /auth/google/callback).
     */
    public function callback(Request $request)
    {
        $code = trim((string) $request->query('code', ''));
        $stateRaw = (string) $request->query('state', '');
        $stateData = [];
        if ($stateRaw !== '') {
            try {
                $decrypted = decrypt($stateRaw);
                $stateData = json_decode($decrypted, true) ?: [];
            } catch (\Throwable) {}
        }

        $clientId = $stateData['client_id'] ?? (string) session('google_oauth_client_id', '');
        $clientSecret = $stateData['client_secret'] ?? (string) session('google_oauth_client_secret', '');
        $redirectUri = $stateData['redirect_uri'] ?? (string) session('google_oauth_redirect_uri', url('/auth/google/callback'));

        $refreshToken = '';
        $errorMessage = null;

        if ($code !== '' && $clientId !== '' && $clientSecret !== '') {
            try {
                $client = new GoogleClient();
                $client->setClientId($clientId);
                $client->setClientSecret($clientSecret);
                $client->setRedirectUri($redirectUri);

                $token = $client->fetchAccessTokenWithAuthCode($code);
                if (isset($token['error'])) {
                    $errorMessage = $token['error_description'] ?? $token['error'];
                } else {
                    $refreshToken = (string) ($token['refresh_token'] ?? $token['access_token'] ?? '');
                    if ($refreshToken !== '') {
                        session(['google_oauth_refresh_token' => $refreshToken]);
                    }
                }
            } catch (\Throwable $e) {
                $errorMessage = $e->getMessage();
            }
        } elseif ($request->has('error')) {
            $errorMessage = (string) $request->query('error_description', $request->query('error'));
        }

        $safeToken = htmlspecialchars($refreshToken, ENT_QUOTES, 'UTF-8');
        $safeError = htmlspecialchars((string) $errorMessage, ENT_QUOTES, 'UTF-8');

        return response()->make("
            <!DOCTYPE html>
            <html lang='en'>
            <head>
                <meta charset='UTF-8'>
                <title>Google Drive OAuth Token Callback</title>
                " . view('partials.theme-head')->render() . "
                <style>
                    :root { --bg: #f1f5f9; --surface: #ffffff; --border: #e2e8f0; --primary: #059669; --text: #0f172a; --text-soft: #334155; --token: #065f46; --info: #b45309; --shadow: rgba(15,23,42,0.12); }
                    html.dark { --bg: #0f172a; --surface: #1e293b; --border: #334155; --primary: #10b981; --text: #f8fafc; --text-soft: #cbd5e1; --token: #6ee7b7; --info: #f59e0b; --shadow: rgba(0,0,0,0.4); }
                    body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; background: var(--bg); color: var(--text); display: flex; align-items: center; justify-content: center; min-height: 100vh; margin: 0; }
                    .card { background: var(--surface); border: 1px solid var(--border); border-radius: 12px; padding: 28px; max-width: 520px; width: 90%; text-align: center; box-shadow: 0 20px 25px -5px var(--shadow); }
                    .token-box { background: var(--bg); border: 1px solid var(--primary); color: var(--token); padding: 12px; border-radius: 8px; font-family: monospace; word-break: break-all; margin: 16px 0; font-size: 13px; }
                    .btn { background: #10b981; color: #064e3b; font-weight: 700; border: none; padding: 10px 20px; border-radius: 8px; cursor: pointer; font-size: 14px; }
                </style>
            </head>
            <body>
                <div class='card'>
                    " . ($refreshToken !== '' ? "
                        <h2 style='color:var(--primary);margin-top:0;'>✅ Google Drive Refresh Token Generated</h2>
                        <p style='color:var(--text-soft);font-size:14px;'>Your Refresh Token has been saved to your session. Click <strong>Update Token</strong> in the Storage Connection form or copy it below:</p>
                        <div class='token-box' id='tokenText'>{$safeToken}</div>
                        <div style='display:flex;gap:10px;justify-content:center;margin-top:16px;'>
                            <button class='btn' id='copyBtn' onclick='navigator.clipboard.writeText(document.getElementById(\"tokenText\").innerText); document.getElementById(\"copyBtn\").innerText=\"✅ Copied!\";'>📋 Copy Refresh Token</button>
                            <button class='btn' style='background:#64748b;color:#fff;' onclick='window.close()'>Done &amp; Close Window</button>
                        </div>
                        <script>
                            if (window.opener) {
                                window.opener.postMessage({ type: 'GOOGLE_DRIVE_REFRESH_TOKEN', token: '{$safeToken}' }, '*');
                            }
                        </script>
                    " : "
                        <h2 style='color:var(--info);margin-top:0;'>ℹ️ Google Drive OAuth Callback</h2>
                        <p style='color:var(--text-soft);font-size:14px;'>" . ($safeError ?: 'Return to your Storage Connection form and click Update Token to populate your Refresh Token.') . "</p>
                        <button class='btn' onclick='window.close()'>Close Window</button>
                    ") . "
                </div>
            </body>
            </html>
        ");
    }
}

