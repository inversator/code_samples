<?php

namespace App\Http\Controllers\WEB\Auth;

use App\Events\UserAccountRegistered;
use App\Http\Controllers\Controller;
use App\Http\Requests\WEB\Auth\SocialiteLinkEmailRequest;
use App\Preference;
use App\Services\ReferrerService;
use App\Services\UserService;
use App\Session;
use App\User;
use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Laravel\Socialite\Facades\Socialite;

class SocialiteController extends Controller
{
    public function login(Request $request, UserService $userService): JsonResponse
    {
        // Get ga id
        preg_match('/.{3}\.\d\.([\d.]*)/mi', $request->get('ga'), $match);
        $gaId = last($match);
        $userIp = $request->get('user_ip');

        try {
            DB::beginTransaction();

            $validator = Validator::make($request->only(['token', 'service']), ['token' => 'required', 'service' => 'required']);

            if ($validator->fails()) {
                $validationErrorMessage = "Validation error <br/> " . convertValidatorErrorsToHtml($validator->errors());
                Log::channel('socialite')->error('Validation error', [$validationErrorMessage]);

                return response()->json([
                    'status' => 'Error',
                    'message' => $validationErrorMessage,
                    'error' => $validator->errors()
                ], Response::HTTP_BAD_REQUEST);
            }

            $data = $validator->validated();
            $service = array_get($data, 'service');

            try {
                $serviceUser = $this->getServiceUser($service, array_get($data, 'token'));
            } catch (Exception $e) {
                return jsonResponse(Response::HTTP_BAD_REQUEST, 'Error', $e->getMessage());
            }

            // Try to find user by email
            if ($serviceUser->email) {
                $user = User::where('email', $serviceUser->email)->withTrashed()->first();
            } else {
                // Trying to get user by service id
                $user = User::where($service . '_id', $serviceUser->id)->first();

                if (!$user) {
                    Log::channel('socialite')->error('No email in response', [$serviceUser]);

                    return response()->json([
                        'status' => 'Error',
                        'message' => 'Sorry, your account does not contain any email information. Email address is required',
                        'needEmail' => 1,
                    ], Response::HTTP_FORBIDDEN);
                }
            }

            // If we don't have user with such email, creating
            if (!$user) {
                $password = str_random(8);
                $user = $this->createUserFromService($serviceUser, $service, $password);
                $userService->verifyUser($user);
                event(new UserAccountRegistered($user->user_id, $gaId, $password, null, false));
            } else {
                // Check user is not currently banned
                $this->checkUser($user);

                // Update service id
                $user->update([$service . '_id' => $serviceUser->id]);

                // Restore user if it was soft deleted
                if ($user->trashed()) {
                    $user->restore();
                }
            }

            // Create session
            $session = $this->createSession($user, $gaId, $userIp);

            DB::commit();

            Log::channel('socialite')->info('Success', [$user, $session]);

            $user->referrer_bonus = $user->referrerBonus();

            return jsonResponse(Response::HTTP_OK, 'Success', 'User logged in', compact('user', 'session'));
        } catch (Exception $e) {
            DB::rollback();

            // Log errors
            report($e);
            Log::channel('socialite')->error('Error', [$e->getMessage()]);

            // Respond with error message
            return jsonResponse(Response::HTTP_INTERNAL_SERVER_ERROR, 'Error', 'Server Error', false, $e->getMessage());
        }
    }

    public function linkEmail(SocialiteLinkEmailRequest $request, UserService $userService): JsonResponse
    {
        $data = $request->validated();
        $service = array_get($data, 'service');

        // Get ga id
        preg_match('/.{3}\.\d\.([\d.]*)/mi', $request->get('ga_id'), $match);
        $gaId = last($match);

        try {
            DB::beginTransaction();

            $serviceUser = $this->getServiceUser($service, array_get($data, 'token'));

            // If we don't have user with such email, creating
            $password = str_random(8);
            $user = $this->createUserFromService($serviceUser, $service, $password, $data['email']);
            $userService->verifyUser($user);
            event(new UserAccountRegistered($user->user_id, $gaId, $password, null, false));

            // Create session
            $session = $this->createSession($user, $gaId, $request->ip());

            DB::commit();

            Log::channel('socialite')->info('Success', [$user, $session]);

            $user->referrer_bonus = $user->referrerBonus();

            return jsonResponse(Response::HTTP_OK, 'Success', 'User logged in', compact('user', 'session'));
        } catch (Exception $e) {
            // Roll back any DB changes
            DB::rollback();

            // Log errors
            report($e);
            Log::channel('socialite')->error('Error', [$e->getMessage()]);

            // Respond with error message
            return jsonResponse(
                Response::HTTP_INTERNAL_SERVER_ERROR,
                "Error",
                "Server Error",
                false,
                ['error' => $e->getMessage()]
            );
        }
    }

