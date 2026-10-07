<?php

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Http;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Models\User;

class SocialAuthController
{
    private const AUTH_URL     = 'https://accounts.google.com/o/oauth2/auth';
    private const TOKEN_URL    = 'https://oauth2.googleapis.com/token';
    private const USERINFO_URL = 'https://www.googleapis.com/oauth2/v3/userinfo';

    /** Return the Google consent URL (stateless OAuth, as Socialite's ->stateless() did). */
    public function redirectToGoogle(Request $request): Response
    {
        $query = http_build_query([
            'client_id'     => config('services.google.client_id'),
            'redirect_uri'  => config('services.google.redirect'),
            'scope'         => 'openid profile email',
            'response_type' => 'code',
        ], '', '&', PHP_QUERY_RFC3986);

        return json_response(['url' => self::AUTH_URL . '?' . $query]);
    }

    public function handleGoogleCallback(Request $request): Response
    {
        $google = $this->fetchGoogleUser((string) $request->input('code', ''));

        $existing = User::query()
            ->whereRaw('google_id = ? OR email = ?', [$google['id'], $google['email']])
            ->first();

        if ($existing) {
            $updates = [
                'google_id'    => $google['id'],
                'google_token' => $google['token'],
                'avatar'       => $existing['avatar'] ?? $google['avatar'],
            ];
            if (!$existing['email_verified_at']) {
                $updates['email_verified_at'] = now();
            }
            $user = User::update($existing['id'], $updates);
        } else {
            $user = User::create([
                'name'              => $google['name'],
                'email'             => $google['email'],
                'google_id'         => $google['id'],
                'google_token'      => $google['token'],
                'avatar'            => $google['avatar'],
                'role'              => 'consumer',
                'referral_code'     => AuthController::newReferralCode(),
                'email_verified_at' => now(),
            ]);
        }

        if ($user['status'] === 'suspended') {
            throw new HttpException(403, 'Your account has been suspended.');
        }

        Auth::revokeAll($user['id']);
        $token = Auth::createToken($user['id']);

        return json_response([
            'message' => 'Google authentication successful.',
            'user'    => User::load(User::findOrFail($user['id']), ['farmer_profile', 'kyc_verification']),
            'token'   => $token,
        ]);
    }

    /** @return array{id:string,name:string,email:string,avatar:?string,token:string} */
    private function fetchGoogleUser(string $code): array
    {
        $fail = fn() => new HttpException(400, 'Google authentication failed.');

        if ($code === '') {
            throw $fail();
        }

        try {
            $tokenRes = Http::postForm(self::TOKEN_URL, [
                'client_id'     => config('services.google.client_id'),
                'client_secret' => config('services.google.client_secret'),
                'code'          => $code,
                'redirect_uri'  => config('services.google.redirect'),
                'grant_type'    => 'authorization_code',
            ]);
            $accessToken = $tokenRes['json']['access_token'] ?? null;
            if (!$accessToken) {
                throw $fail();
            }

            $info = Http::getJson(self::USERINFO_URL, ['Authorization: Bearer ' . $accessToken])['json'] ?? null;
        } catch (HttpException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw $fail();
        }

        // Linking by email is only safe when Google vouches for the address.
        if (!$info || empty($info['sub']) || empty($info['email']) || ($info['email_verified'] ?? true) === false) {
            throw $fail();
        }

        return [
            'id'     => (string) $info['sub'],
            'name'   => $info['name'] ?? $info['email'],
            'email'  => $info['email'],
            'avatar' => $info['picture'] ?? null,
            'token'  => $accessToken,
        ];
    }
}
