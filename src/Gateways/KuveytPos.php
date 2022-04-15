<?php

namespace Mews\Pos\Gateways;

use DOMDocument;
use DOMNodeList;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use Mews\Pos\Entity\Account\AbstractPosAccount;
use Mews\Pos\Entity\Account\KuveytPosAccount;
use Mews\Pos\Entity\Card\CreditCardKuveytPos;
use Mews\Pos\Exceptions\NotImplementedException;
use Symfony\Component\HttpFoundation\Request;

/**
 * Kuveyt banki desteleyen Gateway
 */
class KuveytPos extends AbstractGateway
{
    const LANG_TR = 'tr';
    const LANG_EN = 'en';

    /**
     * @const string
     */
    public const NAME = 'KuveytPos';
    public const API_VERSION = '1.0.0';

    /**
     * Response Codes
     * @var array
     */
    protected $codes = [
        '00'                => 'approved',
        'ApiUserNotDefined' => 'invalid_transaction',
        'EmptyMDException'  => 'invalid_transaction',
        'HashDataError'     => 'invalid_transaction',
    ];

    /**
     * Transaction Types
     *
     * @var array
     */
    protected $types = [
        self::TX_PAY      => 'Sale',
        //todo update null values with valid values
        self::TX_PRE_PAY  => null,
        self::TX_POST_PAY => null,
        self::TX_CANCEL   => null,
        self::TX_REFUND   => null,
        self::TX_STATUS   => null,
    ];

    protected $secureTypeMappings = [
        self::MODEL_3D_SECURE  => 3,
        //todo update null values with valid values
        self::MODEL_3D_PAY     => null,
        self::MODEL_3D_HOST    => null,
        self::MODEL_NON_SECURE => 0,
    ];

    /**
     * Currency mapping
     *
     * @var array
     */
    protected $currencies = [
        'TRY' => '0949',
        'USD' => '0840',
        'EUR' => '0978',
        'GBP' => '0826',
        'JPY' => '0392',
        'RUB' => '0810',
    ];

    /**
     * @var KuveytPosAccount
     */
    protected $account;

    /**
     * @var CreditCardKuveytPos|null
     */
    protected $card;

    /**
     * @param array            $config
     * @param KuveytPosAccount $account
     * @param array            $currencies
     */
    public function __construct($config, $account, array $currencies = [])
    {
        parent::__construct($config, $account, $currencies);
    }

    /**
     * @inheritDoc
     */
    public function createXML(array $nodes, string $encoding = 'ISO-8859-1', bool $ignorePiNode = false): string
    {
        return parent::createXML(['KuveytTurkVPosMessage' => $nodes], $encoding, $ignorePiNode);
    }

    /**
     * @return KuveytPosAccount
     */
    public function getAccount(): KuveytPosAccount
    {
        return $this->account;
    }

    /**
     * @return CreditCardKuveytPos|null
     */
    public function getCard(): ?CreditCardKuveytPos
    {
        return $this->card;
    }

    /**
     * @param CreditCardKuveytPos|null $card
     */
    public function setCard($card)
    {
        $this->card = $card;
    }

    /**
     * Create 3D Hash
     * todo Şifrelenen veriler (Hashdata) uyuşmamaktadır. hatasi aliyoruz
     *
     * @param KuveytPosAccount $account
     * @param                  $order
     * @param bool             $forProvision
     *
     * @return string
     */
    public function create3DHash(KuveytPosAccount $account, $order, bool $forProvision = false): string
    {
        $hashedPassword = $this->hashString($account->getStoreKey());

        if ($forProvision) {
            $hashData = $this->createHashDataForAuthorization($account, $order, $hashedPassword);
        } else {
            $hashData = $this->createHashDataForProvision($account, $order, $hashedPassword);
        }

        $hashStr = implode('', $hashData);

        return $this->hashString($hashStr);
    }

    /**
     * @inheritDoc
     */
    public function send($contents, string $url = null)
    {
        $client = new Client();
        $url = $url ?: $this->getApiURL();

        $isXML = is_string($contents);
        $body = $isXML ? ['body' => $contents] : ['form_params' => $contents];
        $response = $client->request('POST', $url, $body);
        $responseBody = $response->getBody()->getContents();
        try {
            $this->data = $this->XMLStringToArray($responseBody);
        } catch (\Exception $e) {
            if (!$this->isHTML($responseBody)) {
                throw new \Exception($responseBody);
            }
            //icinde form olan HTML response dondu
            $this->data = $responseBody;
        }

        return $this->data;
    }

