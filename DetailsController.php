<?php

namespace App\Http\Controllers\Frontend\Cam;

use App\BaseTransaction;
use App\CamsFollower;
use App\Helpers\AuthSdk;
use App\Http\Controllers\FrontendController;
use App\Services\BLService;
use App\Session;
use App\User;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redirect;
use Illuminate\View\View;
use Mcamara\LaravelLocalization\Facades\LaravelLocalization;
use Exception;
use App\Follower;

/**
 * Class DetailsController
 * @package App\Http\Controllers\Frontend\Cam
 */
class DetailsController extends FrontendController
{
    /**
     * @param $id
     * @return View
     */
    public function index($id)
    {
        $queryParams = '?' . http_build_query(request()->input());
        $liveOrientation = getLiveOrientation();

        // Redirect if has Capital chars
        if (preg_match('/[A-Z]/', $id))
            return Redirect::to($liveOrientation . '/' . strtolower($id) . $queryParams, 301);

        // Check for clearcache parameters
        checkCacheClear("live_cams_single_{$id}", [config('app.env'), 'live_cams']);

        // Get performer info
        $blModel = Cache::tags([config('app.env'), 'live_cams'])
            ->remember("live_cams_single_{$id}", 60 * 60 * 24, function () use ($id) {

                $active_bl_model = \BLService::getPerformerData($id);

                if (empty($active_bl_model) ||
                    !empty($active_bl_model['error']) ||
                    !empty($active_bl_model['errors']) ||
                    !isset($active_bl_model['Results'])) {

                    if (app()->bound('sentry')) {
                        app('sentry')->captureException(
                            new \Exception('Streamate api model error: ' . json_encode($active_bl_model))
                        );
                    }

                    return null;
                }

                return $active_bl_model;
            });

        if (empty($blModel) ||
            !empty($blModel['error']) ||
            !empty($blModel['errors']) ||
            !isset($blModel['Results'])) {

            return redirect(LaravelLocalization::localizeUrl($liveOrientation . $queryParams), 307)
                ->with('popupMessageTitle', 'Model offline')
                ->with('popupMessage', "Model {$id} currently offline");
        }

        // Model name
        $modelName = array_get($blModel, 'Results.About.Name');

        if (request()->has('dynamic')) {
            $dynamicCheckRes = \App\Services\Facades\BLService::getList(['size' => 1, 'query' => $id]);
            $dynamicStatus = array_get($dynamicCheckRes, 'Results.0.LiveStatus');

            if (!$dynamicStatus || $dynamicStatus === 'offline') {
                return redirect(
                    LaravelLocalization::localizeUrl($liveOrientation . '/dynamic'),
                    Response::HTTP_TEMPORARY_REDIRECT
                );
            }
        }

        $modelHistory = session()->get('live-model-history');

        if (!$modelHistory || last($modelHistory)['nickname'] !== $modelName) {

            session()->push('live-model-history', [
                'nickname' => $modelName,
                'dynamic' => str_contains(url()->previous(), 'dynamic') ? true : false
            ]);
        }

        $prefix = request()->route()->getPrefix();
        $gender = array_get($blModel, 'Results.About.Attributes.Gender');

        if (in_array($gender, [AuthSdk::FEMALE, AuthSdk::FEMALE_COUPLES, AuthSdk::COUPLES, AuthSdk::GROUP])
            && strpos($prefix, 'live') === false) {
            return Redirect::route('cams.show.has_straight', strtolower($id) . $queryParams, 301);
        } else if (in_array($gender, [AuthSdk::TRANSGENDERMAN, AuthSdk::TRANSGENDER]) && strpos($prefix, 'trans/live') === false) {
            return Redirect::route('cams.show.has_transgender', strtolower($id) . $queryParams, 301);
        } else if (in_array($gender, [AuthSdk::MALE, AuthSdk::MALE_COUPLES]) && strpos($prefix, 'gay/live') === false) {
            return Redirect::route('cams.show.has_gay', strtolower($id) . $queryParams, 301);
        }

        $page_data = [
            'active_bl_model' => $blModel,
            'page_title' => __('meta.cam-page-title', ['modelName' => $modelName]),
        ];

        // Set meta
        $page_data['meta'] = [
            "title" => $modelName . ' | ' . __('meta.live-creator') . ' | SinParty',
            "description" => __('meta.cam-description.' . getOrientationSlug(), ['modelName' => $modelName]),
            "canonical" => LaravelLocalization::localizeUrl(getLiveOrientation() . '/' . strtolower($modelName)),
            "robots" => "index,follow",
        ];

        // Get all (album 0) photos
        $page_data['photos'] = Cache::tags([config('app.env'), 'live_cams'])
            ->remember("live_cams_photos_{$id}", 60 * 60 * 24, function () use ($id) {

                $resp = \App\Services\Facades\BLService::getPerformerAlbum($id);

                return collect(array_get($resp, 'Results.Photos.PhotosData', []))->map(function ($item) {
                    return (object)$item;
                })->reverse();
            });

        if (array_get($blModel, 'Results.About.AboutMyShow')
            || array_get($blModel, 'Results.About.WhatTurnsMeOn')
            || array_get($blModel, 'Results.About.MyExpertise')) {
            $page_data['tabs'] = ['About the show', 'Model info'];
        } else {
            $page_data['tabs'] = ['Model info'];
        }

        if ($page_data['photos'] && count($page_data['photos'])) {
            array_unshift($page_data['tabs'], 'Photos');
        }

        // Set Menu and live cams for the page
        $page_data['active_main_menu'] = 'live';
        $page_data['hideDesktopHeaderBtns'] = true;
        $page_data['live_cams'] = \CamsService::getModelsGlobal(36, 12, 0);
        $page_data['sakey'] = request()->get('session')->black_label_sa_key ?? null;

        $user = User::find(currentUser()->user_id ?? 0);
        $page_data['balance'] = (float)$user?->getBalance();
        $page_data['user_id'] = $user?->user_id ?? 0;
        $page_data['user_fake'] = $user?->is_fake;

        if ($user) {
            $page_data['balance_topped_up'] = $user->isUserToppedUpBalance();
        }

        $page_data['modelFollowed'] = $user
            ? CamsFollower::where([
                'user_id' => $user->user_id,
                'cam_model_name' => $modelName])->exists()
            : false;
        $page_data['modelFollowerCount'] = CamsFollower::whereCamModelName($modelName)->count();

        // Check bl key
        if ($user &&
            (
                !request()->get('session')->black_label_sa_key
                || (request()->get('session')->bl_expires_at && request()->get('session')->bl_expires_at < now())
            )
        ) {
            $blAuthRes = $this->loginBlacklabel($user);

            if (!empty($blAuthRes['success']) && $blAuthRes['success']) {
                $page_data['track'] = $blAuthRes['track'];
                $page_data['sakey'] = $blAuthRes['sakey'];

                Log::channel('live-cams-slack')
                    ->info($user->user_id . ' logged in (' . config('app.env') . ')');
            } else {
                Log::channel('live-cams-slack')
                    ->error($user->user_id . ' login error (' . config('app.env') . ')', [$blAuthRes]);

                if (!empty($blAuthRes['error']) && 'Invalid parameters' == $blAuthRes['error']) {
                    $blAuthRes['error'] = __('frontend/pages-dynamic/livecams.invalid-params');
                }

                $page_data['authError'] = $blAuthRes;
            }
        } elseif ($user && empty($_COOKIE['cam-name-checked'])) {

            // Check if the username matches the black label nickname
            if (config('webcam.name-check')) {
                $session = Session::where("session_token", request()->get('session')->session_token)->first();
                $this->checkUserData($user, $session, $page_data['sakey']);
                setcookie('cam-name-checked', 1, time() + 60 * 60, '/');
            }
        }

        // Enable embedding DSP
        $page_data['embed_DSP'] = isEmbedDSP();

        if ($page_data['embed_DSP']) {
            $page_data += $this->advertisment();
        }

        // Associated Sinparty Creator user_id
        $creatorId = request()->get('creator_id') ?? 0;
        $page_data['creatorId'] = $creatorId;
        
        // Check if current user is follower
        $userIsCreatorFollower = false;
        if ($creatorId && currentUser('user_id')) {
            $userIsCreatorFollower = Follower::query()
                ->where('user_id', currentUser('user_id'))
                ->where('creator_id', $creatorId)
                ->exists();
        }
        $page_data['userIsCreatorFollower'] = $userIsCreatorFollower;

        return view('frontend.pages-dynamic.livecams.single-vue', $page_data);
    }


