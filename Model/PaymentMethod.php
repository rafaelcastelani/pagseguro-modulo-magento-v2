<?php
/**
 * 2007-2016 [PagSeguro Internet Ltda.]
 *
 * NOTICE OF LICENSE
 *
 *Licensed under the Apache License, Version 2.0 (the "License");
 *you may not use this file except in compliance with the License.
 *You may obtain a copy of the License at
 *
 *http://www.apache.org/licenses/LICENSE-2.0
 *
 *Unless required by applicable law or agreed to in writing, software
 *distributed under the License is distributed on an "AS IS" BASIS,
 *WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
 *See the License for the specific language governing permissions and
 *limitations under the License.
 *
 *  @author    PagSeguro Internet Ltda.
 *  @copyright 2016 PagSeguro Internet Ltda.
 *  @license   http://www.apache.org/licenses/LICENSE-2.0
 */

namespace UOL\PagSeguro\Model;

use UOL\PagSeguro\Helper\Library;

/**
 * Class PaymentMethod
 * @package UOL\PagSeguro\Model
 */
class PaymentMethod
{
    /**
     * @var \Magento\Checkout\Model\Session
     */
    protected $_checkoutSession;
    /**
     * @var \Magento\Framework\App\Config\ScopeConfigInterface
     */
    protected $_scopeConfig;
    
    /**
     *
     * @var \PagSeguro\Domains\Requests\Payment
     */
    protected $_paymentRequest;

    /**
     *
     * @var \Magento\Directory\Api\CountryInformationAcquirerInterface
     */
    protected $_countryInformation;

    /**
     * PaymentMethod constructor.
     * @param \Magento\Framework\App\Config\ScopeConfigInterface $scopeConfigInterface
     * @param \Magento\Checkout\Model\Session $checkoutSession
     */
    public function __construct(
        \Magento\Framework\App\Config\ScopeConfigInterface $scopeConfigInterface,
        \Magento\Checkout\Model\Session $checkoutSession,
        \Magento\Directory\Api\CountryInformationAcquirerInterface $countryInformation
    ) {
        $this->_scopeConfig = $scopeConfigInterface;
        $this->_checkoutSession = $checkoutSession;
        $this->_countryInformation = $countryInformation;
        $this->_library = new Library($scopeConfigInterface);
        $this->_paymentRequest = new \PagSeguro\Domains\Requests\Payment();
    }

    /**
     * @return \PagSeguroPaymentRequest
     */
    public function createPaymentRequest()
    {
        // Currency
        $this->_paymentRequest->setCurrency("BRL");
        
        // Order ID
        $this->_paymentRequest->setReference($this->getOrderStoreReference());

        //Shipping
        $this->setShippingInformation();
        $this->_paymentRequest->setShipping()->setType()
            ->withParameters(\PagSeguro\Enum\Shipping\Type::NOT_SPECIFIED); //Shipping Type
        $this->_paymentRequest->setShipping()->setCost()
            ->withParameters(number_format($this->getShippingAmount(), 2, '.', '')); //Shipping Coast

        // Sender
        $this->setSenderInformation();

        // Itens
        $this->setItemsInformation();

        //Redirect Url
        $this->_paymentRequest->setRedirectUrl($this->getNotificationUrl());

        // Notification Url
        $this->_paymentRequest->setNotificationUrl($this->getRedirectUrl());

        try {
            $this->_library->setEnvironment();
            $this->_library->setCharset();
            $this->_library->setLog();

            return $this->_paymentRequest->register(
                $this->_library->getPagSeguroCredentials(),
                $this->_library->isLightboxCheckoutType()
            );

        } catch (PagSeguroServiceException $ex) {
            $this->logger->debug($ex->getMessage());
            $this->getCheckoutRedirectUrl();
        }
    }

    /**
     * Get information of purchased items and set in the attribute $_paymentRequest
     * @return PagSeguroItem
     */
    private function setItemsInformation()
    {
        foreach ($this->_checkoutSession->getLastRealOrder()->getAllVisibleItems() as $product) {
            $this->_paymentRequest->addItems()->withParameters(
                $product->getId(), //id
                \UOL\PagSeguro\Helper\Data::fixStringLength($product->getName(), 255), //description
                $product->getSimpleQtyToShip(), //quantity
                \UOL\PagSeguro\Helper\Data::toFloat($product->getPrice()), //amount
                round($product->getWeight()) //weight
            );
        }
    }

