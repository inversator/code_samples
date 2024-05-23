<?php

namespace App\Console\Commands\Sendgrid;

use App\Balance;
use App\BaseTransaction;
use App\InternalTransaction;
use App\Services\SendgridService;
use App\Transaction;
use App\User;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

class ContactHandler extends Command
{
    const IMPORT_COMMAND_TYPE = 'import';
    const DELETE_BANNED_COMMAND_TYPE = 'delete-banned';
    const UNSUBSCRIBE_SUPPRESSED_COMMAND_TYPE = 'unsubscribe-suppressed';

    const COMMAND_TYPES = [
        self::IMPORT_COMMAND_TYPE,
        self::DELETE_BANNED_COMMAND_TYPE,
        self::UNSUBSCRIBE_SUPPRESSED_COMMAND_TYPE
    ];

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature;

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Sendgrid contacts handler';

    /**
     * Create a new command instance.
     *
     * @return void
     */
    public function __construct(private SendgridService $sendgridService)
    {
        $commandTypeString = implode('/', self::COMMAND_TYPES);
        $this->signature = 'sendgrid:contacts {type : Command type — ' . $commandTypeString . ' } {--file-name= : Import file name }';
        parent::__construct();
    }

    /**
     * Execute the console command.
     *
     * @return mixed
     */
    public function handle()
    {
        $commandType = $this->argument('type');

        if (in_array($commandType, self::COMMAND_TYPES)) {
            $this->{camel_case($commandType) . 'Contacts'}();
        } else {
            $this->output->error('Command type ' . $commandType . ' not found');
        }
    }

    private function unsubscribeSuppressedContacts()
    {
        $fileContents = file(base_path($this->option('file-name') ?: 'sendgrid_suppressed.csv'));

        $bar = $this->output->createProgressBar(count($fileContents));
        $bar->start();

        foreach ($fileContents as $line) {
            $bar->advance();

            $lineData = explode(',', $line);
            $email = head($lineData);

            $validator = Validator::make(['email' => $email], [
                'email' => 'email',
            ]);

            if ($validator->passes()) {
                $user = User::withTrashed()->with('profileNotifications')->whereEmail($email)->first();

                if ($user?->profileNotifications?->email_enabled) {
                    $user->profileNotifications->update(['email_enabled' => 0]);
                }
            }
        }

        $bar->finish();
    }

    private function deleteBannedContacts()
    {
        $emails = User::withTrashed()
            ->whereNotNull('email')
            ->where('email', '!=', '')
            ->where(function (Builder $q) {
                $q->whereNotNull('deleted_at')->orWhere('is_banned', 1);
            })->pluck('email')->toArray();


        $emailChunks = collect($emails)->chunk(50);

        $bar = $this->output->createProgressBar(count($emails));
        $bar->start();

        foreach ($emailChunks as $emailChunk) {
            $sgContacts = $this->sendgridService->getContactsByEmails($emailChunk->all());

            if (count($sgContacts)) {
                $ids = collect($sgContacts)->pluck('id')->all();
                $this->sendgridService->deleteContacts($ids);
            }

            $bar->advance(count($emailChunk));
        }

        $bar->finish();
    }

