<?php

class JuniorMaia_Paymee_Model_Pix extends Mage_Payment_Model_Method_Abstract
{

    protected $_code = 'juniormaia_paymee_pix';

    protected $_isGateway                   = true;
    protected $_canUseForMultishipping      = false;
    protected $_isInitializeNeeded          = true;
    protected $_canUseInternal              = true;

    protected $_formBlockType = 'juniormaia_paymee/form_pix';
    protected $_infoBlockType = 'juniormaia_paymee/info_paymee';

    protected $_canOrder  = true;

    public function assignData($data)
    {
        $info = $this->getInfoInstance();

        $info->setAdditionalInformation('paymee_cpf', $data->getPaymeeCpf());
        $info->setAdditionalInformation('paymee_banco', $data->getPaymeeBanco());
        $info->setAdditionalInformation('paymee_branch', $data->getPaymeeBranch());
        $info->setAdditionalInformation('paymee_account', $data->getPaymeeAccount());

        return $this;
    }

    public function initialize($paymentAction, $stateObject)
    {
        try {
            Mage::log(" ----- Place Order Pix ------");

            $payment  = $this->getInfoInstance();
            $order    = $payment->getOrder();

            //Admin order
            if(empty($order->getRemoteIp())){

                $_orderData         = $order->getData();
                $_paymentData       = $order->getPayment();
                $paymentCode        = $_paymentData->getMethod();
                $amount             = $_orderData['grand_total'];
                $customer_id        = $order->getCustomerId();

                $paymee_document    = $_paymentData->getAdditionalInformation('paymee_cpf');
                if (!$paymee_document || $paymee_document == null) {
                    $customer           = Mage::getModel('customer/customer')->load($customer_id);
                    $paymee_document    = preg_replace('/[^\dxX]/', '', $customer->getData('taxvat'));
                }

                $mobile         = preg_replace('/[^\dxX]/', '', $order->getBillingAddress()->getTelephone());

                $data = array(
                    "currency"          => "BRL",
                    "amount"            => (float)$amount,
                    "referenceCode"     => $order->getIncrementId(),
                    "discriminator"     => Mage::helper('juniormaia_paymee')->getDiscriminator(),
                    "maxAge"            => Mage::helper('juniormaia_paymee')->getMaxAge(),
                    "paymentMethod"     => 'PIX',
                    "callbackURL"       => Mage::getUrl('paymee/webhook/index/'),
                    "shopper" => array(
                        "id" => $customer_id,
                        "name" => $_orderData['customer_firstname'].' '.$_orderData['customer_lastname'],
                        "email" => $_orderData['customer_email'],
                        "document" => array(
                            "type"      => "CPF",
                            "number"    => $paymee_document,
                        ),
                        "phone" => array(
                            "type"      => "MOBILE",
                            "number"    => $mobile,
                        ),
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

                        $order->getPayment()->setAdditionalInformation('paymee_response', $response["response_payload"]);

                        $saleCode       = $_response['saleCode'];
                        $uuid           = $_response['uuid'];
                        $referenceCode  = $_response['referenceCode'];

                        if ($paymentCode == 'juniormaia_paymee_pix') {
                            $qrCode = $_response['instructions']['qrCode']['url'];
                            $plain  = $_response['instructions']['qrCode']['plain'];
                            $order->getPayment()->setAdditionalInformation('paymee_pix_qrcode', $qrCode);
                            $order->getPayment()->setAdditionalInformation('paymee_pix_plain', $plain);
                            $order->getPayment()->save();
                        }

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
        }  catch (Mage_Core_Exception $e) {
            Mage::throwException($e->getMessage());
            Mage::log('pix error: '.$e->getMessage());
        } catch (Exception $e) {
            Mage::throwException($e->getMessage());
            Mage::log('pix error: ' . $e->getMessage());
        }

        return $this;
    }

    public function validate()
    {
        parent::validate();
        $info = $this->getInfoInstance();
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