    /**
     * todo implement method
     * @param AbstractPosAccount $account
     * @param array              $data
     *
     * @return bool
     */
    public function check3DHash(AbstractPosAccount $account, array $data): bool
    {
        return true;
    }

    /**
     * @inheritDoc
     */
    public function make3DPayment(Request $request)
    {
        $gatewayResponse = $request->request->get('AuthenticationResponse');
        $gatewayResponse = urldecode($gatewayResponse);
        $gatewayResponse = $this->XMLStringToArray($gatewayResponse);
        $bankResponse = null;
        $procReturnCode  = $this->getProcReturnCode($gatewayResponse);
        if ($this->check3DHash($this->account, $gatewayResponse)) {
            if ('00' === $procReturnCode) {
                $contents = $this->create3DPaymentXML($gatewayResponse);

                $bankResponse = $this->send($contents);
            }
        }

        $authorizationResponse = $this->emptyStringsToNull($bankResponse);
        $this->response        = (object) $this->map3DPaymentData($gatewayResponse, $authorizationResponse);

        return $this;
    }

    /**
     * @inheritDoc
     */
    public function make3DPayPayment(Request $request)
    {
        throw new NotImplementedException();
    }

    /**
     * @inheritDoc
     */
    public function make3DHostPayment(Request $request)
    {
        return $this->make3DPayPayment($request);
    }

    /**
     * Deniz bank dokumantasyonunda history sorgusu ile alakali hic bir bilgi yok
     * @inheritDoc
     */
    public function history(array $meta)
    {
        throw new NotImplementedException();
    }

    /**
     * Amount Formatter
     * converts 100 to 10000, or 10.01 to 1001
     * @param float $amount
     *
     * @return int
     */
    public static function amountFormat(float $amount): int
    {
        return round($amount, 2) * 100;
    }

    /**
     * @inheritDoc
     */
    public function get3DFormData(): array
    {
        $gatewayUrl = $this->get3DGatewayURL();

        return $this->getCommon3DFormData($this->account, $this->order, $this->type, $gatewayUrl, $this->card);
    }

    /**
     * @inheritDoc
     */
    public function create3DPaymentXML($responseData)
    {
        $account = $this->account;
        $order = $this->order;
        $hash = $this->create3DHash($this->account, $this->order, true);

        $requestData = [
            'APIVersion'                   => self::API_VERSION,
            'HashData'                     => $hash,
            'MerchantId'                   => $account->getClientId(),
            'CustomerId'                   => $account->customerId(),
            'UserName'                     => $account->getUsername(),
            'CustomerIPAddress'            => $order->ip,
            'KuveytTurkVPosAdditionalData' => [
                'AdditionalData' => [
                    'Key'  => 'MD',
                    'Data' => $responseData['MD'],
                ],
            ],
            'TransactionType'              => $responseData['VPosMessage']['TransactionType'],
            'InstallmentCount'             => $responseData['VPosMessage']['InstallmentCount'],
            'Amount'                       => $responseData['VPosMessage']['Amount'],
            'DisplayAmount'                => $responseData['VPosMessage']['DisplayAmount'],
            'CurrencyCode'                 => $responseData['VPosMessage']['CurrencyCode'],
            'MerchantOrderId'              => $responseData['VPosMessage']['MerchantOrderId'],
            'TransactionSecurity'          => $responseData['VPosMessage']['TransactionSecurity'],
        ];

        return $requestData;
    }

    /**
     * @inheritDoc
     */
    public function createRegularPaymentXML()
    {
        throw new NotImplementedException();
    }

    /**
     * @inheritDoc
     */
    public function createRegularPostXML()
    {
        throw new NotImplementedException();
    }

    /**
     * @inheritDoc
     */
    public function createHistoryXML($customQueryData)
    {
        throw new NotImplementedException();
    }

    /**
     * @inheritDoc
     */
    public function createStatusXML()
    {
        throw new NotImplementedException();
    }

