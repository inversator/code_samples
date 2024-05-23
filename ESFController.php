<?php

namespace App\Http\Controllers\WEB\LiveCam;

use App\Attribution;
use App\BaseTransaction;
use App\CamsUserIntention;
use App\Events\UserBalanceChanged;
use App\Http\Controllers\Controller;
use App\InternalTransaction;
use App\Jobs\Notifications\Slack\CamsTransactionsSlack;
use App\Jobs\User\AddSendgridUser;
use App\Notifications\Payer\GoldGivenSuccessfully;
use App\Services\Facades\BLService;
use App\Session;
use App\User;
use icf\lib\auth\AuthSdk;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Irazasyed\LaravelGAMP\Facades\GAMP;

class ESFController extends Controller
{
    /**
     * User App\User
     * @var null
     */
    protected $user, $requestId = null;

    private $resp = [];

    public function __construct()
    {
        if (config('app.env') !== 'production') {
            $this->resp += ['TEST' => config('app.env')];
        }
    }

    /**
     * When a user initiates spending, Streamate will perform a verification request
     * to verify that the user can spend the given amount.
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function verify(Request $request)
    {
        $this->logRequest(explode('::', __METHOD__)[1]);

        try {
            $data = $request->validate(array_only($this->rules(), ['member_id', 'amount']));
            $this->setUser($data['member_id']);

            // Get User balance in USD
            $balance = $this->user->balance->balance ?? 0;

        } catch (\Exception $e) {
            $this->resp += [
                'reason' => 'Invalid data format',
                'data' => $e->errors(),
                'request' => $request->all(),
                'balance' => $this->user->balance->balance ?? 'not provided',
            ];

            $this->logResponse('Checking esf balance');

            return $this->formattedResponse($this->resp, Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $this->resp += [
            'eligible' => $data['amount'] > $balance ? false : true,
            'required_amount' => $data['amount'],
            'status' => 'success',
            'balance' => $balance,
        ];

        // Logging
        $this->logResponse($this->user->user_id . " ({$this->user->username})" . ' checking esf balance', true);

        return $this->formattedResponse($this->resp);
    }

    /**
     * Streamate may need to perform preauthorization (also known as an authorization hold or preauth) requests to ensure funds are available for a future purchase. When a preauthorization request occurs, the amount specified should be on hold and be marked as unavailable until Streamate clears the transaction or the hold expires.
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function preauthorize(Request $request)
    {
        $this->logRequest(explode('::', __METHOD__)[1]);

        try {
            $data = $request->validate(array_only($this->rules(), [
                'member_id', 'amount', 'merchant_hold_id', 'expires'
            ]), $this->customErrors());

        } catch (\Exception $e) {
            $this->resp += [
                'status' => 'error',
                'reason' => 'Invalid data format',
                'data' => method_exists($e, 'errors') ? $e->errors() : $e->getMessage()
            ];

            $this->logResponse(explode('::', __METHOD__)[1] . ' ' . $request->get('member_id'));

            return $this->formattedResponse($this->resp, Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $this->setUser($data['member_id']);

        // Get User balance in USD
        $balance = $this->user->balance->balance ?? 0;
        $logRow = $this->user->user_id . " ({$this->user->username})" . ' preauthorise $' . $data['amount'];

        if ($data['amount'] > $balance) {
            $this->resp += [
                'eligible' => false,
                'balance' => $balance,
            ];

            $this->logResponse($logRow);

            return $this->formattedResponse($this->resp);
        }

        // Set payment data
        $paymentDetails = $this->getPaymentDetails($data);

        // Create PA hold
        $transaction = $this->withdrawFromBalance(
            $data['amount'],
            null,
            $paymentDetails, collect($data)->only(['expires', 'merchant_hold_id'])->all()
        );

        broadcast(new UserBalanceChanged($this->user->user_id, $this->user->getBalance()->balance ?? 0));

        $this->resp += [
            'eligible' => true,
            'status' => 'success',
            'transactionId' => $transaction->id,
            'model' => $paymentDetails['model_title'],
            'type' => $paymentDetails['type'],
            'Balance before' => $balance,
            'Amount remaining' => $this->user->fresh()->balance->balance ?? 0,
        ];

        $this->logResponse($logRow, true);

        return $this->formattedResponse($this->resp);
    }

    /**
     * If Streamate determines that a preauthorization hold is no longer required,
     * Streamate will make a request to remove the hold. When a remove preauthorization request occurs,
     * the merchant_hold_id for the hold should be removed and be available for any future spending.
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function removePreauthorize(Request $request)
    {
        $this->logRequest(Str::snake(explode('::', __METHOD__)[1]));

        try {
            $data = $request->validate(array_only($this->rules(), [
                'member_id', 'merchant_hold_id'
            ]), $this->customErrors());

        } catch (\Exception $e) {
            $this->resp += [
                'status' => 'error',
                'reason' => 'Invalid data format',
                'data' => $e->errors() ?? $e->getMessage(),
            ];

            $this->logResponse('Remove preauthorize ' . $request->get('member_id'));

            return $this->formattedResponse($this->resp, Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $this->setUser($data['member_id']);
        $balance = $this->user->balance->balance ?? 0;

        // Find in PA transactions where hold id equals merchant_hold_id
        $paTransaction = InternalTransaction::whereHoldId($data['merchant_hold_id'])
            ->wherePayerUserId($this->user->user_id)
            ->whereNotNull('expires_at')
            ->first();

        $logRow = $this->user->user_id . " ({$this->user->username})" . ' remove preauthorise';

        if (!$paTransaction) {
            $this->resp += [
                'status' => 'error',
                'reason' => 'Hold not found',
                'merchant_hold_id' => $data['merchant_hold_id']
            ];

            $this->logResponse($logRow);

            return $this->formattedResponse($this->resp, Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        // Refund PA transaction
        try {
            $transaction = InternalTransaction::create([
                'payer_user_id' => $this->user->user_id,
                'value' => $paTransaction->value,
                'source' => 'streamate',
                'data' => $paTransaction->data,
                'status' => BaseTransaction::STATUS_REFUNDED,
                'hold_id' => $paTransaction->hold_id,
                'type' => BaseTransaction::TYPE_REFUND_FOR_PRE_AUTHORIZATION
            ]);

            $this->user->getBalance()->add($paTransaction->value, $transaction, 'livecams');
            $paTransaction->update(['expires_at' => null]);

        } catch (\Exception $e) {
            $this->resp += [
                'status' => 'error',
                'reason' => $e->getMessage(),
                'merchant_hold_id' => $data['merchant_hold_id']
            ];

            $this->logResponse($logRow);

            return $this->formattedResponse($this->resp, Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        // Get User balance in USD
        $this->resp += [
            'status' => 'success',
            'Balance before' => $balance,
            'Amount remaining' => $this->user->fresh()->balance->balance ?? 0
        ];

        broadcast(new UserBalanceChanged($this->user->user_id, $this->user->getBalance()->balance ?? 0));

        $this->logResponse($logRow, true);

        return $this->formattedResponse($this->resp);
    }

    /**
     * Streamate sends transaction requests to collect owed balances. It is expected the the amount being posted will be drained from the user's balance stored on the affiliate's side. If the collection request contains merchant_hold_id, then the collection request is for funds previously held by preauthorization requests and the preauth should be released.
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function collect(Request $request)
    {
        $this->logRequest(explode('::', __METHOD__)[1]);

        try {
            $data = $request->validate([
                    'merchant_hold_id' => 'regex:/^[_a-zA-Z0-9\s-]+$/|nullable'
                ] + array_only($this->rules(), [
                    'member_id', 'amount', 'merchant_hold_id', 'merchant_trans_id', 'performer_nickname', 'spend_type'
                ]), $this->customErrors());

        } catch (\Exception $e) {
            $this->resp += [
                'data' => $e->errors() ?? $e->getMessage(),
                'status' => 'error',
                'reason' => 'Invalid data format',
                'request' => $request->all()
            ];

            $this->logResponse(explode('::', __METHOD__)[1] . ' ' . $request->get('member_id'));

            return $this->formattedResponse($this->resp, Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $this->setUser($data['member_id']);

        // Check if the user has already had successful transaction
        $camsTransactionExists = DB::table('internal_transactions')
            ->wherePayerUserId($this->user->user_id)
            ->whereSource('streamate')
            ->whereStatus(BaseTransaction::STATUS_COMPLETED)
            ->exists();

        // Set payment data
        $paymentDetails = $this->getPaymentDetails($data);

        $this->resp += ['model' => $paymentDetails['model_title'], 'type' => $paymentDetails['type']];
        $logRow = $this->user->user_id . " ({$this->user->username})" . ' collect $' . $data['amount'];
        $balance = $this->user->balance->balance ?? 0;

        if (array_get($data, 'merchant_hold_id')) {
            $transaction = InternalTransaction::whereHoldId($data['merchant_hold_id'])
                ->wherePayerUserId($this->user->user_id)
                ->whereNotNull('expires_at')
                ->first();

            if (!$transaction) {
                $this->resp += [
                    'status' => 'error',
                    'reason' => 'Hold not found',
                    'merchant_hold_id' => array_get($data, 'merchant_hold_id'),
                ];

                $this->logResponse($logRow);

                return $this->formattedResponse($this->resp, Response::HTTP_UNPROCESSABLE_ENTITY);
            }

            // Remove PA hold transaction
            try {
                $transaction->update(['type' => 'live-cams', 'expires_at' => null]);
                $this->resp += ['merchant_hold_id' => array_get($data, 'merchant_hold_id')];

            } catch (\Exception $e) {
                $this->resp += [
                    'status' => 'error',
                    'reason' => $e->getMessage(),
                    'merchant_hold_id' => array_get($data, 'merchant_hold_id')
                ];

                $this->logResponse($logRow);

                return $this->formattedResponse($this->resp, Response::HTTP_UNPROCESSABLE_ENTITY);
            }
        } else {
            // Check if user has sufficient funds
            if ($data['amount'] > $balance && ($balance <= 0 || $paymentDetails['type'] == 'give_gold')) {

                $this->resp += [
                    'status' => 'error',
                    'reason' => 'Insufficient Funds',
                    'requiredAmount' => $data['amount'],
                    'balance' => $balance,
                ];

                $this->logResponse($logRow);

                return $this->formattedResponse($this->resp, Response::HTTP_UNPROCESSABLE_ENTITY);
            }

            // Withdraw funds from the user's account, recording the transaction name and details
            $transaction = $this->withdrawFromBalance($data['amount'], $data['merchant_trans_id'], $paymentDetails);
        }

        // Send notifications
        CamsTransactionsSlack::dispatch($transaction)->onQueue('API');

        $this->resp += [
            'status' => 'success',
            'transaction_id' => $transaction->id,
            'Balance before' => $balance,
            'Amount remaining' => $this->user->fresh()->balance->balance ?? 0,
        ];

        // Send notification
        try {
            // Send transaction
            $this->sendGampEvent($data['amount'], $paymentDetails, $transaction);

            broadcast(new UserBalanceChanged($this->user->user_id, $this->user->getBalance()->balance ?? 0));

            // If it's the first cam transaction for the user
            if (!$camsTransactionExists) {
                // Add user to the specified sendgrid list
                if (config('app.env') == 'production') {
                    dispatch(new AddSendgridUser(config('sendgrid.lists.user-first-cam-transaction'), $this->user?->user_id))
                        ->onQueue('API');
                }
            }

            if ($paymentDetails['type'] == 'give_gold') {
                $this->user->notify((new GoldGivenSuccessfully($transaction))->onQueue('API'));
            }
        } catch (\Exception $e) {
            $this->logResponse($e->getMessage());
        }

        $this->logResponse($logRow, true);

        return $this->formattedResponse($this->resp);
    }

    /**
     * For support and testing purposes
     *
     * @param Request $request
     * @return array|\Illuminate\Support\MessageBag|string
     */
    public function login(Request $request)
    {
        if (config('app.env') !== 'local') return 'Only for local using';

        $validator = Validator::make($request->all(), [
            'user_id' => 'required|numeric',
            'ip' => 'ip|nullable',
            'userAgent' => 'nullable'
        ]);

        if ($validator->fails()) return $validator->errors();

        $bl = new AuthSdk(config('app.bl_key'), config('app.bl_secret'));
        $data = $request->all();
        $userIp = $data['ip'] ?? '89.250.165.81';


        $postData = [
            'referrerId' => (int)config('app.bl_ref_id'),
            'clientUserId' => (string)$data['user_id'],

            'userIp' => $userIp,

            'userAgent' => $data['userAgent']
                ?? 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/108.0.0.0 Safari/537.36'
        ];

        $response = $bl->get('https://blacklabel.naiadsystems.com/v2/user/login', $postData);

        Log::channel('esf')->info('Login', [
            collect($postData)->except('referrerId')->all(),
            $response,
            'manual api request'
        ]);

        return $response;
    }