    public function importContacts()
    {
        // Log start of command
        Log::info('sendgrid_importer has been executed at: ' . date("Y-m-d H:i:s"));

        $importListId = config('sendgrid.lists.general');
        $sg = new \SendGrid(config('sendgrid.api_key'));

        // Get custom field list
        $resp = $sg->client->marketing()->field_definitions()->get();
        $body = json_decode($resp->body(), true);
        $customFieldsIds = Arr::pluck($body['custom_fields'], 'id', 'name');

        // Get users
        $query = User::withTrashed()->where('email', 'NOT RLIKE', 'xn--')->with('preference');

        Log::channel('sendgrid-slack')->info('Mass upload START', [
            'env' => config('app.env'),
            'count' => $query->count(),
        ]);

        $contactListString = '';

        $query->chunk(100, function (Collection $users) use ($sg, $importListId, &$contactListString, &$customFieldsIds) {
            $users->each(function (User $user) use (&$contactListString, &$customFieldsIds) {
                try {
                    if (!filter_var($user->email, FILTER_VALIDATE_EMAIL)) {
                        return true;
                    }

                    if ($user->deleted_at || $user->is_banned) {
                        return true;
                    }

                    // Limited to 50 characters
                    $username = substr($user->username, 0, 50);
                    $blackLabelJoinedAt = $user->blacklabel_joined_at
                        ? Carbon::parse($user->blacklabel_joined_at)?->format('m/d/Y')
                        : null;

                    $lastLogin = $user->last_login
                        ? Carbon::parse($user->last_login)?->format('m/d/Y')
                        : null;

                    $balanceWasToppedUp = Balance::where('user_id', $user->user_id)
                        ->where('direction', 'in')
                        ->whereDoesntHaveMorphIn('transaction', InternalTransaction::class, function ($q) {
                            $q->where('type', BaseTransaction::TYPE_BONUS);
                        })->exists();

                    // Get preference
                    if ($user->preference) {
                        $preference = match (true) {
                            $user->preference?->has_gay => 'has_gay',
                            $user->preference?->has_transgender => 'has_transgender',
                            default => 'has_straight',
                        };
                    } else
                        $preference = "has_straight";

                    $customFields = '{
                        "' . $customFieldsIds['user_id'] . '": ' . $user->user_id . ',
                        "' . $customFieldsIds['username'] . '": "' . $username . '",
                        "' . $customFieldsIds['is_active'] . '": ' . $user->is_active . ',
                        "' . $customFieldsIds['is_fake'] . '": ' . $user->is_fake . ',
                        "' . $customFieldsIds['orientation'] . '": "' . $preference . '",
                        "' . $customFieldsIds['gender'] . '": "' . $user->gender . '",
                        "' . $customFieldsIds['registered_at'] . '": "' . $user->created_at?->format('m/d/Y') . '",
                        "' . $customFieldsIds['last_login'] . '": "' . $lastLogin . '",
                        "' . $customFieldsIds['blacklabel_joined_at'] . '": "' . $blackLabelJoinedAt . '",
                        "' . $customFieldsIds['registration_type'] . '": "' . $user->registration_type . '",
                        "' . $customFieldsIds['balance'] . '": ' . (int)$user->getBalance()->balance . ',
                        "' . $customFieldsIds['balance_topped_up'] . '": ' . ($balanceWasToppedUp ? 1 : 0) . ',
                        "' . $customFieldsIds['is_creator'] . '": ' . ($user->model ? 1 : 0) . '
                    ';

                    if ($user->model) {
                        $earnings = Transaction::where('creator_user_id', $user->user_id)->whereNotIn('status', [
                            Transaction::STATUS_PENDING,
                            Transaction::STATUS_ERROR,
                        ])->sum('value');

                        $customFields .= ',"' . $customFieldsIds['creator_name'] . '": "' . $user?->model?->title . '"';
                        $customFields .= ',"' . $customFieldsIds['creator_earnings'] . '": ' . $earnings;
                    }

                    $customFields .= '}';

                    $newObject = '{"email": "' . $user->email . '",
                        "first_name": "' . $username . '",
                        "last_name": "' . $username . '",
                        "custom_fields": ' . $customFields . '
                    }';

                    if (!$this->isJsonValid($newObject)) {
                        Log::channel('sendgrid-slack')->error('Wrong json: ', ['object' => $newObject]);
                    } else {
                        $contactListString .= $newObject . ',';
                    }

                    // Error while data creating
                } catch (\Exception $e) {
                    $this->importError(null, $e);
                }

                return true;
            });

            // Decode and send data
            $request_body = json_decode('{"list_ids": [
                "' . $importListId . '"
            ], "contacts": [' . mb_substr($contactListString, 0, -1) . ']}');

            try {
                $resp = $sg->client->marketing()->contacts()->put($request_body);

                if (!$resp || $resp->statusCode() == 400) {
                    $e = new \Exception('Sendgrid mass upload error');
                    $this->importError($resp ?? null, $e);

                } else {
                    echo ($resp->statusCode() ?? 'no code') . '_' . ($resp->body() ?? 'no-body') . "\r\n";

                    Log::channel('sendgrid')->info('Mass upload', [
                        $resp->statusCode() ?? 'no-code',
                        $resp->body() ?? 'no-body'
                    ]);
                }

            } catch (\Exception $e) {
                $this->importError($resp ?? null, $e);
                report($e);
            }

            $contactListString = '';
        });

        Log::channel('sendgrid-slack')->info('Mass upload END', ['env' => config('app.env')]);
        Log::info('sendgrid_importer has been finished at: ' . date("Y-m-d H:i:s"));
    }

    private function isJsonValid($string)
    {
        json_decode($string);
        return (json_last_error() == JSON_ERROR_NONE);
    }

    private function importError($resp, $exception)
    {
        $data = [
            $resp?->statusCode() ?? 'error',
            $resp?->body() ?? 'no-data'
        ];

        if (app()->bound('sentry')) {
            app('sentry')
                ->captureException($exception);
        }

        if ($resp)
            echo ($resp->statusCode() ?? 'no code') . '_' . ($resp->body() ?? 'no-body') . "\r\n";

        Log::channel('sendgrid')->error('Error mass upload', $data);
        Log::channel('sendgrid-slack')->error('Error mass upload: ', ['response' => $data]);
    }

    public function populateListByOrientation()
    {
        $generalListId = config('sendgrid.lists.general');

        $straightContacts = $gayContacts = $transContacts = '';

        $sg = new \SendGrid(config('sendgrid.api_key'));


        // Log start of command
        Log::info('sendgrid_importer has been executed at: ' . date("Y-m-d H:i:s"));

        $straightUsers = User::whereDoesntHave('model')->whereIsActive(1)->whereHas('preference', function ($q) {
            $q->where('has_straight', 1);
        })->get();

        $gayUsers = User::whereDoesntHave('model')->whereIsActive(1)->whereHas('preference', function ($q) {
            $q->where('has_gay', 1);
        })->get();

        $transUsers = User::whereDoesntHave('model')->whereIsActive(1)->whereHas('preference', function ($q) {
            $q->where('has_transgender', 1);
        })->get();


        // Add emails to free user group
        foreach ($straightUsers->chunk(100) as $straightUserChunk) {
            foreach ($straightUserChunk as $user) {
                $straightContacts .= '{"email": "' . $user->email . '",
                        "first_name": "' . $user->username . '",
                        "last_name": "' . $user->username . '"
                    },';
            }

            $request_body = json_decode('{"list_ids": [
                "' . $generalListId . '"], "contacts": [' . mb_substr($straightContacts, 0, -1) . ']}');

            $response = $sg->client->marketing()->contacts()->put($request_body);
            echo $response->statusCode() . '_' . $response->body() . "\r\n";

            $straightContacts = '';
        }

        foreach ($gayUsers->chunk(100) as $gayUserChunk) {
            foreach ($gayUserChunk as $user) {
                $gayContacts .= '{"email": "' . $user->email . '",
                        "first_name": "' . $user->username . '",
                        "last_name": "' . $user->username . '"
                    },';
            }

            $request_body = json_decode('{"list_ids": [
                "' . $generalListId . '"], "contacts": [' . mb_substr($gayContacts, 0, -1) . ']}');

            $response = $sg->client->marketing()->contacts()->put($request_body);
            echo $response->statusCode() . '_' . $response->body() . "\r\n";

            $gayContacts = '';
        }

        foreach ($transUsers->chunk(100) as $transUserChunk) {
            foreach ($transUserChunk as $user) {
                $transContacts .= '{"email": "' . $user->email . '",
                        "first_name": "' . $user->username . '",
                        "last_name": "' . $user->username . '"
                    },';
            }

            $request_body = json_decode('{"list_ids": [
                "' . $generalListId . '"], "contacts": [' . mb_substr($transContacts, 0, -1) . ']}');

            $response = $sg->client->marketing()->contacts()->put($request_body);
            echo $response->statusCode() . '_' . $response->body() . "\r\n";

            $transContacts = '';
        }

        echo "Finished";
    }
}