    /**
     * @inheritDoc
     */
    public function createCancelXML()
    {
        throw new NotImplementedException();
    }

    /**
     * @inheritDoc
     */
    public function createRefundXML()
    {
        throw new NotImplementedException();
    }

    /**
     * Get ProcReturnCode
     *
     * @param array $response
     *
     * @return string|null
     */
    protected function getProcReturnCode(array $response): ?string
    {
        return $response['ResponseCode'] ?? null;
    }

    /**
     * Get Status Detail Text
     *
     * @param string|null $procReturnCode
     *
     * @return string|null
     */
    protected function getStatusDetail(?string $procReturnCode): ?string
    {
        return $procReturnCode ? ($this->codes[$procReturnCode] ?? null) : null;
    }

    /**
     * @inheritDoc
     */
    protected function map3DPaymentData($raw3DAuthResponseData, $rawPaymentResponseData): array
    {
        $threeDResponse = $this->tDPayResponseCommon($raw3DAuthResponseData);

        if (empty($rawPaymentResponseData)) {
            return array_merge($this->getDefaultPaymentResponse(), $threeDResponse);
        }

        $paymentResponseData = $this->mapPaymentResponse($rawPaymentResponseData);

        return $this->mergeArraysPreferNonNullValues($threeDResponse, $paymentResponseData);
    }

    /**
     * @inheritDoc
     */
    protected function mapPaymentResponse($responseData): array
    {

        $responseData = (array) $responseData;
        if (isset($responseData['VPosMessage'])) {
            $responseData['VPosMessage'] = (array) $responseData['VPosMessage'];
        }
        $responseData   = $this->emptyStringsToNull($responseData);
        $status         = 'declined';
        $procReturnCode = $this->getProcReturnCode($responseData);

        if ('00' === $procReturnCode) {
            $status = 'approved';
        }

        $result = $this->getDefaultPaymentResponse();

        $result['proc_return_code'] = $procReturnCode;
        $result['code']             = $procReturnCode;
        $result['status']           = $status;
        $result['status_detail']    = $this->getStatusDetail($procReturnCode);
        $result['all']              = $responseData;

        if ('approved' !== $status) {
            $result['error_code']    = $procReturnCode;
            $result['error_message'] = $responseData['ResponseMessage'];

            return $result;
        }
        $result['id']            = $responseData['ProvisionNumber'];
        $result['auth_code']     = $responseData['ProvisionNumber'];
        $result['order_id']      = $responseData['MerchantOrderId'];
        $result['host_ref_num']  = $responseData['RRN'];
        $result['amount']        = $responseData['VPosMessage']['Amount'];
        $result['currency']      = array_search($responseData['VPosMessage']['CurrencyCode'], $this->currencies);
        $result['masked_number'] = $responseData['VPosMessage']['CardNumber'];

        return $result;
    }

    /**
     * @inheritDoc
     */
    protected function map3DPayResponseData($raw3DAuthResponseData)
    {
        return $this->map3DPaymentData($raw3DAuthResponseData, $raw3DAuthResponseData);
    }

    /**
     * @inheritDoc
     */
    protected function mapRefundResponse($rawResponseData)
    {
        throw new NotImplementedException();
    }

    /**
     * @inheritDoc
     */
    protected function mapCancelResponse($rawResponseData)
    {
        throw new NotImplementedException();
    }

    /**
     * @inheritDoc
     */
    protected function mapStatusResponse($rawResponseData)
    {
        throw new NotImplementedException();
    }

    /**
     * @inheritDoc
     */
    protected function mapHistoryResponse($rawResponseData)
    {
        return $rawResponseData;
    }

    /**
     * @inheritDoc
     */
    protected function preparePaymentOrder(array $order)
    {
        // Installment
        $installment = 0;
        if (isset($order['installment']) && $order['installment'] > 1) {
            $installment = (int) $order['installment'];
        }

        return (object) array_merge($order, [
            'installment' => $installment,
            'currency'    => $this->mapCurrency($order['currency']),
        ]);
    }

    /**
     * @inheritDoc
     */
    protected function preparePostPaymentOrder(array $order)
    {
        throw new NotImplementedException();
    }