    /**
     * Get customer information that are sent and set in the attribute $_paymentRequest
     */
    private function setSenderInformation()
    {
        $senderName = $this->_checkoutSession->getLastRealOrder()->getCustomerName();

        // If Guest
        if ($senderName == __('Guest')) {
            $address = $this->getBillingAddress();
            $senderName = $address->getFirstname() . ' ' . $address->getLastname();
        }

        $this->_paymentRequest->setSender()->setName($senderName);
        $this->_paymentRequest->setSender()->setEmail($this->_checkoutSession
            ->getLastRealOrder()->getCustomerEmail());
        $this->setSenderPhone();
        
    }

    /**
     * Get the shipping information and set in the attribute $_paymentRequest
     */
    private function setShippingInformation()
    {
        $shipping = $this->getShippingData();
        $country = $this->_countryInformation->getCountryInfo($shipping['country_id']);
        $address = \UOL\PagSeguro\Helper\Data::addressConfig($shipping['street']);

        $this->_paymentRequest->setShipping()->setAddress()->withParameters(
            $this->getShippingAddress($address[0], $shipping),
            $this->getShippingAddress($address[1]),
            $this->getShippingAddress($address[3]),
            \UOL\PagSeguro\Helper\Data::fixPostalCode($shipping['postcode']),
            $shipping['city'],
            $this->getRegionAbbreviation($shipping['region']),
            $country->getFullNameLocale(),
            $this->getShippingAddress($address[2])
        );
    }

    /**
     * @param $address
     * @param bool $shipping
     * @return array|null
     */
    private function getShippingAddress($address, $shipping = null)
    {
        if (!is_null($address) or !empty($adress)) {
            return $address;
        }
        if ($shipping) {
            return \UOL\PagSeguro\Helper\Data::addressConfig($shipping['street']);
        }
        return null;
    }

    /**
     * Get the shipping Data of the Order
     * @return object $orderParams - Return parameters, of shipping of order
     */
    private function getShippingData()
    {
        if ($this->_checkoutSession->getLastRealOrder()->getIsVirtual()) {
            return $this->getBillingAddress();
        }
        return $this->_checkoutSession->getLastRealOrder()->getShippingAddress();
    }

    /**
     * @return mixed
     */
    private function getShippingAmount()
    {
        return $this->_checkoutSession->getLastRealOrder()->getBaseShippingAmount();
    }

    /***
     * @param $code
     * @return string
     */
    public function checkoutUrl($code, $serviceName)
    {
        $connectionData = new \PagSeguro\Resources\Connection\Data($this->_library->getPagSeguroCredentials());
        return $connectionData->buildPaymentResponseUrl() . "?code=$code";
    }

    /**
     * @return string
     */
    private function getOrderStoreReference()
    {
        return \UOL\PagSeguro\Helper\Data::getOrderStoreReference(
            $this->_scopeConfig->getValue('pagseguro/store/reference'),
            $this->_checkoutSession->getLastRealOrder()->getEntityId()
        );
    }
    
    /**
     * Get a brazilian region name and return the abbreviation if it exists
     * @param string $regionName
     * @return string
     */
    private function getRegionAbbreviation($regionName)
    {
        $regionAbbreviation = new \PagSeguro\Enum\Address();
        return (is_string($regionAbbreviation->getType($regionName))) ? $regionAbbreviation->getType($regionName) : $regionName;
    }
    
    /**
     * Get the store notification url
     * @return string
     */
    public function getNotificationUrl()
    {
        return $this->_scopeConfig->getValue('payment/pagseguro/notification');
    }
    
    /**
     * Get the store redirect url
     * @return string
     */
    public function getRedirectUrl()
    {
        return $this->_scopeConfig->getValue('payment/pagseguro/redirect');
    }
    
    /**
     * Set the sender phone if it exist
     */
    private function setSenderPhone()
    {
        $shipping = $this->getShippingData();
        if (! empty($shipping['telephone'])) {
            $phone = \UOL\PagSeguro\Helper\Data::formatPhone($shipping['telephone']);
            $this->_paymentRequest->setSender()->setPhone()->withParameters(
                $phone['areaCode'],
                $phone['number']
            ); 
        }
    }
    
    /**
     * Get the billing address data of the Order
     * @return \Magento\Sales\Model\Order\Address|null
     */
    private function getBillingAddress()
    {
        return $this->_checkoutSession->getLastRealOrder()->getBillingAddress();
    }
}