    /**
     * For support and testing purposes
     *
     * @param Request $request
     * @return array|\Illuminate\Support\MessageBag|string
     */
    public function register(Request $request)
    {
        if (config('app.env') !== 'local') return 'Only for local using';

        $validator = Validator::make($request->all(), [
            'user_id' => 'required|numeric',
            'email' => 'required|email',
            'username' => 'required',
            'country_code' => 'required',

            'ip' => 'ip|nullable',
            'userAgent' => 'nullable'
        ]);

        if ($validator->fails()) return $validator->errors();

        $bl = new AuthSdk(config('app.bl_key'), config('app.bl_secret'));
        $userIp = $request->get('ip', '89.250.165.81');

        $data = [
            'referrerId' => (int)config('app.bl_ref_id'),
            'clientUserId' => (string)$request->get('user_id'),

            'email' => $request->get('email'),
            'nickname' => $request->get('username'),
            'country' => $request->get('country_code'),

            'userIp' => $userIp,

            'userAgent' => $request->get('userAgent')
                ?? 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/108.0.0.0 Safari/537.36'
        ];

        $response = $bl->post('https://blacklabel.naiadsystems.com/v2/user/createesfuser', $data);

        Log::channel('esf')->info('Registration', [
            collect($data)->except('referrerId')->all(),
            $response,
            'manual api request'
        ]);

        return $response;
    }