    /**
     * @inheritDoc
     */
    protected function prepareStatusOrder(array $order)
    {
        return (object) $order;
    }

    /**
     * @inheritDoc
     */
    protected function prepareHistoryOrder(array $order)
    {
        return (object) $order;
    }

    /**
     * @inheritDoc
     */
    protected function prepareCancelOrder(array $order)
    {
        return (object) $order;
    }

    /**
     * @inheritDoc
     */
    protected function prepareRefundOrder(array $order)
    {
        return (object) $order;
    }

    /**
     * @param KuveytPosAccount         $account
     * @param                          $order
     * @param string                   $txType
     * @param string                   $gatewayURL
     * @param CreditCardKuveytPos|null $card
     *
     * @return array
     *
     * @throws GuzzleException
     */
    private function getCommon3DFormData(KuveytPosAccount $account, $order, string $txType, string $gatewayURL, ?CreditCardKuveytPos $card = null): array
    {
        $formData = $this->create3DEnrollmentCheckData($account, $order, $txType, $card);
        if (!$formData) {
            return [];
        }

        $xml = $this->createXML($formData);
        $bankResponse = $this->send($xml, $gatewayURL);

        return $this->transformReceived3DFormData($bankResponse);
    }

    /**
     * @param KuveytPosAccount         $account
     * @param                          $order
     * @param string                   $txType
     * @param CreditCardKuveytPos|null $card
     *
     * @return array
     */
    private function create3DEnrollmentCheckData(KuveytPosAccount $account, $order, string $txType, ?CreditCardKuveytPos $card = null): array
    {
        if (!$order) {
            return [];
        }
        $hash = $this->create3DHash($this->account, $this->order);

        $inputs = [
            'APIVersion'          => self::API_VERSION,
            'MerchantId'          => $account->getClientId(),
            'UserName'            => $account->getUsername(),
            'CustomerId'          => $account->customerId(),
            'HashData'            => $hash,
            'TransactionType'     => $txType,
            'TransactionSecurity' => $this->secureTypeMappings[$this->account->getModel()],
            'InstallmentCount'    => $order->installment,
            'Amount'              => self::amountFormat($order->amount),
            //DisplayAmount: Amount değeri ile aynı olacak şekilde gönderilmelidir.
            'DisplayAmount'       => self::amountFormat($order->amount),
            'CurrencyCode'        => $order->currency,
            'MerchantOrderId'     => $order->id,
            'OkUrl'               => $order->success_url,
            'FailUrl'             => $order->fail_url,
        ];

        if ($card) {
            $inputs['CardHolderName']      = $card->getHolderName();
            $inputs['CardType']            = $card->getCardCode();
            $inputs['CardNumber']          = $card->getNumber();
            $inputs['CardExpireDateYear']  = $card->getExpireYear();
            $inputs['CardExpireDateMonth'] = $card->getExpireMonth();
            $inputs['CardCVV2']            = $card->getCvv();
        }

        return $inputs;
    }

    /**
     * Diger Gateway'lerden farkli olarak bu gateway HTML form olan bir response doner.
     * Kutupahenin islem akisina uymasi icin bu HTML form verilerini array'e donusturup, kendimiz post ediyoruz.
     * @param string $response
     *
     * @return array
     */
    private function transformReceived3DFormData(string $response): array
    {
        $dom = new DOMDocument();
        $dom->loadHTML($response);

        $gatewayURL = '';
        $formNode = $dom->getElementsByTagName('form')->item(0);
        for ($i = 0; $i < $formNode->attributes->length; ++$i) {
            if ('action' === $formNode->attributes->item($i)->name) {
                /**
                 * banka onayladiginda gatewayURL=bankanin gateway url
                 * onaylanmadiginda (hatali istek oldugunda) ise gatewayURL = istekte yer alan failURL
                 */
                $gatewayURL = $formNode->attributes->item($i)->value;
                break;
            }
        }

        $els = $dom->getElementsByTagName('input');
        $inputs = $this->builtInputsFromHTMLDoc($els);

        return [
            'gateway' => $gatewayURL,
            'inputs'  => $inputs,
        ];
    }

