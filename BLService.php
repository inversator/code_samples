<?php


namespace App\Services;


use App\Helpers\AuthSdk;
use App\Session;
use App\User;
use Illuminate\Support\Facades\Log;

class BLService
{
    public $bl;
    public $baseUrl = 'https://blacklabel.naiadsystems.com';

    public $response;
    public $data = [];

    public function __construct()
    {
        $this->bl = new AuthSdk(config('app.bl_key'), config('app.bl_secret'));
    }

    /**
     * Register client on streamate esf side
     *
     * @param User $user
     * @param Session $session
     * @param $params
     * @return array|string
     */
    public function createEsfUser(User $user, Session $session, $params = null)
    {
        // Restrict operations on test environments
        if (config('app.env') != 'production') return 'Unauthorized';

        $this->setData($user, $session);
        $username = $this->prepareNickname($user['username'] ?? $user['name']);

        $this->data += [
            'email' => $user['email'],
            'nickname' => $username,
            'country' => $params['country'] ?? $user['country_code'],
        ];

        $this->response = $this->bl->post($this->baseUrl . '/v2/user/createesfuser', $this->data);
        Log::channel('esf')->info('Create user', [$this->data, $this->response]);

        return $this->response;
    }

    /**
     * Call this endpoint to log in a previously created user.
     * The API will respond with SM_LOGGED_IN plus the user's sakey or provide an informative error
     *
     * @param User $user
     * @param Session|null $session
     * @return array|string
     */
    public function login(User $user, ?Session $session)
    {
        $this->setData($user, $session);

        $this->response = $this->bl->get($this->baseUrl . '/v2/user/login', $this->data);
        Log::channel('esf')->info('Login', [$this->data, $this->response]);

        return $this->response;
    }

    /**
     * Returns the nickname for a specific user.
     *
     * @param User $user
     * @param Session|null $session
     * @param array|null $params
     * @return array|string
     */
    public function profile(User $user, ?Session $session, ?array $params)
    {
        $this->setData($user, $session);

        $this->data += [
            'sakey' => $params['sakey'] ?? null
        ];

        $this->response = $this->bl->get($this->baseUrl . '/v1/user/profile', $this->data);
        Log::channel('esf')->info('Profile', [$this->data]);

        return $this->response;
    }

    /**
     * Changes the nickname for a specific user.
     *
     * @param User $user
     * @param Session $session
     * @param array $params
     * @return array|string
     */
    public function changeUsername(User $user, Session $session, array $params)
    {
        $this->setData($user, $session);

        $this->data += [
            'sakey' => $params['sakey'] ?? null,
            'nickname' => $params['username'] ?? null
        ];

        $this->response = $this->bl->post($this->baseUrl . '/v1/user/profile', $this->data);
        Log::channel('esf')->info('Change name', [$this->data]);

        return $this->response;
    }

    /**
     * Get live models list
     *
     * @param array $filters
     * @return array|string
     */
    public function getList(array $filters)
    {
        $this->data = $filters;

        $this->response = $this->bl->post($this->baseUrl . '/v3/search/get-list', $this->data, ['user-agent' => request()->server('HTTP_USER_AGENT', 'unknown')]);
        Log::channel('esf')->info('Get list', [$this->data, collect($this->response)->only(['status', 'requestkey'])->all()]);

        return $this->response;
    }

    /**
     * Get live model info
     *
     * @param $performerName
     * @return array|string
     */
    public function getPerformerData($performerName)
    {
        $this->data = ['performerName' => $performerName];

        $this->response = $this->bl->get($this->baseUrl . '/v1/performer/details', $this->data);
        Log::channel('esf')->info('Performer data', [$this->data, array_get($this->response, 'ResponseTime')]);

        return $this->response;
    }

