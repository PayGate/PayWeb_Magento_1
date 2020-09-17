<?php
/*
 * Copyright (c) 2020 PayGate (Pty) Ltd
 *
 * Author: App Inlet (Pty) Ltd
 * 
 * Released under the GNU General Public License
 */
class PayGate_Pay_Model_Functions extends Mage_Payment_Model_Method_Abstract
{

    protected $_code       = 'pay_functions';
    protected $_canCapture = true;

    public function getPostUrl()
    {
        return 'https://secure.paygate.co.za/payweb3/initiate.trans';
    }

    public function getID()
    {
        if ( $this->getConfigData( 'mode' ) ) {
            return $this->getConfigData( 'id' );
        } else {
            return '10011072130';
        }
    }

    public function getSecretKey()
    {
        if ( $this->getConfigData( 'mode' ) ) {
            return $this->getConfigData( 'key' );
        } else {
            return 'secret';
        }
    }

    public function getOrderStatus()
    {
        return $this->getConfigData( 'order_status' );
    }

    public function getOrderPlacedEmail()
    {
        return $this->getConfigData( 'order_placed_email' );
    }

    public function getOrderSuccessfulEmail()
    {
        return $this->getConfigData( 'order_successful_email' );
    }

    public function getOrderFailedEmail()
    {
        return $this->getConfigData( 'order_failed_email' );
    }

    public function getSendInvoiceEmail()
    {
        return $this->getConfigData( 'send_invoice_email' );
    }

    public function getOrder()
    {
		$quote = Mage::getSingleton( 'checkout/session' )->getQuote();
        $quote->collectTotals()->save();
		$quoteId = $quote->getId();
        $service = Mage::getModel( 'sales/service_quote', $quote );
		$service->submitAll();
		$order = $service->getOrder();
        $increment_id = $service->getOrder()->getRealOrderId();
		
		$checkoutSession = Mage::getSingleton('checkout/type_onepage')->getCheckout();
		$checkoutSession->setLastSuccessQuoteId($quoteId);
		$checkoutSession->setLastQuoteId($quoteId);
		$checkoutSession->setLastOrderId($order->getIncrementId());
		return $order;
    }
	

    public function getOrderPlaceRedirectUrl()
    {
        return Mage::getUrl( 'pay/processing/redirect' );
    }

    public function getReturnUrl()
    {
        $url = Mage::getUrl( 'pay/processing/return' );
        $url = trim( $url );
        $pos = strpos( $url, '?' );
        if ( $pos ) {
            $url = substr( $url, 0, $pos );
        }

        return $url;
    }

    public function getNotifyUrl()
    {
        $url = Mage::getUrl( 'pay/processing/notify' );
        $url = trim( $url );
        $pos = strpos( $url, '?' );
        if ( $pos ) {
            $url = substr( $url, 0, $pos );
        }

        return $url;
    }

}