    /**
     * html form'da gelen input degeleri array'e donusturur
     * @param DOMNodeList $inputNodes
     *
     * @return array
     */
    private function builtInputsFromHTMLDoc(DOMNodeList $inputNodes): array
    {
        $inputs = [];
        foreach ($inputNodes as $el) {
            $key = null;
            $value = null;
            for ($i = 0; $i < $el->attributes->length; ++$i) {
                if ('name' === $el->attributes->item($i)->name) {
                    $key = $el->attributes->item($i)->value;
                }
                if ('value' === $el->attributes->item($i)->name) {
                    $value = $el->attributes->item($i)->value;
                }
            }
            if ($key && $value) {
                $inputs[$key] = $value;
            }
        }
        unset($inputs['submit']);

        return $inputs;
    }

    /**
     * bankadan gelen response'da bos string degerler var.
     * bu metod ile bos string'leri null deger olarak degistiriyoruz
     *
     * @param string|object|array $data
     *
     * @return string|object|array
     */
    private function emptyStringsToNull($data)
    {
        if (is_string($data)) {
            $data = '' === $data ? null : $data;
        } elseif (is_array($data)) {
            foreach ($data as $key => $value) {
                $data[$key] = '' === $value ? null : $value;
            }
        }

        return $data;
    }

    /**
     * @param KuveytPosAccount $account
     * @param                  $order
     * @param string           $hashedPassword
     *
     * @return array
     */
    private function createHashDataForAuthorization(KuveytPosAccount $account, $order, string $hashedPassword): array
    {
        return [
            $account->getClientId(),
            $order->id,
            self::amountFormat($order->amount),
            $account->getUsername(),
            $hashedPassword,
        ];
    }

    /**
     * @param KuveytPosAccount $account
     * @param                  $order
     * @param string           $hashedPassword
     *
     * @return array
     */
    private function createHashDataForProvision(KuveytPosAccount $account, $order, string $hashedPassword): array
    {
        return [
            $account->getClientId(),
            $order->id,
            self::amountFormat($order->amount),
            $order->success_url,
            $order->fail_url,
            $account->getUsername(),
            $hashedPassword,
        ];
    }

    /**
     * @param array $raw3DAuthResponseData
     *
     * @return array
     */
    private function tDPayResponseCommon(array $raw3DAuthResponseData): array
    {
        $raw3DAuthResponseData = $this->emptyStringsToNull($raw3DAuthResponseData);
        $procReturnCode = $this->getProcReturnCode($raw3DAuthResponseData);
        $status = 'declined';
        $response = 'Declined';
        if ('00' === $procReturnCode) {
            $status = 'approved';
            $response = 'Approved';
        }

        $transactionSecurity = 'MPI fallback';

        if (isset($raw3DAuthResponseData['VPosMessage'])) {
            $orderId = $raw3DAuthResponseData['VPosMessage']['MerchantOrderId'];
        } else {
            $orderId = $raw3DAuthResponseData['MerchantOrderId'];
        }

        $default = [
            'order_id'             => $orderId,
            'response'             => $response,
            'transaction_type'     => $this->type,
            'transaction'          => $this->type,
            'transaction_security' => $transactionSecurity,
            'proc_return_code'     => $procReturnCode,
            'code'                 => $procReturnCode,
            'md_status'            => null,
            'status'               => $status,
            'status_detail'        => $this->getStatusDetail($procReturnCode),
            'hash'                 => null,
            'rand'                 => null,
            'hash_params'          => null,
            'hash_params_val'      => null,
            'amount'               => null,
            'currency'             => null,
            'tx_status'            => null,
            'error_code'           => 'approved' !== $status ? $procReturnCode : null,
            'md_error_message'     => 'approved' !== $status ? $raw3DAuthResponseData['ResponseMessage'] : null,
            '3d_all'               => $raw3DAuthResponseData,
        ];

        if ('approved' === $status) {
            $default['hash'] = $raw3DAuthResponseData['VPosMessage']['HashData'];
            $default['amount'] = $raw3DAuthResponseData['VPosMessage']['Amount'];
            $default['currency'] = array_search($raw3DAuthResponseData['VPosMessage']['CurrencyCode'], $this->currencies);
            $default['masked_number'] = $raw3DAuthResponseData['VPosMessage']['CardNumber'];
        }

        return $default;
    }
}