    /**
     * For support and testing purposes
     *
     * @param Request $request
     * @return array|\Illuminate\Support\MessageBag|string
     */
    public function profile(Request $request)
    {
        if (config('app.env') !== 'local') return 'Only for local using';

        $validator = Validator::make($request->all(), [
            'user_id' => 'required|numeric',
        ]);

        if ($validator->fails()) return $validator->errors();

        $user = User::find($request->get('user_id'));
        $session = Session::whereUserId($user->user_id)->orderByDesc('session_id')->first();

        return BLService::profile($user, $session, ['sakey' => $request->get('sakey')]);
    }

    /**
     * @param $userId
     * @return \Illuminate\Http\JsonResponse|void
     */
    public function setUser($userId)
    {
        // Find user with given id
        $user = User::whereUserId($userId)->withTrashed()->first();

        // Set related user if exist
        if ($user?->related_user_id) {
            $this->resp += ['User substituted' => true];
            $this->user = $user->relatedUser;
        } else {
            $this->user = $user;
        }

        // User not found
        if (!$this->user) {
            $this->resp += [
                'status' => 'error',
                'member_id' => 'Member not found',
                'reason' => 'User do not exist',
                'userId' => $userId
            ];

            $this->logResponse('Set user');

            return $this->formattedResponse($this->resp, Response::HTTP_UNPROCESSABLE_ENTITY)->throwResponse();
        }

        // User was deleted
        if ($this->user->deleted_at) {
            $this->resp += [
                'status' => 'error',
                'member_id' => 'Member was deleted',
                'reason' => 'User was deleted',
                'userId' => $userId
            ];

            $this->logResponse('Set user');

            return $this->formattedResponse($this->resp, Response::HTTP_UNPROCESSABLE_ENTITY)->throwResponse();
        }
    }

