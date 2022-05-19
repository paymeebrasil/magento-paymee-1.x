<?php

class JuniorMaia_Paymee_Model_Standard extends Mage_Payment_Model_Method_Abstract
{

    protected $_code  = 'juniormaia_paymee_transfer';
    protected $_formBlockType = 'juniormaia_paymee/form_paymee';
    protected $_infoBlockType = 'juniormaia_paymee/info_paymee';

    protected $_isInitializeNeeded          = true;
    protected $_canUseInternal              = true;

    public function assignData($data)
    {
        $info = $this->getInfoInstance();

        if ($data->getPaymeeCpf())
        {
            $info->setPaymeeCpf($data->getPaymeeCpf());
        }

        if ($data->getPaymeeBanco())
        {
            $info->setPaymeeBanco($data->getPaymeeBanco());
        }

        if ($data->getPaymeeBranch())
        {
            $info->setPaymeeBranch($data->getPaymeeBranch());
        }

        if ($data->getPaymeeAccount())
        {
            $info->setPaymeeAccount($data->getPaymeeAccount());
        }

        $info->setAdditionalInformation('paymee_cpf', $data->getPaymeeCpf());
        $info->setAdditionalInformation('paymee_banco', $data->getPaymeeBanco());
        $info->setAdditionalInformation('paymee_branch', $data->getPaymeeBranch());
        $info->setAdditionalInformation('paymee_account', $data->getPaymeeAccount());

        return $this;
    }

    public function validate()
    {
        parent::validate();
        $info = $this->getInfoInstance();
        return $this;
    }

    public function initialize($paymentAction, $stateObject)
    {
        $payment            = $this->getInfoInstance();
        $order              = $payment->getOrder();

        //Admin order
        if(empty($order->getRemoteIp())){
            $_orderData         = $order->getData();
            $_paymentData       = $order->getPayment();
            $amount             = $_orderData['grand_total'];
            $customer_id        = $order->getCustomerId();

            $paymentMethod      = $_paymentData->getAdditionalInformation('paymee_banco');
            $agencia            = $_paymentData->getAdditionalInformation('paymee_branch');
            $conta              = $_paymentData->getAdditionalInformation('paymee_account');
            $paymee_document    = $_paymentData->getAdditionalInformation('paymee_cpf');

            $mobile             = preg_replace('/[^\dxX]/', '', $order->getBillingAddress()->getTelephone());
            $vatNumber      = preg_replace('/[^\dxX]/', '', $paymee_document);

            $data = array(
                "currency"          => "BRL",
                "amount"            => (float)$amount,
                "referenceCode"     => $order->getIncrementId(),
                "discriminator"     => Mage::helper('juniormaia_paymee')->getDiscriminator(),
                "maxAge"            => Mage::helper('juniormaia_paymee')->getMaxAge(),
                "paymentMethod"     => $paymentMethod,
                "callbackURL"       => Mage::getUrl('paymee/webhook/index/'),
                "shopper" => array(
                    "id" => $customer_id,
                    "name" => $_orderData['customer_firstname'].' '.$_orderData['customer_lastname'],
                    "email" => $_orderData['customer_email'],
                    "document" => array(
                        "type"      => "CPF",
                        "number"    => $vatNumber,
                    ),
                    "phone" => array(
                        "type"      => "MOBILE",
                        "number"    => $mobile,
                    ),
                    "bankDetails" => array(
                        "branch"    => $agencia,
                        "account"   => $conta,
                    )
                )
            );

            Mage::helper('juniormaia_paymee')->logs(" ----- Enviando Dados API ------");
            Mage::helper('juniormaia_paymee')->logs($data);

            $response = Mage::helper('juniormaia_paymee/api')->checkout($data);

            Mage::helper('juniormaia_paymee')->logs(" ----- Resposta API ------");
            Mage::helper('juniormaia_paymee')->logs($response);

            if(!$response["success"]) {
                Mage::getSingleton('core/session')->addError('Erro na comunicação com a PayMee.<br/>' .  $response['message']);
                return $this;
            } else {
                if ($order->getId()) {
                    $response_payload   = json_decode($response["response_payload"], true);
                    $_response          = $response_payload['response'];

                    Mage::helper('juniormaia_paymee')->logs($_response);

                    $saleCode       = $_response['saleCode'];
                    $uuid           = $_response['uuid'];
                    $referenceCode  = $_response['referenceCode'];

                    $order->getPayment()->setAdditionalInformation('paymee_response', $response["response_payload"]);
                    $order->setPaymeeUuid($uuid);
                    $order->setPaymeeSalecode($saleCode);
                    $order->setPaymeeReferencecode($referenceCode);
                    $order->save();

                    if (Mage::helper('juniormaia_paymee')->getCommentOrder()) {
                        $order->addStatusHistoryComment($response["response_payload"]);
                        $order->save();
                    }

                    Mage::getSingleton('core/session')->setData('paymee_instructions', $response["response_payload"]);
                }
            }
        }

        return $this;
    }

    /**
     * Return Order place redirect url
     *
     * @return string
     */
    public function getOrderPlaceRedirectUrl()
    {
        return Mage::getUrl('paymee/checkout/payment', array('_secure' => true));
    }

}