    private function getServiceUser($service, $token)
    {
        $serviceUser = Socialite::driver($service)->userFromToken($token);

        Log::channel('socialite')->info('Response', [$serviceUser]);

        if (!$serviceUser) {
            Log::channel('socialite')->error('No received data', [$serviceUser]);

            return response()->json([
                'status' => 'Error',
                'message' => "Data from service hasn't been received. Please try later",
            ], Response::HTTP_FORBIDDEN)->send();
        }

        return $serviceUser;
    }

    private function createUserFromService($serviceUser, $service, $password, $email = null): User
    {
        $username = $service[0] . substr($serviceUser->id, 0, 8);

        if (!$email) $email = $serviceUser->email;

        if ($email) {
            $username = slugify(strstr($email, '@', true));
        } elseif (property_exists($serviceUser, 'nickname') && $serviceUser->nickname) {
            $username = $serviceUser->nickname;
        }

        if (strlen($username) < 5) {
            $additionalSymbolsCount = 5 - strlen($username);

            for ($i = 0; $i < $additionalSymbolsCount; $i++) {
                $username .= rand(0, 9);
            }
        }

        $try = 0;

        while (User::where('username', $username)->withTrashed()->exists() && $try < 10) {
            $username = slugify($username) . rand(10, 99);
            $try++;
        }

        // Create from service user data
        $user = User::create([
            'user_hash' => uniqid('user'),
            'last_ip' => request()->ip(),
            'is_active' => 1,
            'username' => $username,
            'email' => $email,
            'password' => Hash::make($password),
            'email_verification_key' => md5(time() . $email),
            'email_verification_expiration' => now()->addHours(72),
            'email_verified' => 0,
            'thumbnail_url' => '',
            'banner_url' => '',
            'registration_type' => $service,
            $service . '_id' => $serviceUser->id,
        ]);

        if ($referrer = request()->input('referrer')) {
            ReferrerService::createBonusReward($user, $referrer);
        }

        // Set User`s preference
        $payload = match ((string)request()->input('so')) {
            'has_gay' => ['has_straight' => 0, 'has_gay' => 1, 'has_transgender' => 0],
            'has_transgender' => ['has_straight' => 0, 'has_gay' => 0, 'has_transgender' => 1],
            default => ['has_straight' => 1, 'has_gay' => 0, 'has_transgender' => 0],
        };

        if ($payload) {
            Preference::updateOrCreate(['user_id' => $user->user_id], $payload);
        }

        return $user;
    }

    private function checkUser($user)
    {
        if ($user->is_banned) {
            Log::channel('socialite')->error('Account banned', [$user]);

            return response()->json(['status' => 'Error', 'message' => 'Account has been banned.'], Response::HTTP_FORBIDDEN)->send();
        } elseif (!$user->is_active) {
            Log::channel('socialite')->error('Account inactive', [$user]);

            return response()->json(['status' => 'Error', 'message' => 'Account inactive.'], Response::HTTP_FORBIDDEN)->send();
        }
    }

    private function createSession(User $user, ?string $gaId = null, ?string $userIp = null): ?Session
    {
        $session = Session::query()
            ->where('user_id', $user->user_id)
            ->where('expires', '>=', date('Y-m-d H:i:s'))
            ->where('session_state', 'active')
            ->first();

        // Session data for updating
        $sessionData = [
            'ga_id' => $gaId,
            'user_id' => $user->user_id,
            'session_state' => 'active',
            'expires' => date('Y-m-d H:i:s', strtotime('+7 days')),
            'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? null,
        ];

        // Update or create session data
        if ($session) {
            // Update the session expiration
            $session->update($sessionData);
        } elseif (request()->header('sp-api-key')) {
            // If no active session, use the guest session we've setup on the request
            $session = Session::query()
                ->where('session_token', request()->header('sp-api-key'))
                ->first();
            $session->update($sessionData);
        } else {
            // Start new session if no active session is found
            $session = Session::create([
                'ip' => $userIp ?? request()->ip(),
                'session_token' => uniqid('session') . md5(rand() . request()->ip()),
                'start' => date('Y-m-d H:i:s'),
                'country_code' => ip_info('Visitor', 'Country Code')
            ] + $sessionData);
        }

        return $session;
    }
}
