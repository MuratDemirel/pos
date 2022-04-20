<?php

namespace Mews\Pos\DataMapper;

use Mews\Pos\Entity\Account\AbstractPosAccount;
use Mews\Pos\Entity\Card\AbstractCreditCard;
use Mews\Pos\Exceptions\NotImplementedException;
use Mews\Pos\Gateways\AbstractGateway;

/**
 * Creates request data for KuveytPos Gateway requests
 */
class InterPosRequestDataMapper extends AbstractRequestDataMapper
{
    public const CREDIT_CARD_EXP_DATE_FORMAT = 'my';

    protected $secureTypeMappings = [
        AbstractGateway::MODEL_3D_SECURE  => '3DModel',
        AbstractGateway::MODEL_3D_PAY     => '3DPay',
        AbstractGateway::MODEL_3D_HOST    => '3DHost',
        AbstractGateway::MODEL_NON_SECURE => 'NonSecure',
    ];

    /**
     * Transaction Types
     *
     * @var array
     */
    protected $txTypeMappings = [
        AbstractGateway::TX_PAY      => 'Auth',
        AbstractGateway::TX_PRE_PAY  => 'PreAuth',
        AbstractGateway::TX_POST_PAY => 'PostAuth',
        AbstractGateway::TX_CANCEL   => 'Void',
        AbstractGateway::TX_REFUND   => 'Refund',
        AbstractGateway::TX_STATUS   => 'StatusHistory',
    ];

    protected $cardTypeMapping = [
        AbstractCreditCard::CARD_TYPE_VISA       => '0',
        AbstractCreditCard::CARD_TYPE_MASTERCARD => '1',
        AbstractCreditCard::CARD_TYPE_AMEX       => '3',
    ];

    /**
     * Currency mapping
     *
     * @var array
     */
    protected $currencyMappings = [
        'TRY' => 949,
        'USD' => 840,
        'EUR' => 978,
        'GBP' => 826,
        'JPY' => 392,
        'RUB' => 810,
    ];

    /**
     * @inheritDoc
     */
    public function create3DPaymentRequestData(AbstractPosAccount $account, $order, string $txType, array $responseData): array
    {
        return [
            'UserCode'                => $account->getUsername(),
            'UserPass'                => $account->getPassword(),
            'ClientId'                => $account->getClientId(),
            'TxnType'                 => $txType,
            'SecureType'              => $this->secureTypeMappings[AbstractGateway::MODEL_NON_SECURE],
            'OrderId'                 => $order->id,
            'PurchAmount'             => $order->amount,
            'Currency'                => $order->currency,
            'InstallmentCount'        => $order->installment,
            'MD'                      => $responseData['MD'],
            'PayerTxnId'              => $responseData['PayerTxnId'],
            'Eci'                     => $responseData['Eci'],
            'PayerAuthenticationCode' => $responseData['PayerAuthenticationCode'],
            'MOTO'                    => '0',
            'Lang'                    => $this->getLang($account, $order),
        ];
    }

    /**
     * @inheritDoc
     */
    public function createNonSecurePaymentRequestData(AbstractPosAccount $account, $order, string $txType, ?AbstractCreditCard $card = null): array
    {
        $requestData = [
            'UserCode'         => $account->getUsername(),
            'UserPass'         => $account->getPassword(),
            'ShopCode'         => $account->getClientId(),
            'TxnType'          => $txType,
            'SecureType'       => $this->secureTypeMappings[AbstractGateway::MODEL_NON_SECURE],
            'OrderId'          => $order->id,
            'PurchAmount'      => $order->amount,
            'Currency'         => $order->currency,
            'InstallmentCount' => $order->installment,
            'MOTO'             => '0',
            'Lang'             => $this->getLang($account, $order),
        ];

        if ($card) {
            $requestData['CardType'] = $this->cardTypeMapping[$card->getType()];
            $requestData['Pan']      = $card->getNumber();
            $requestData['Expiry']   = $card->getExpirationDate(self::CREDIT_CARD_EXP_DATE_FORMAT);
            $requestData['Cvv2']     = $card->getCvv();
        }

        return $requestData;
    }