    /**
     * Login to streamate user account or create new one
     *
     * @param User $user
     * @return array
     * @throws \Psr\Container\ContainerExceptionInterface
     * @throws \Psr\Container\NotFoundExceptionInterface
     */
    private function loginBlacklabel(User $user)
    {
        $blService = new BLService();
        $track_this = 0;

        try {
            // Restrict testing environment except for test users
            if (!\BLService::camsAccessAllowed())
                return [
                    "message" => "Access restricted",
                    'error' => "For test environment, only for test users",
                    'track' => false
                ];


            $session = Session::where("session_token", request()->get('session')->session_token)->first();

            // Try login
            $blService->login($user, $session);

            // User not logged in
            if ($blService->requestFailed()) {
                // If user not found
                if ($blService->userNotFound()) {

                    // Trying to create user
                    $blService->createEsfUser($user, $session, [
                        'country' => array_get(session()->get('location'), 'countryIsoCode')
                    ]);

                    // If user creation failed
                    if ($blService->requestFailed()) {
                        $this->logToSentry($blService->getErrorLog());

                        return [
                            'message' => 'Cams authorization error',
                            'error' => $blService->getErrorLog(),
                            'track' => $track_this
                        ];

                        // User created, try to log in
                    } else {
                        $blService->login($user, $session);

                        // Track registration
                        $track_this = $this->trackRegistration($user, $blService->getSaKey(), $blService->getStatus());

                        // Login failed after user created
                        if ($blService->requestFailed()) {
                            $error = $blService->getErrorLog();
                            $this->logToSentry($error);

                            return [
                                'message' => 'Cams authorization error',
                                'error' => $error,
                                'track' => $track_this
                            ];
                        }
                    }
                    // If request to login failed
                } else {
                    $error = $blService->getErrorLog();
                    $this->logToSentry($error);

                    return [
                        'message' => 'Cams authorization error',
                        'error' => $error,
                        'track' => $track_this
                    ];
                }
            }

            // Update the session sakey
            $sakey = $blService->getSaKey();

            $session->update([
                'black_label_sa_key' => $sakey,
                'bl_expires_at' => now()->addDay()
            ]);

            $responseData = [
                'success' => 1,
                'response' => $blService->getResponse(),
                'sakey' => $sakey,
                'track' => $track_this ?? 0
            ];

            // Check if the username matches the black label nickname
            if (config('webcam.name-check'))
                $this->checkUserData($user, $session, $sakey);

            return $responseData;

        } catch (Exception $e) {
            // Log errors
            report($e);

            return [
                'message' => 'Cams authorization error',
                'error' => $e->getMessage(),
                'track' => $track_this,
                'success' => 0
            ];
        }
    }