    /**
     * Validation rules
     * @return array
     */
    public function rules(): array
    {
        return [
            'member_id' => 'required|min:3|numeric',
            'amount' => 'required|numeric',
            'merchant_hold_id' => 'required|regex:/^[_a-zA-Z0-9\s-]+$/',
            'expires' => 'required|numeric',
            'merchant_trans_id' => 'required|regex:/^[_a-zA-Z0-9\s-]+$/',
            'performer_nickname' => 'nullable|string',
            'spend_type' => 'nullable|string',
        ];
    }

    /**
     * Validation custom errors
     * @return array
     */
    public function customErrors(): array
    {
        return [
            'merchant_hold_id' => [
                'regex' => 'merchant_hold_id format is invalid'
            ],
            'merchant_trans_id' => [
                'regex' => 'merchant_hold_id format is invalid'
            ],
        ];
    }

    public function withdrawFromBalance(float $amount, $transactionId, array $paymentDetails, array $paData = null): InternalTransaction
    {
        $transactionData = [
            'payer_user_id' => $this->user->user_id,
            'value' => $amount,
            'source' => 'streamate',
            'checkout_id' => $transactionId,
            'data' => $paymentDetails,
            'status' => 2,
            'type' => 'live-cams',
            'creator_share' => $amount * config('financial.cams_creator_share'),
            'sinparty_share' => $amount * config('financial.cams_sinparty_share')
        ];

        if ($paData) {
            $transactionData['type'] = 'PA';
            $transactionData['expires_at'] = now()->addMinutes($paData['expires']);
            $transactionData['hold_id'] = $paData['merchant_hold_id'];
        }

        $transaction = InternalTransaction::create($transactionData);

        if (isset($paymentDetails['attribution_id']) && $paymentDetails['attribution_id']) {
            $attribution = Attribution::find($paymentDetails['attribution_id']);
            $attribution?->internalTransactions()->attach($transaction);
        }

        $this->user->getBalance()->subtract($amount, $transaction, 'livecams');

        return $transaction;
    }

