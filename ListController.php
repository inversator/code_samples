<?php

namespace App\Http\Controllers\Frontend\Cam;

use App\CamsFollower;
use App\Http\Controllers\FrontendController;
use App\Services\CamsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use App\Services\Facades\BLService;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;
use Mcamara\LaravelLocalization\Facades\LaravelLocalization;
use Exception;

/**
 * Class ListController
 * @package App\Http\Controllers\Frontend\Cam
 */
class ListController extends FrontendController
{
    /**
     * @param bool $searchQuery
     * @return \Illuminate\Contracts\Foundation\Application|\Illuminate\Contracts\View\Factory|View
     */
    public function index($broadcaster = null): View|RedirectResponse
    {
        $params = $this->getBroadcasterUrl($broadcaster);

        try {
            $models = $this->getModels($params);
        } catch (Exception $ex) {
            // If we have Streamate server error
            return redirect('/', Response::HTTP_TEMPORARY_REDIRECT)
                ->with('popupMessageTitle', 'Live cams service is not available')
                ->with('popupMessage', 'Please try later');
        }

        // Collect data for page
        $pageData = $this->preparePageData();

        // Add Models Data
        $pageData += $models;

        session()->forget('live-model-history');

        return view('frontend.pages-dynamic.livecams.list-vue', $pageData);
    }

    /**
     * @return RedirectResponse
     */
    public function random(): RedirectResponse
    {
        $cams = \CamsService::getModelsGlobal(1, false, rand(1, 99));
        $nickname = slugify($cams[0]->Nickname);

        return redirect()->route('cams.show.' . request()->get('session_orientation'), $nickname);
    }

    /**
     * Take cam model from list depending on users preference
     *
     * @return \Illuminate\Contracts\Foundation\Application|RedirectResponse|\Illuminate\Routing\Redirector
     * @throws \Psr\Container\ContainerExceptionInterface
     * @throws \Psr\Container\NotFoundExceptionInterface
     */
    public function dynamicSelect(): RedirectResponse
    {
        $orientation = request()->get('session_orientation');
        $modelList = config('webcam.dynamic.' . $orientation);

        $camsService = new CamsService();
        $genders = $camsService->setGenders()->getAllowedGenders();

        $modelCollection = is_array($modelList) ? collect($modelList) : collect(explode(',', $modelList));
        $models = $modelCollection->random(min($modelCollection->count(), 50));

        // Find online models
        $params = [
            'query' => $models->implode(','),
            'filters' => "showtype:inpartychat;online:true;gender:" . implode(',', $genders) . ";"
        ];

        $blResp = BLService::getList($params);
dd($blResp);
        $nickname = count($blResp['Results'])
            ? array_get($blResp, 'Results.' . array_rand($blResp['Results']) . '.Nickname')
            : null;

        if (empty($nickname)) {
            return redirect(LaravelLocalization::localizeUrl(getLiveOrientation()), Response::HTTP_TEMPORARY_REDIRECT);
        }

        // If online model found — redirect to them, if not — redirect to last one
        $params = request()->input();
        $url = LaravelLocalization::localizeUrl(getLiveOrientation()) . '/' . $nickname . '?' . http_build_query($params);

        return redirect($url, Response::HTTP_TEMPORARY_REDIRECT);
    }

    /**
     * @return array
     */
    private function advertisment(): array
    {
        extract(dspAdverts());

        return [
            'cams_in_grid_block_1' => [$adv_300x250, $adv_300x250, $adv_300x250],
            'cams_in_grid_block_2' => [$adv_300x250, $adv_728x90, $adv_728x90],
            'cams_in_grid_block_3' => [$adv_468x60, $adv_468x60],
            'cams_in_grid_block_4' => [$adv_300x250, $adv_728x90, $adv_728x90],
            'cams_side_block' => [$adv_160x600, $adv_160x600, $adv_160x600],
            'cams_bottom_block' => [$adv_900x250, $adv_300x250],
            'cams_mobile_block' => [$adv_300x100, $adv_300x250],
        ];
    }

    /**
     * Prepare data for page
     *
     * @return array
     */
    private function preparePageData(): array
    {
        $orientation = getOrientationSlug();
        $robots = request()->all() ? 'noindex,follow' : 'index,follow';
        $canonical = request()->url() . '?' . request()->getQueryString();

        $pageData = [
            'page_title' => __('banner.title'),
            'description' => __('banner.description.' . $orientation),
            'meta' => [
                'title' => __('meta.live-adult-webcams') . ' | ' . __('meta.cam-list-title.' . $orientation) . ' | SinParty',
                'description' => __('meta.cam-list-description.' . $orientation),
                'canonical' => $canonical,
                'robots' => $robots,
            ],

            'showCountry' => request()->has('country'),
            'trending_countries' => config('webcam.countries'),
            'followers' => $this->getFollowers(),

            'active_main_menu' => 'lives',
            'hideDesktopHeaderBtns' => true,
            'hideDesktopHeaderSearch' => true,

            'promocode_banner' => null,
            'copyCreation' => isStraight() ? __('creation.lives') : false,
            'embed_DSP' => isEmbedDSP() // Enable embedding DSP
        ];

        if ($pageData['embed_DSP']) {
            $pageData += $this->advertisment();
        }

        // *** Close promo-banner during SIN-3074
        // Get promoCode banner settings
        // try {
        //     $promoBanner = apiWebRequest('/v2/web/promo-banner')?->data;

        //     $alreadyUsedUrl = '/v2/web/payments/check-promotion-code-usage?code=' . $promoBanner?->promoCode;
        //     $isCodeAlreadyUsed = apiWebRequest($alreadyUsedUrl);

        //     $pageData['promocode_banner'] = $isCodeAlreadyUsed ? null : $promoBanner;
        // } catch (Exception $e) {
        //     Log::error($e->getMessage());
        //     $pageData['promocode_banner'] = null;
        // }

        return $pageData;
    }