    /**
     * Log error to sentry
     *
     * @param $errorMessage
     */
    private function logToSentry($errorMessage)
    {
        if (app()->bound('sentry')) {
            app('sentry')->captureException(
                new \Exception('OFFLINE: Login blacklabel error — ' . $errorMessage)
            );
        }
    }

    /**
     * Mark user as interacted with streamate
     *
     * @return int
     */
    private function trackRegistration(User $user, $saKey, $status)
    {
        if (!$user->blacklabel_joined_at && ($saKey && $status == 'SM_LOGGED_IN')) {

            $user->blacklabel_joined_at = now();
            $user->save();

            // if ($user->vl_cid)
            //     sendVoluumStats(['cid' => $user->vl_cid, 'et' => 'camsignup']);

            return 1;
        }

        return 0;
    }

    /**
     * Update user data if it doesn't match streamate account
     *
     * @param $user
     * @param $session
     * @param $sakey
     * @return void
     */
    private function checkUserData($user, $session, $sakey)
    {
        $blService = new BLService();
        $blService->profile($user, $session, ['sakey' => $sakey]);

        if (!$blService->requestFailed()) {
            $currentUsername = $blService->prepareNickname($user['username'] ?? $user['name']);
            $blNickname = array_get($blService->getResponse(), 'profileData.nickname');

            if ($currentUsername != $blNickname) {
                $blService->changeUsername($user, $session, ['sakey' => $sakey, 'username' => $currentUsername]);
            }
        }
    }


