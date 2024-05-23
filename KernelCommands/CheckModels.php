<?php

namespace App\Console\Commands\Cams;

use App\CamsFollower;
use App\Notifications\CamModelLive;
use App\Services\Facades\BLService;
use App\User;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class CheckModels extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'cams:online';
    private $users = [];
    private $cachePrefix = 'check-cam-model-';
    private $cacheTags;

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Check if the followed models are online';

    /**
     * Create a new command instance.
     *
     * @return void
     */
    public function __construct()
    {
        $this->cacheTags = [config('app.env'), 'cams'];
        parent::__construct();
    }

    /**
     * Execute the console command.
     *
     * @return bool
     * @throws \Exception
     */
    public function handle(): bool
    {
        $this->checkModelsByIds();
        $this->checkModelsByNicknames();

        return true;
    }

    private function checkModelsByIds()
    {
        $failedRequests = [];

        CamsFollower::select('cam_model_id', 'cam_model_name', DB::raw('GROUP_CONCAT(user_id) as user_ids'))
            ->where('cam_model_id', '!=', 0)
            ->groupBy('cam_model_id')
            ->chunk(50, function ($chunk) use (&$failedRequests) {
                $modelArr = $chunk->sortBy('cam_model_name')->pluck('cam_model_id');

                try {
                    $blResponse = BLService::getStreamStatus($modelArr->all());
                } catch (\Exception $e) {
                    $this->output->comment('Problem with streamate request - ' . $e->getMessage());
                    $failedRequests[] = $chunk;
                    return true;
                }
                // To avoid loading on streamate api
                usleep(300000);

                if (BLService::requestFailed() || !is_array($blResponse) || !count($blResponse)) {
                    return true;
                }

                $models = $blResponse;

                // Load users for following interaction
                $this->setUsers($chunk);

                // Check model live status
                foreach ($models as $modelId => $model) {
                    $requestedInHour = cache()->tags($this->cacheTags)->get($this->cachePrefix . $modelId);

                    $this->output->comment('Check for ' . $modelId);

                    if (!$requestedInHour || $requestedInHour === 'offline') {
                        if ($model['Status']['Online']) {
                            // Find followers
                            if ($followers = $chunk->firstWhere('cam_model_id', $modelId)?->user_ids) {

                                foreach (explode(',', $followers) as $userId) {
                                    $user = $this->users[$userId] ?? User::find($userId);

                                    // Send notification
                                    if ($user) {
                                        $user->notify((new CamModelLive(
                                            $model['nickname'],
                                            array_get($model, 'Thumbnail'),
                                            array_get($model, 'Gender')
                                        ))->onQueue('API'));

                                        $this->output->comment('Sent for user ' . $userId);
                                    } else {
                                        $this->output->comment('User ' . $userId . ' not found');
                                    }
                                }

                                $this->output->comment('Finished');

                                cache()->tags($this->cacheTags)->put($this->cachePrefix . $modelId, 'live', 60 * 60);
                            } else {
                                $this->output->comment('Problem with getting followers');
                            }
                        } else {
                            $this->output->comment($model['nickname'] . ' offline');
                        }
                    } else {
                        $this->output->comment('Was requested in hour ' . $requestedInHour);
                    }
                }

                return true;
            });
    }

    /**
     * @throws \Exception
     */
    private function checkModelsByNicknames(): void
    {
        $failedRequests = [];

        CamsFollower::select('cam_model_name', DB::raw('GROUP_CONCAT(user_id) as user_ids'))
            ->where('cam_model_id', 0)
            ->groupBy('cam_model_name')
            ->chunk(50, function ($chunk) use (&$failedRequests) {

                $modelArr = $chunk->sortBy('cam_model_name')->pluck('cam_model_name');

                try {
                    $blResponse = BLService::getList(['query' => $modelArr->implode(',')]);
                } catch (\Exception $e) {
                    $this->output->comment('Problem with streamate request - ' . $e->getMessage());
                    $failedRequests[] = $chunk;
                    return true;
                }

                // To avoid loading on streamate api
                usleep(500000);

                if (BLService::requestFailed() || !is_array($blResponse) || BLService::noResults()) {
                    return true;
                }

                $models = array_filter($blResponse['Results'], fn($model) => in_array($model['Nickname'], $modelArr->all()));

                // Load users for following interaction
                $this->setUsers($chunk);

                // Check model live status
                foreach ($models as $model) {
                    $requestedInHour = cache()->tags($this->cacheTags)->get($this->cachePrefix . $model['Nickname']);

                    $this->output->comment('Check for ' . $model['Nickname']);

                    if (!$requestedInHour || $requestedInHour === 'offline') {

                        if ($model['LiveStatus'] === 'live') {
                            // Find followers
                            if ($followers = $chunk->firstWhere('cam_model_name', $model['Nickname'])?->user_ids) {
                                foreach (explode(',', $followers) as $userId) {
                                    $user = $this->users[$userId] ?? User::find($userId);

                                    // Send notification
                                    if ($user) {
                                        $user->notify((new CamModelLive(
                                            $model['Nickname'],
                                            array_get($model, 'Thumbnail'),
                                            array_get($model, 'Gender')
                                        ))->onQueue('API'));

                                        $this->output->comment('Sent for user ' . $userId);
                                    } else {
                                        $this->output->comment('User ' . $userId . ' not found');
                                    }
                                }

                                $this->output->comment('Finished');

                                cache()->tags($this->cacheTags)->put($this->cachePrefix . $model['Nickname'], 'live', 60 * 60);
                            } else {
                                $this->output->comment('Problem with getting followers');
                            }
                        } else {
                            $this->output->comment($model['Nickname'] . ' offline');
                        }
                    } else {
                        $this->output->comment('Was requested in hour ' . $requestedInHour);
                    }

                    // Add performer id for missed rows
                    CamsFollower::where('cam_model_name', $model['Nickname'])->update([
                        'cam_model_id' => array_get($model, 'PerformerId'),
                        'cam_model_stars' => array_get($model, 'Stars'),
                        'cam_model_gender' => array_get($model, 'Gender'),
                        'cam_model_country' => array_get($model, 'Country'),
                        'cam_model_age' => array_get($model, 'Age'),
                    ]);
                }

                return true;
            });
    }

    private function setUsers(Collection $chunk): void
    {
        $userIds = array_unique(explode(',', $chunk->pluck('user_ids')->implode(',')));

        $idsToLoad = array_filter($userIds, function ($userId) {
            return !isset($this->users[$userId]);
        });

        if ($idsToLoad) {
            $loadedUsers = User::findMany($idsToLoad)->keyBy('user_id');
            $this->users = $this->users + $loadedUsers->all();
        }
    }
}