    /**
     * Get Models Data for page
     *
     * @return array
     */
    private function getModels($params = null): null|array
    {
        $orientation = request()->get('session_orientation') ?? request()->get('so');

        $resultSource = "/v2/web/live-cams/list?" . ($params ?? ("so=" . $orientation . '&' . request()->getQueryString()));
        $result = apiWebRequest($resultSource)->data;
        $result->source = $resultSource;

        if ($result->total == 0) {
            $resultSource = "/v2/web/live-cams/list?so=" . $orientation;
            $noData = apiWebRequest($resultSource)->data;
            $noData->source = $resultSource;
        } else {
            $noData = null;
        }

        if (!$result && !$noData) return null;

        return [
            'content' => ['result' => $result],
            'noData' => ['result' => $noData],
        ];
    }

    /**
     * Get Broadcaster URL
     *
     * @return string
     */
    private function getBroadcasterUrl($code = null): string|null
    {
        try {
            if ($code || request()->has('attribution')) {
                $broadcaster = apiWebRequest("/v2/web/live-cams/list/broadcaster?code=" . $code . '&attribution=' . request()->get('attribution'))->data;
                $broadcaster->params->so = $broadcaster->orientation;
                return http_build_query($broadcaster->params);
            }
            return null;
        } catch (Exception $e) {
            return null;
        }
    }

    /**
     * Get user's live followers
     *
     * @return array
     */
    private function getFollowers(): array
    {
        $result = [];

        if (currentUser()) {
            try {
                $request = CamsFollower::where('user_id', currentUser('user_id'));

                if ($request->exists()) {
                    $camFollowings = $request->select(['cam_model_name', 'cam_model_id'])->get();

                    $modelsWithId = $camFollowings->where('cam_model_id', '>', 0);
                    $modelsWithoutId = $camFollowings->where('cam_model_id', 0);

                    // Get models by Ids
                    if ($modelsWithId->count()) {
                        $blResWithId = BLService::getStreamStatus($modelsWithId->pluck('cam_model_id')->all());

                        $result += array_map(function ($model) {
                            $id = array_get($model, 'performerid');
                            return [
                                'PerformerId' => $id,
                                'Nickname' => array_get($model, 'nickname'),
                                'LiveStatus' => array_get($model, 'Status.Online') ? 'live' : 'offline',
                                'Thumbnail' => 'https://m2.nsimg.net/biopic/320x240/' . $id
                            ];
                        }, $blResWithId);
                    }

                    // Find models by nicknames
                    if ($modelsWithoutId) {
                        $modelNicknames = $modelsWithoutId->pluck('cam_model_name');
                        $blResWithoutId = BLService::getList(['query' => $modelNicknames->implode(',')]);
                        $foundModels = array_get($blResWithoutId, 'Results');

                        if ($foundModels) {
                            $filteredModels = array_filter($foundModels, fn($model) => in_array($model['Nickname'], $modelNicknames->all()));
                            $result += array_map(function ($model) {
                                return [
                                    'PerformerId' => array_get($model, 'PerformerId'),
                                    'Nickname' => array_get($model, 'Nickname'),
                                    'LiveStatus' => array_get($model, 'LiveStatus'),
                                    'Thumbnail' => array_get($model, 'Thumbnail'),
                                ];
                            }, $filteredModels);
                        }
                    }

                    usort($result, function ($a, $b) {
                        return strcmp($a['LiveStatus'], $b['LiveStatus']);
                    });
                }
            } catch (Exception $e) {
                return [];
            }
        }

        return array_values($result);
    }

    /**
     * Get user back to previous cam model
     */
    public function previousModel()
    {
        $history = session()->get('live-model-history');

        $url = getLiveOrientation();

        if ($history && count($history) > 1) {
            array_pop($history);
            $lastModel = array_pop($history);

            session()->put('live-model-history', $history);

            $url .= '/' . strtolower($lastModel['nickname']);

            if ($lastModel['dynamic']) {
                $url .= '?dynamic=1';
            }
        }

        return redirect(LaravelLocalization::localizeUrl($url), Response::HTTP_TEMPORARY_REDIRECT);
    }

    public function getHero(): JsonResponse
    {
        return response()->json(\CamsService::setGenders()->getHero());
    }
}