    /**
     * Get live model photos
     *
     * @param $performerName
     * @return array|string
     */
    public function getPerformerAlbum($performerName)
    {
        $this->data = [
            'performerName' => $performerName,
            'albumid' => 0
        ];

        $this->response = $this->bl->get($this->baseUrl . '/v1/performer/album', $this->data);
        Log::channel('esf')->info('Performer album', [$this->data, array_get($this->response, 'ResponseTime')]);

        return $this->response;
    }

    /**
     * Get tags for gender
     *
     * @param array $filters
     * @return array|string
     */
    public function getTrandingTags(array $filters)
    {
        $this->data = $filters;

        $this->response = $this->bl->get($this->baseUrl . '/v3/search/trending-tags', $this->data);
        Log::channel('esf')->info('Tranding tags', [$this->data]);

        return $this->response;
    }

    /**
     * Get category filters for genders
     *
     * @param array $filters
     * @return array|string
     */
    public function getCategories(array $filters)
    {
        $this->data = $filters;

        $this->response = $this->bl->get($this->baseUrl . '/v1/search/categories', $this->data);
        Log::channel('esf')->info('Categories', [$this->data]);

        return $this->response;
    }

    /**
     * @return bool
     */
    public function requestFailed()
    {
        $status = array_get($this->response, 'status');
        return $status == 'SM_ERROR' || $status == 'SM_REQUIRES_SUPPORT' || $this->response == 'Unauthorized';
    }

    public function userNotFound()
    {
        return array_get($this->response, 'errors.0.error') == 'USER_NOT_FOUND';
    }

    public function getErrorLog($writeToLog = true)
    {
        if (is_string($this->response)) return $this->response;

        $message = array_get($this->response, 'errors.0.message', 'Cams error');

        $error = !empty($this->response['reason'])
            ? array_get($this->response, 'reason', 'Please contact support')
            : array_get($this->response, 'errors.0.error', 'Please contact support');

        $requestKey = array_get($this->response, 'requestkey', 'No key');

        if ($writeToLog)
            !is_string($message)
                ? Log::channel('esf')->info('Auth error: ' . $requestKey . ' — ' . $error, [$message])
                : Log::channel('esf')->info('Error', [$this->response]);

        return ucfirst(strtolower(str_replace('_', ' ', $error)));
    }

    public function setData(User $user, Session $session)
    {
        $additionalIp = config('app.env') == 'local' ? '89.250.165.81' : $session?->ip;

        $this->data = [
            'referrerId' => (int)config('app.bl_ref_id'),
            'clientUserId' => (string)$user['user_id'],

            'userIp' => request()->ip() ?? $additionalIp,
            'userAgent' => request()->server('HTTP_USER_AGENT') ?? $session?->user_agent ?? 'unknown'
        ];
    }

    public function getData()
    {
        return $this->data;
    }

    public function getSaKey()
    {
        return array_get($this->response, 'sakey');
    }

    public function getResponse()
    {
        return $this->response;
    }

    public function getStatus()
    {
        return array_get($this->response, 'status');
    }

    public function camsAccessAllowed(): bool
    {
        // Restrict operations on test environments
        if (config('app.env') != 'production') {
            if (currentUser('user_id')) {
                if (
                    !in_array(currentUser('user_id'), explode(',', config('webcam.test-ids')))
                    // && !currentUser('is_fake')
                )
                    return false;
            } else
                return false;
        }

        return true;
    }

    public function prepareNickname($username)
    {
        return str_replace(['_', '-', ' ', '.', 'kill', '666'], '', $username);
    }

    /**
     * Get stream statuses of models by ids
     *
     * @param array $performerIds
     * @return mixed
     */
    public function getStreamStatus(array $performerIds)
    {
        $this->data = [
            'performerIds' => implode(',', $performerIds)
        ];

        $this->response = $this->bl->get($this->baseUrl . '/v1/performer/streamStatus', $this->data);
        Log::channel('esf')->info('Get stream status', [$this->data]);

        return $this->response;
    }
}