    /**
     * @return array
     */
    private function advertisment()
    {
        extract(dspAdverts());

        return [
            'cam_top_block' => [$adv_300x250, $adv_300x250, $adv_300x250, $adv_300x250],
            'cam_side_block' => [$adv_300x250, $adv_300x250],
            'cam_middle_block' => [$adv_300x250, $adv_300x250],
            'cam_bottom_block' => [$adv_900x250, $adv_300x250],
            'cam_mobile_block' => [$adv_300x100, $adv_300x250],
        ];
    }

    public function addFavorite(Request $request)
    {
        $userId = $request->get('user_id');

        $camModelName = $request->get('cam_model_name');
        $camModelId = $request->get('cam_model_id');

        $camModelCountry = $request->get('cam_model_country');
        $camModelGender = $request->get('cam_model_gender');
        $camModelAge = $request->get('cam_model_age');
        $camModelStars = $request->get('cam_model_stars');

        if ($userId && $camModelName) {
            try {
                $entry = CamsFollower::where([
                    'user_id' => $userId,
                    'cam_model_name' => $camModelName,
                ])->first();

                if ($entry && !$entry->cam_model_id) {
                    $entry->update([
                        'cam_model_id' => $camModelId,
                        'cam_model_country' => $camModelCountry,
                        'cam_model_gender' => $camModelGender,
                        'cam_model_age' => $camModelAge,
                        'cam_model_stars' => $camModelStars,
                    ]);
                }

                if (!$entry) {
                    CamsFollower::create([
                        'user_id' => $userId,
                        'cam_model_name' => $camModelName,

                        'cam_model_id' => $camModelId,
                        'cam_model_country' => $camModelCountry,
                        'cam_model_gender' => $camModelGender,
                        'cam_model_age' => $camModelAge,
                        'cam_model_stars' => $camModelStars,
                    ]);
                }
            } catch (Exception $e) {
                return response()->json([
                    'status' => 'Error',
                    'Message' => $e->getMessage()
                ], Response::HTTP_UNPROCESSABLE_ENTITY);
            }

            return response()->json(['status' => 'Success'], Response::HTTP_OK);
        }

        return response()->json([
            'status' => 'Error',
            'Message' => 'Wrong data'
        ], Response::HTTP_UNPROCESSABLE_ENTITY);
    }

    public function removeFavorite(Request $request)
    {
        $userId = $request->get('user_id');
        $camModelName = $request->get('cam_model_name');
        $camModelId = $request->get('cam_model_id');

        if ($userId) {
            try {
                if ($camModelId) {
                    $entry = CamsFollower::where([
                        'user_id' => $userId,
                        'cam_model_id' => $camModelId,
                    ])->first();
                }

                if (!$entry && $camModelName) {
                    $entry = CamsFollower::where([
                        'user_id' => $userId,
                        'cam_model_name' => $camModelName,
                    ])->first();
                }

                if (!$entry) {
                    throw new Exception('No entry');
                }

                $entry->delete();
            } catch (Exception $e) {
                return response()->json([
                    'status' => 'Error',
                    'Message' => $e->getMessage()
                ], Response::HTTP_UNPROCESSABLE_ENTITY);
            }

            return response()->json(['status' => 'Success'], Response::HTTP_OK);
        }

        return response()->json([
            'status' => 'Error',
            'Message' => 'Wrong data'
        ], Response::HTTP_UNPROCESSABLE_ENTITY);
    }
}