    /**
     * @inheritDoc
     */
    public function createNonSecurePostAuthPaymentRequestData(AbstractPosAccount $account, $order, ?AbstractCreditCard $card = null): array
    {
        return [
            'UserCode'    => $account->getUsername(),
            'UserPass'    => $account->getPassword(),
            'ShopCode'    => $account->getClientId(),
            'TxnType'     => $this->txTypeMappings[AbstractGateway::TX_POST_PAY],
            'SecureType'  => $this->secureTypeMappings[AbstractGateway::MODEL_NON_SECURE],
            'OrderId'     => null,
            'orgOrderId'  => $order->id,
            'PurchAmount' => $order->amount,
            'Currency'    => $order->currency,
            'MOTO'        => '0',
        ];
    }

    /**
     * @inheritDoc
     */
    public function createStatusRequestData(AbstractPosAccount $account, $order): array
    {
        return [
            'UserCode'   => $account->getUsername(),
            'UserPass'   => $account->getPassword(),
            'ShopCode'   => $account->getClientId(),
            'OrderId'    => null, //todo buraya hangi deger verilecek?
            'orgOrderId' => $order->id,
            'TxnType'    => $this->txTypeMappings[AbstractGateway::TX_STATUS],
            'SecureType' => $this->secureTypeMappings[AbstractGateway::MODEL_NON_SECURE],
            'Lang'       => $this->getLang($account, $order),
        ];
    }

    /**
     * @inheritDoc
     */
    public function createCancelRequestData(AbstractPosAccount $account, $order): array
    {
        return [
            'UserCode'   => $account->getUsername(),
            'UserPass'   => $account->getPassword(),
            'ShopCode'   => $account->getClientId(),
            'OrderId'    => null, //todo buraya hangi deger verilecek?
            'orgOrderId' => $order->id,
            'TxnType'    => $this->txTypeMappings[AbstractGateway::TX_CANCEL],
            'SecureType' => $this->secureTypeMappings[AbstractGateway::MODEL_NON_SECURE],
            'Lang'       => $this->getLang($account, $order),
        ];
    }

    /**
     * @inheritDoc
     */
    public function createRefundRequestData(AbstractPosAccount $account, $order): array
    {
        return [
            'UserCode'    => $account->getUsername(),
            'UserPass'    => $account->getPassword(),
            'ShopCode'    => $account->getClientId(),
            'OrderId'     => null,
            'orgOrderId'  => $order->id,
            'PurchAmount' => $order->amount,
            'TxnType'     => $this->txTypeMappings[AbstractGateway::TX_REFUND],
            'SecureType'  => $this->secureTypeMappings[AbstractGateway::MODEL_NON_SECURE],
            'Lang'        => $this->getLang($account, $order),
            'MOTO'        => '0',
        ];
    }

    /**
     * @inheritDoc
     */
    public function createHistoryRequestData(AbstractPosAccount $account, $order, array $extraData = []): array
    {
        throw new NotImplementedException();
    }


    /**
     * @inheritDoc
     */
    public function create3DFormData(AbstractPosAccount $account, $order, string $txType, string $gatewayURL, ?AbstractCreditCard $card = null): array
    {
        $hash = $this->create3DHash($account, $order, $txType);

        $inputs = [
            'ShopCode'         => $account->getClientId(),
            'TxnType'          => $txType,
            'SecureType'       => $this->secureTypeMappings[$account->getModel()],
            'Hash'             => $hash,
            'PurchAmount'      => $order->amount,
            'OrderId'          => $order->id,
            'OkUrl'            => $order->success_url,
            'FailUrl'          => $order->fail_url,
            'Rnd'              => $order->rand,
            'Lang'             => $this->getLang($account, $order),
            'Currency'         => $order->currency,
            'InstallmentCount' => $order->installment,
        ];

        if ($card) {
            $inputs['CardType'] = $this->cardTypeMapping[$card->getType()];
            $inputs['Pan']      = $card->getNumber();
            $inputs['Expiry']   = $card->getExpirationDate(self::CREDIT_CARD_EXP_DATE_FORMAT);
            $inputs['Cvv2']     = $card->getCvv();
        }

        return [
            'gateway' => $gatewayURL,
            'inputs'  => $inputs,
        ];
    }

    /**
     * @inheritDoc
     */
    public function create3DHash(AbstractPosAccount $account, $order, string $txType): string
    {
        $hashData = [
            $account->getClientId(),
            $order->id,
            $order->amount,
            $order->success_url,
            $order->fail_url,
            $txType,
            $order->installment,
            $order->rand,
            $account->getStoreKey(),
        ];

        $hashStr = implode(static::HASH_SEPARATOR, $hashData);

        return $this->hashString($hashStr);
    }
}