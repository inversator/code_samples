<?php

namespace App\Services;

use App\CamsCategory;
use App\Helpers\AuthSdk;
use App\InternalTransaction;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class CamsService
{
    public $genderPref;
    public $page = 1;
    public $offset = 40;
    public $gender = AuthSdk::FEMALE;
    public $allowedGenders = [AuthSdk::FEMALE, AuthSdk::FEMALE_COUPLES, AuthSdk::COUPLES, AuthSdk::GROUP];
    public $forbiddenGender = [];

    public $searchQuery = '';
    public $filterName = '';
    public $filter = [];
    public $groupFilters = [];
    public $blService;

    public $so;
    public $location;

    const MAX_HOMEPAGE_CATS = 10;

    public function __construct()
    {
        $this->so = request()->get('session_orientation');
        $this->location = session()->get('location');

        $this->blService = new BLService();
    }

    public function setSearchQuery($searchQuery)
    {
        $this->searchQuery = $searchQuery;
    }

    public function setFilterName($setFilterName)
    {
        $this->filterName = $setFilterName;
    }

    public function getList(?array $filters = [])
    {
        $streamateDownAware = Cache::tags([config('app.env'), 'global_cams'])->get('global_cam_availability');

        $bl_models = $this->blService->getList($filters ?? $this->filter);

        if (!is_array($bl_models)) {
            $streamateError = 'Streamate api server error: ' . json_encode($bl_models);

            // If error happens for the first time in period
            if (!$streamateDownAware || array_get($streamateDownAware, 'available')) {

                // Send slack warning
                if (empty($streamateDownAware['slack-sent']) && config('app.env') == 'production') {
                    Log::channel('live-cams-slack')->emergency('Streamate not responding (' . config('app.env') . ') front');
                }

                if (app()->bound('sentry')) {
                    app('sentry')->captureException(new \Exception($streamateError));
                }

                Cache::tags([config('app.env'), 'global_cams'])->put('global_cam_availability', [
                    'available' => 0,
                    'slack-sent' => 1
                ], 60 * 5);

                // Try to get data again
                sleep(2);
                $bl_models = $this->blService->getList($filters ?? $this->filter);
                Log::channel('live-cams-slack')->info("Get list 2 try (" . config('app.env') . ")", [$bl_models]);
            }
        } else {
            // Send notification about streamate availability if it was down
            if ($streamateDownAware
                && !array_get($streamateDownAware, 'available')
                && !empty($streamateDownAware['slack-sent'])) {

                Log::channel('live-cams-slack')->info('Streamate is OK (' . config('app.env') . ')');
                Cache::tags([config('app.env'), 'global_cams'])->forget('global_cam_availability');
            }
        }

        return $bl_models;
    }

    public function setGenders()
    {
        // Set the main gender by current section
        switch (getOrientationSlug()) {
            case 'trans':
                if ($trans_orientation = request()->get('set_trans_orientation')) {
                    if (in_array($trans_orientation, [AuthSdk::TRANSGENDER, AuthSdk::TRANSGENDERMAN])) {
                        request()->session()->put('trans_orientation', $trans_orientation);
                    }
                }

                $this->gender = AuthSdk::TRANSGENDER;
                $this->allowedGenders = [AuthSdk::TRANSGENDER, AuthSdk::TRANSGENDERMAN, AuthSdk::GROUP];
                break;

            case 'gay':
                $this->gender = AuthSdk::MALE;
                $this->allowedGenders = [AuthSdk::MALE, AuthSdk::MALE_COUPLES, AuthSdk::GROUP];
                break;
        }

        $this->genderPref = request('gender_pref', $this->gender);

        // Gender chosen by user from another section
        $this->forbiddenGender = collect(config('webcam.genders'))->except($this->allowedGenders)?->keys()?->all();

        return $this;
    }

    public function getHomeSliderCategories()
    {
        $categories = CamsCategory::select([
            'cams_category_id',
            'slug',
            'is_featured',
            'orientation',
            'title',
            'thumbnail_url',
            'count as creators_count',
            'order',
            'cams_category_id as category_id'
        ])
            ->where('orientation', $this->so)
            ->where('order', '>', 0)
            ->whereNotNull('order')
            ->orderBy('order')
            ->limit(self::MAX_HOMEPAGE_CATS)
            ->get();

        return $categories;
    }

    /**
     * Cam models for site page sliders
     *
     * @param int $slice_amount
     * @param bool $chunk
     * @param int $slice_start
     * @param int $page
     * @param int $per_page
     */
    public function getModelsGlobal($slice_amount = 100, $chunk = false, $slice_start = 0, $page = 0, $per_page = 120)
    {
        $cacheKey = 'global_cam_listing_content' . md5($page . $per_page . $this->so . request()->get('age'));
        $cacheTags = [config('app.env'), 'global_cams'];
        $cacheTime = config('app.env') != 'production' ? 8 * 60 * 60 : config('webcam.cache-time', 360);

        $output = Cache::tags($cacheTags)->get($cacheKey);

        // Check if menu content is in cache and fetch it else, generate and store in cache
        if (!$output) {
            $gender = match (request()->get('session_orientation')) {
                'has_gay' => AuthSdk::MALE . ',' . AuthSdk::MALE_COUPLES,
                'has_transgender' => AuthSdk::TRANSGENDER,
                default => AuthSdk::FEMALE . ',' . AuthSdk::FEMALE_COUPLES
            };

            $orientation = match (request()->get('session_orientation')) {
                'has_gay' => 'gay',
                'has_transgender' => 'gay,bi',
                default => 'straight'
            };

            $filters = "gender:{$gender};sexpref:{$orientation}";

            $filters .= request()->get('age') ? ";age:" . request()->get('age') : ";age:18-39";

            // Get live cams
            $bl_models = $this->getList([
                'page_number' => $page,
                'results_per_page' => $per_page,
                'filters' => $filters,
            ]);

            if (isset($bl_models['Results'])) {
                $output = json_decode(json_encode($bl_models['Results']));
                Cache::tags($cacheTags)->put($cacheKey, $output, $cacheTime);
            } else {
                $output = json_decode(json_encode([]));
            }
        }

        if (request('id')) {
            $output = collect($output)->map(function ($item) {
                if (strtolower($item->Nickname) != request('id'))
                    return $item;
            })->all();

            if (!$output) $output = [];
        }

        $online = collect($output)->map(function ($item) {
            if ($item && $item->LiveStatus == 'live') return $item;
        })->filter();

        if (($slice_amount + $slice_amount) > $online->count())
            $output = array_slice(array_filter($output ?? []), $slice_start, $slice_amount) ?? json_decode(json_encode([]));
        else
            $output = array_slice(array_filter($online->all() ?? []), $slice_start, $slice_amount) ?? json_decode(json_encode([]));

        if ($chunk) {
            return array_chunk($output, $chunk);
        }

        return $output;
    }

    /**
     * Cam models for home page sliders
     *
     * @param int $countModels
     */
    public function getModelsDynamic($countModels = 30)
    {
        $orientation = request()->get('session_orientation');
        $modelList = config('webcam.dynamic.' . $orientation);
        $genders = $this->setGenders()->getAllowedGenders();

        $params = [
            'query' => is_array($modelList) ? implode(',', $modelList) : $modelList,
            'filters' => "showtype:inpartychat;online:true;gender:" . implode(',', $genders) . ";"
        ];
        $blResp = $this->blService->getList($params);

        $result = $blResp['Results'];

        if ($leftCount = $countModels - count($result)) {
            $result += $this->getModelsGlobal($leftCount);
        }

        return $result;
    }

    public function getGender()
    {
        return $this->gender;
    }

    public function getForbiddenGenders()
    {
        return $this->forbiddenGender;
    }

    public function getAllowedGenders()
    {
        return $this->allowedGenders;
    }

    /**
     * Get categories to use as list of categories in filters on cams list page
     *
     * @param array $filter
     * @param array|null $priorityArray
     * @return array
     */
    public function getFilterCategories(array $filter, ?array $priorityArray = null)
    {
        $orientation = $this->so;

        $bl_categories = cache()
            ->tags([config('app.env'), 'global_cams'])
            ->remember('global_cam_list_categories', 60 * 60,
                function () use ($filter) {
                    return $this->blService->getCategories($filter);
                });

        // Modify category list for gender tf2m
        if (is_array($this->genderPref) && isset($bl_categories['tf2m'])
            && count($this->genderPref) == 1
            && in_array('tf2m', $this->genderPref)) {

            $filterCategories = collect($bl_categories['tf2m'])->unique('Name')->all();
        } else if (isset($bl_categories[$this->gender]) && is_array($bl_categories[$this->gender])) {

            $filterCategories = collect($bl_categories)
                ->only($this->getAllowedGenders())
                ->flatten(1)
                ->unique('Name')
                ->map(function ($item) use ($orientation) {
                    if ($orientation == 'has_gay' && str_starts_with($item['Name'], 'guy'))
                        $item['editedName'] = str_replace(['guys', 'guy'], '', $item['Name']);
                    else if ($orientation == 'has_transgender' && str_starts_with($item['Name'], 'ts'))
                        $item['editedName'] = str_replace(['ts'], '', $item['Name']);
                    else
                        $item['editedName'] = $item['Name'];

                    return $item;
                })->sortBy('Name')
                ->all();
        }

        if ($priorityArray) {
            usort($filterCategories, function ($a, $b) use ($priorityArray) {
                $aInPriority = in_array($a['Name'], $priorityArray);
                $bInPriority = in_array($b['Name'], $priorityArray);

                if ($aInPriority && $bInPriority) {
                    return array_search($a['Name'], $priorityArray) - array_search($b['Name'], $priorityArray);
                } else if ($aInPriority) {
                    return -1;
                } else if ($bInPriority) {
                    return 1;
                } else {
                    return $a['Name'] <=> $b['Name'];
                }
            });
        }

        return $filterCategories;
    }

    /**
     * Get cams tags for cams list page
     *
     * @param array $filter
     * @return array
     */
    public function getTrendingTags(array $filter)
    {
        $tags = cache()
            ->tags([config('app.env'), 'global_cams'])
            ->remember('global_cam_tranding_tags' . md5($this->so), 60 * 60,
                function () use ($filter) {
                    $tagsArray = $this->blService->getTrandingTags($filter);
                    return array_get($tagsArray, 'trending');
                });

        return $tags ?? [];
    }

    /**
     * Get popular cam models among the site's users
     *
     * @return array
     */
    public function getPopularModels()
    {
        $performerNicknames = InternalTransaction::where('source', 'streamate')
            ->whereNotNull('data->performer_nickname')
            ->where('status', 2)
            ->where('created_at', '>=', now()->subDays(365))
            ->selectRaw("JSON_UNQUOTE(JSON_EXTRACT(data, '$.performer_nickname')) as performer_nickname, COUNT(*) as count")
            ->groupBy('performer_nickname')
            ->orderByDesc('count')
            ->pluck('performer_nickname');

        $response = BLService::getList(['query' => $performerNicknames->implode(',')]);

        if (BLService::requestFailed()) {
            return [];
        }

        $models = array_filter($response['Results'], fn($model) => in_array($model['Nickname'], $performerNicknames->all()));

        return array_values($models);
    }

    public function getTrendingModels($filters)
    {
        $page = $filters['page_number'] > 1 ? 1 : 2;

        if (!empty(array_intersect(array_keys($filters), ['category', 'tag', 'age', 'country', 'lang']))) {
            $page = 1;
        }

        $filtersData = [
            'page_number' => $page,
            'results_per_page' => $filters['results_per_page'],
            'new_models' => $filters['new_models'],
            'language' => 'en',
            'filters' => 'gender:' . $this->gender
        ];

        $blModels = $this->getList($filtersData);

        return array_get($blModels, 'Results')
            ? array_slice(array_get($blModels, 'Results'), 0, 15)
            : [];
    }

    /**
     * Get cam hero from dynamic (manually selected) models with stream hls url
     *
     * @return array
     * @throws \Psr\Container\ContainerExceptionInterface
     * @throws \Psr\Container\NotFoundExceptionInterface
     */
    public function getHero(): array
    {
        $streamUrl = 'https://manifest-server.naiadsystems.com/live';
        $orientation = request()->get('session_orientation');
        $currentModel = request()->get('modelName');

        $modelList = config('webcam.dynamic.' . $orientation);

        // Exclude current model from selected list
        if ($currentModel) {
            $modelArray = is_string($modelList) ? explode(',', $modelList) : $modelList;
            $query = collect($modelArray)->reject(fn($v) => $v === strtolower($currentModel))->implode(',');
        } else {
            $query = is_array($modelList) ? implode(',', $modelList) : $modelList;
        }

        $genders = $this->setGenders()->getAllowedGenders();

        $params = [
            'query' => $query,
            'filters' => "showtype:inpartychat;online:true;gender:" . implode(',', $genders) . ";"
        ];

        $blResp = \BLService::getList($params);
        $camModelTranslation = null;

        if (is_array($blResp) && count($blResp['Results'])) {
            $models = $blResp['Results'];
            shuffle($models);

            foreach ($models as $blDynamicModel) {
                $modelName = $blDynamicModel['Nickname'];
                $camModelTranslation = @file_get_contents($streamUrl . '/s:' . $modelName . '.json?');

                if ($camModelTranslation) {
                    break;
                }
            }
        }

        if (!$camModelTranslation) {
            return $this->heroFromGeneral($genders);
        }

        $data = [
            'nickname' => $modelName,
            'link' => getLiveOrientation() . '/' . strtolower($modelName),
            'streamUrl' => $camModelTranslation
                ? array_get(json_decode($camModelTranslation, TRUE), 'formats.mp4-hls.manifest')
                : null,
            'type' => 'dynamic',
        ];

        return $data;
    }

    /**
     * Get stream hls data for hero from general list
     * @param $genders
     * @return array
     */
    public function heroFromGeneral($genders = 'f,ff'): array
    {
        $params = [
            'size' => 30,
            'filters' => "showtype:inpartychat;online:true;gender:" . implode(',', $genders) . ";"
        ];

        try {
            $blResp = \BLService::getList($params);

            // If no results in response
            if (empty($blResp['Results']) || !count($blResp['Results'])) {
                return [];
            }

            $modelName = array_get($blResp, 'Results.' . array_rand($blResp['Results']) . '.Nickname');

            $camModelTranslation = file_get_contents('https://manifest-server.naiadsystems.com/live/s:' . $modelName . '.json?');
        } catch (\Exception $e) {
            return [];
        }

        return [
            'nickname' => $modelName,
            'link' => getLiveOrientation() . '/' . strtolower($modelName),
            'streamUrl' => $camModelTranslation
                ? array_get(json_decode($camModelTranslation, TRUE), 'formats.mp4-hls.manifest')
                : null,
            'type' => 'general'
        ];
    }
}
