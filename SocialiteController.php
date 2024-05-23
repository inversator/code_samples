<?php

namespace App\Http\Controllers\Frontend\Auth;

use App\Http\Controllers\FrontendController;
use Exception;
use Illuminate\Support\Facades\Log;
use Laravel\Socialite\Facades\Socialite;

class SocialiteController extends FrontendController
{
    /**
     * @param $service
     * @return \Illuminate\Http\RedirectResponse|\Symfony\Component\HttpFoundation\RedirectResponse
     */
    public function redirect($service)
    {
        if (!empty($_COOKIE[$service . '-access-token'])) {
            Log::channel('socialite')->info('Try ' . $service . ' token auth');

            try {
                $respData = $this->authByToken($service, $_COOKIE[$service . '-access-token']);
                $path = urldecode(request()->input('redirectUrl', '/'));

                if ($respData && $respData->status == 'Success') {

                    setcookie('sp-api-key', $respData->data->session->session_token, time() + 7 * 24 * 60 * 60, "/");
                    return redirect()->to($path);

                } elseif (property_exists($respData, 'needEmail')) {
                    // Need an email to complete auth/login
                    return redirect()->to($path ?? '/')
                        ->with('socialiteDemandsEmail', $_COOKIE[$service . '-access-token']);
                }

            } catch (Exception $e) {
                Log::channel('socialite')->error($e->getMessage());
            }
        }

        setcookie('socialite-redirect-url', request()->input('redirectUrl', '/'), time() + 7 * 24 * 60 * 60, '/');

        return Socialite::driver($service)->redirect();
    }

    /**
     * @param $service
     * @return \Illuminate\Http\RedirectResponse
     */
    public function callback($service)
    {
        try {
            $user = $service == 'google'
                ? Socialite::driver($service)->stateless()->user()
                : Socialite::driver($service)->user();

            // Save token
            if ($user->token)
                setcookie($service . '-access-token', $user->token, time() + 7 * 24 * 60 * 60, "/");

            $respData = $this->authByToken($service, $user->token);

            if ($respData->status == 'Success') {
                setcookie('sp-api-key', $respData->data->session->session_token, time() + 7 * 24 * 60 * 60, "/");

            } else {
                $popupMessage = $respData->message;

                Log::channel('socialite')->error($popupMessage);

                if (isset($_COOKIE['socialite-redirect-url'])) {
                    $path = urldecode($_COOKIE['socialite-redirect-url']);
                }

                // Need an email to complete auth/login
                if (property_exists($respData, 'needEmail'))
                    return redirect()->to($path ?? '/')->with('socialiteDemandsEmail', $user->token);

                return redirect()->to($path ?? '/')
                    ->with('popupMessageTitle', ucfirst($service) . ' authorization error')
                    ->with('popupMessage', $popupMessage);
            }
        } catch (Exception $e) {
            $popupMessage = $e->getMessage();

            Log::channel('socialite')->error('Socialite error', [$popupMessage]);
            report($e);

            if (isset($_COOKIE['socialite-redirect-url'])) {
                $path = urldecode($_COOKIE['socialite-redirect-url']);
            }

            return redirect()->to($path ?? '/')
                ->with('popupMessageTitle', ucfirst($service) . ' authorization error')
                ->with('popupMessage', 'Internal server error. Please try later');
        }

        if (isset($_COOKIE['socialite-redirect-url'])) {
            $path = urldecode($_COOKIE['socialite-redirect-url']);

            unset($_COOKIE['socialite-redirect-url']);
            setcookie('socialite-redirect-url', '', time() - 24 * 3600, '/');
        }

        return redirect()->to($path ?? '/');
    }

    /**
     * @param $service
     * @param $token
     * @param $tokenSecret
     * @return bool|mixed
     */
    protected function authByToken($service, $token, $tokenSecret = null)
    {
        $ga_id = $_COOKIE['_ga'] ?? '';

        $referrer = isReferrer() ? "&referrer=" . session()->get('referrer') : '';
        $userIp = request()->ip();

        $queryString = 'user_ip=' . $userIp . '&token=' . $token . '&service=' . $service . '&ga=' . $ga_id . $referrer;

        $resp = fetchFromAPI('/v2/web/auth/socialite?' . $queryString);
        $respData = is_string($resp) ? json_decode($resp) : $resp;

        Log::channel('socialite')->info('Socialite ' . $service . ' api response', [$respData->status, $respData->message]);

        return $respData;
    }

}