    /**
     * Get data from site user last btn push behavior or just from request data
     *
     * @return array
     */
    public function getPaymentDetails($data)
    {
        $intention = CamsUserIntention::where('user_id', $this->user->user_id)->first();

        $paymentDetails = [
            'type' => !empty($data['spend_type']) ? strtolower($data['spend_type']) : ($intention->type ?? 'unknown'),
            'attribution_id' => $intention->attribution_id ?? null,
        ];

        if (!empty($data['performer_nickname'])) {
            $paymentDetails['model_title'] = $data['performer_nickname'];
            // Need to check orientation before for link, but still have load issue
            $paymentDetails['link'] = config('app.website_url') . '/live/' . strtolower($data['performer_nickname']);
        } else {
            $paymentDetails['model_title'] = $intention->model_title ?? 'No data';
            $paymentDetails['link'] = $intention->link ?? 'No data';
        }

        return $paymentDetails;
    }

    /**
     * Format response for streamate
     *
     * @param array $data
     * @param $status
     * @return \Illuminate\Contracts\Foundation\Application|\Illuminate\Contracts\Routing\ResponseFactory|Response
     */
    private function formattedResponse(array $data, $status = 200)
    {
        return response(gzencode(json_encode($data), 9), $status)
            ->header('Content-Type', '')
            ->header('Content-Encoding', 'gzip');
    }

    /**
     * @param $message
     * @return void
     */
    private function logResponse($message, $success = false)
    {
        $data = collect($this->resp)->except(['eligible', 'status', 'request'])->all();

        if (!$success) {
            Log::channel('esf')->error('Error: ' . $this->requestId . ' — ', [$this->resp]);
            Log::channel('live-cams-slack')->error($message, $data);
        } else {
            Log::channel('esf')->info('Out: ' . $this->requestId . ' — ', [$this->resp]);
            Log::channel('live-cams-slack')->info($message, $data);
        }
    }

    private function logRequest($type)
    {
        $this->requestId = uniqid() . ' ' . $type;
        Log::channel('esf')->info('In: ' . $this->requestId . ' — ', [request()->all()]);
    }

    private function sendGampEvent($amount, $paymentDetails, $transaction)
    {
        $ga_id = $this->user->getGaId();
        $gamp = GAMP::setClientId($ga_id);

        $price = $amount;

        $ecCategory = 'live-cams';
        $ecBrand = 'Streamate';
        $ecName = "live-cams-{$paymentDetails['type']}-{$paymentDetails['model_title']}";
        $ecId = 'U' . $this->user->user_id;

        $gamp->setTransactionId($transaction->id)
            ->setRevenue($price)
            ->setAffiliation($ecBrand)
            ->sendTransaction();

        $gamp->setTransactionId($transaction->id)
            ->setItemName($ecName)
            ->setItemCode($ecId)
            ->setItemCategory($ecCategory)
            ->setItemPrice($price)
            ->setItemQuantity(1)
            ->sendItem();
    }
}
