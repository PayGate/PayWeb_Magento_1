<?php
/*
 * Copyright (c) 2022 PayGate (Pty) Ltd
 *
 * Author: App Inlet (Pty) Ltd
 *
 * Released under the GNU General Public License
 */

class PayGate_Pay_ProcessingController extends Mage_Core_Controller_Front_Action
{

    protected $_order       = null;
    protected $_paymentInst = null;

    public function redirectAction()
    {
        /**
         * @var $module PayGate_Pay_Model_Functions
         */
        $module  = Mage::getModel( 'pay/functions' );
        $session = Mage::getSingleton( 'checkout/session' );

        /**
         * @var $order Mage_Sales_Model_Order
         */
        $order     = $module->getOrder();
        $emailSent = false;

        if ( $module->getOrderPlacedEmail() ? true : false ) {
            $order->sendNewOrderEmail();
            $emailSent = true;
        }

        $order->addStatusToHistory( $module->getOrderStatus(), 'The User was Redirected to PayGate for Payment', $emailSent );

        try {
            $country_code2 = $order->getBillingAddress()->getCountry();
            $session->setData( 'REFERENCE', $order->getRealOrderId() );

            if ( $quoteId = $session->getQuoteId() ) {

                $quote = Mage::getModel( 'sales/quote' )->load( $quoteId );
                if ( $quote->getId() ) {
                    $quote->setIsActive( true )->save();
                    $session->setQuoteId( $quoteId );
                }
            }
            if ( $country_code2 == '' || $country_code2 == null ) {
                $country_code2 = $order->getShippingAddress()->getCountry();
            }
        } catch ( Exception $e ) {
            //Set default to South Africa and log error
            $country_code2 = 'ZA';
            error_log( $e->getMessage() );
        }

        $country_code3 = self::getCountryDetails( $country_code2 );

        $order->save();
        $orderId = $order->getIncrementId();

        $fields = array(
            'PAYGATE_ID'       => $module->getID(),
            'REFERENCE'        => $order->getRealOrderId(),
            'AMOUNT'           => number_format( $order->getGrandTotal(), 2, '', '' ),
            'CURRENCY'         => $order->getOrderCurrencyCode(),
            'RETURN_URL'       => $module->getReturnUrl() . '?gid=' . $order->getRealOrderId() . '&qid=' . $quoteId,
            'TRANSACTION_DATE' => date( 'Y-m-d H:i' ),
            'LOCALE'           => str_replace( '_', '-', Mage::app()->getLocale()->getLocaleCode() ),
            'COUNTRY'          => $country_code3,
            'EMAIL'            => $order->getCustomerEmail(),
            'NOTIFY_URL'       => $module->getNotifyUrl(),
            'USER2'            => $orderId,
            'USER3'            => 'magento-v' . Mage::getVersion() . ' - plugin-v1.0.8',
        );

        $fields['CHECKSUM'] = md5( implode( '', $fields ) . $module->getSecretKey() );

        $response = $this->curlPost( 'https://secure.paygate.co.za/payweb3/initiate.trans', $fields );
        parse_str( $response, $fields );

        echo <<<HTML
<html>
<body>
    <form action="https://secure.paygate.co.za/payweb3/process.trans" method="post" name="redirect">
        <input name="PAY_REQUEST_ID" type="hidden" value="{$fields['PAY_REQUEST_ID']}" />
        <input name="CHECKSUM" type="hidden" value="{$fields['CHECKSUM']}" />
    </form>
    <script>document.forms['redirect'].submit();</script>
</body>
</html>
HTML;

    }

    public function returnAction()
    {
        if ( isset( $_POST['TRANSACTION_STATUS'] ) ) {

            $session = Mage::getSingleton( 'checkout/session' );

            $status = filter_var( $_POST['TRANSACTION_STATUS'], FILTER_SANITIZE_STRING );

            /**
             * @var $module PayGate_Pay_Model_Functions
             */
            $module = Mage::getModel( 'pay/functions' );
            $key    = $module->getSecretKey();

            $orderId = filter_var( $_GET['gid'], FILTER_SANITIZE_STRING );
            $quoteId = filter_var( $_GET['qid'], FILTER_SANITIZE_STRING );

            $queryFields = [
                'PAYGATE_ID'     => $module->getID(),
                'PAY_REQUEST_ID' => filter_var( $_POST['PAY_REQUEST_ID'], FILTER_SANITIZE_STRING ),
                'REFERENCE'      => $orderId,
            ];
            $queryFields['CHECKSUM'] = md5( implode( '', $queryFields ) . $key );
            $response                = $this->curlPost( 'https://secure.paygate.co.za/payweb3/query.trans', $queryFields );
            parse_str( $response, $queryFields );

            if ( count( $queryFields ) > 0 ) {
                $orderId = filter_var( $queryFields['USER2'], FILTER_SANITIZE_STRING );
                switch ( $status ) {
                    case 1:
                        $this->successful( $orderId, $quoteId );
                        break;
                    case 2:
                        $this->failed( $orderId, $quoteId );
                        break;
                    case 0:
                    case 4:
                        $this->canceled( $orderId, $quoteId );
                        break;
                    default:
                        header( 'Location:' . Mage::getUrl() );
                        $urll = Mage::getUrl( 'checkout/cart' );
                        echo <<<HTML
<html>
<body>
    <script>window.location='$urll';</script>
</body>
</html>
HTML;
                        break;
                }
            } else {
                header( 'Location:' . Mage::getUrl() );
                $urll = Mage::getUrl( 'checkout/cart' );
                echo <<<HTML
<html>
<body>
    <script>window.location='$urll';</script>
</body>
</html>
HTML;
            }
        }
    }

    public function canceled( $orderId, $quoteId = false )
    {
        /**
         * @var $order Mage_Sales_Model_Order
         */
        $order = Mage::getModel( 'sales/order' )->loadByIncrementId( $orderId );
        $order->cancel();
        $order->setState( Mage_Sales_Model_Order::STATE_CANCELED, true, 'Redirect Response: The User Canceled Payment with PayGate' );
        $order->setStatus( 'canceled' );
        $order->addStatusToHistory( Mage_Sales_Model_Order::STATE_CANCELED, 'Redirect Response: The User Canceled Payment with PayGate' );
        $order->save();

        $url = Mage::getUrl( 'checkout/cart' );
        /**
         * @var $session Mage_Checkout_Model_Session
         */
        $session = Mage::getSingleton( 'checkout/session' );

        if ( $quoteId ) {
            /**
             * @var $quote Mage_Sales_Model_Quote
             */
            $quote = Mage::getModel( 'sales/quote' )->load( $quoteId );

            if ( $quote->getId() ) {
                $quote->setIsActive( true )->save();
                $session->setQuoteId( $quoteId );
            }
        }

        $this->_redirect( 'checkout/cart' );

        echo <<<HTML
<html>
<body>
    <script>window.location='$url';</script>
</body>
</html>
HTML;
        die;
    }

    public function successful( $orderId, $quoteId = false )
    {
        /**
         * @var $module PayGate_Pay_Model_Functions
         */
        $module = Mage::getModel( 'pay/functions' );

        /**
         * @var $order Mage_Sales_Model_Order
         */
        $order = Mage::getModel( 'sales/order' )->loadByIncrementId( $orderId );

        $order->setState( Mage_Sales_Model_Order::STATE_PROCESSING )->save();

        $payment = $order->getPayment();
        $payment->save();

        /**
         * @var $invoice Mage_Sales_Model_Order_Invoice
         */
        $invoice = $order->prepareInvoice();
        $invoice->register()->capture();
        $invoice->save();
        $order->save();

        Mage::getModel( 'core/resource_transaction' )
            ->addObject( $invoice )
            ->addObject( $invoice->getOrder() )
            ->save();

        if ( $module->getSendInvoiceEmail() ) {
            $message = Mage::helper( 'paygate' )->__( 'Notified customer about invoice #%s.', $invoice->getIncrementId() );
            $comment = $order->sendNewOrderEmail()->addStatusHistoryComment( $message )
                ->setIsCustomerNotified( true )
                ->save();
            $invoice->sendEmail();
        } else {
            $comment = $order->save();
        }
        $this->clearCart();

        $checkoutSession = Mage::getSingleton( 'checkout/type_onepage' )->getCheckout();
        $checkoutSession->setLastSuccessQuoteId( $quoteId );
        $checkoutSession->setLastQuoteId( $quoteId );
        $checkoutSession->setLastOrderId( $order->getId() );
        $checkoutSession->setLastRealOrderId( $orderId );

        $this->_redirect('checkout/onepage/success');
    }

    public function clearCart()
    {
        Mage::getSingleton( 'checkout/session' )->clear();
        foreach ( Mage::getSingleton( 'checkout/session' )->getQuote()->getItemsCollection() as $item ) {
            Mage::getSingleton( 'checkout/cart' )->removeItem( $item->getId() )->save();
        }
    }

    public function failed( $orderId, $quoteId = false )
    {
        /**
         * @var $order Mage_Sales_Model_Order
         */
        $order = Mage::getModel( 'sales/order' )->loadByIncrementId( $orderId );
        $order->cancel();
        $order->setState( Mage_Sales_Model_Order::STATE_CANCELED, true, 'Redirect Response: The User Canceled Payment with PayGate' );
        $order->setStatus( 'canceled' );
        $order->addStatusToHistory( Mage_Sales_Model_Order::STATE_CANCELED, 'Redirect Response: The User Failed to make Payment with PayGate due to transaction been declined' );
        $order->save();

        /**
         * @var $session Mage_Checkout_Model_Session
         */
        $session = Mage::getSingleton( 'checkout/session' );

        if ( $quoteId ) {
            /**
             * @var $quote Mage_Sales_Model_Quote
             */
            $quote = Mage::getModel( 'sales/quote' )->load( $quoteId );
            if ( $quote->getId() ) {
                $quote->setIsActive( true )->save();
                $session->setQuoteId( $quoteId );
            }
        }

        $url = Mage::getUrl( 'checkout/onepage/failure' );
        echo <<<HTML
<html>
<body>
    <script>window.location='$url';</script>
</body>
</html>
HTML;
        die;
    }

    public function notifyAction()
    {
        // Notify paygate
        echo 'OK';
        $errors      = false;
        $notify_data = array();
        $post_data   = '';
        $order       = '';

        // Get notify data
        $paygate_data = $this->getPostData();
        if ( $paygate_data === false ) {
            $errors = true;
        }

        /**
         * @var $module PayGate_Pay_Model_Functions
         */
        $module = Mage::getModel( 'pay/functions' );

        // Build security signature
        $checkSumParams = '';
        if ( !$errors ) {
            foreach ( $paygate_data as $key => $val ) {
                $post_data .= $key . '=' . $val . "\n";
                $notify_data[$key] = stripslashes( $val );

                if ( $key == 'PAYGATE_ID' ) {
                    $checkSumParams .= $val;
                }
                if ( $key != 'CHECKSUM' && $key != 'PAYGATE_ID' ) {
                    $checkSumParams .= $val;
                }

                if ( sizeof( $notify_data ) == 0 ) {
                    $errors = true;
                    error_log( 'PayGate Notification Data Invalid' );
                }
            }

            $checkSumParams .= $module->getSecretKey();
        }

        // Verify security signature
        if ( !$errors ) {
            $checkSumParams = md5( $checkSumParams );
            if ( $checkSumParams != $paygate_data['CHECKSUM'] ) {
                error_log( 'Notification checksum mismatch' );
                $errors = true;
            }
        }

        if ( !$errors ) {
            // Load order
            $orderId = $paygate_data['CUST2'];
            /**
             * @var $order Mage_Sales_Model_Order
             */
            $order = Mage::getModel( 'sales/order' )->loadByIncrementId( $orderId );
            if ( strtolower( $order->getStatus() ) != 'pending' ) {
                error_log( 'Trying to update order not in a pending state' );
                $errors = true;
            }
        }

        if ( !$errors ) {
            $status = $paygate_data['TRANSACTION_STATUS'];
            if ( $status == 0 || $status == 4 ) {
                $order->cancel();
                $order->setState( Mage_Sales_Model_Order::STATE_CANCELED, true, 'Redirect Response: The User Canceled Payment with PayGate' );
                $order->setStatus( 'canceled' );

                if ( $module->getOrderFailedEmail() ? true : false ) {
                    $order->sendOrderUpdateEmail( true, 'Cancelled Payment with PayGate' );
                }
            } elseif ( $status == 1 ) {
                /**
                 * @var $invoice Mage_Sales_Model_Order_Invoice
                 */
                $invoice = $order->prepareInvoice();
                $invoice->setState( Mage_Sales_Model_Order_Invoice::STATE_PAID );
                $invoice->register()->capture();
                $order->addRelatedObject( $invoice );
                $order->setState( Mage_Sales_Model_Order::STATE_PROCESSING, 'Processing', 'Completed Payment with PayGate', true );

                if ( $module->getOrderSuccessfulEmail() ? true : false ) {
                    $order->sendOrderUpdateEmail( true, 'Completed Payment with PayGate' );
                }
                $this->clearCart();
            } elseif ( $status == 2 ) {
                $order->cancel();
                $order->setState( Mage_Sales_Model_Order::STATE_CANCELED, true, 'Redirect Response: The User Canceled Payment with PayGate' );
                $order->setStatus( 'canceled' );

                if ( $module->getOrderFailedEmail() ? true : false ) {
                    $order->sendOrderUpdateEmail( true, 'Failed to make Payment with PayGate due to transaction declined' );
                }
            }
            $order->save();
        }
    }

    public function curlPost( $url, $fields )
    {
        $curl = curl_init( $url );
        curl_setopt( $curl, CURLOPT_POST, count( $fields ) );
        curl_setopt( $curl, CURLOPT_POSTFIELDS, $fields );
        curl_setopt( $curl, CURLOPT_RETURNTRANSFER, 1 );
        $response = curl_exec( $curl );
        curl_close( $curl );

        return $response;
    }

    public function getPostData()
    {
        // Posted variables from ITN
        $nData = $_POST;

        // Strip any slashes in data
        foreach ( $nData as $key => $val ) {
            $nData[$key] = stripslashes( $val );
        }

        // Return "false" if no data was received
        if ( sizeof( $nData ) == 0 ) {
            return ( false );
        } else {
            return ( $nData );
        }
    }

    public static function getCountryDetails( $code2 )
    {

        $countries = array(
            "AF" => array( "AFGHANISTAN", "AF", "AFG", "004" ),
            "AL" => array( "ALBANIA", "AL", "ALB", "008" ),
            "DZ" => array( "ALGERIA", "DZ", "DZA", "012" ),
            "AS" => array( "AMERICAN SAMOA", "AS", "ASM", "016" ),
            "AD" => array( "ANDORRA", "AD", "AND", "020" ),
            "AO" => array( "ANGOLA", "AO", "AGO", "024" ),
            "AI" => array( "ANGUILLA", "AI", "AIA", "660" ),
            "AQ" => array( "ANTARCTICA", "AQ", "ATA", "010" ),
            "AG" => array( "ANTIGUA AND BARBUDA", "AG", "ATG", "028" ),
            "AR" => array( "ARGENTINA", "AR", "ARG", "032" ),
            "AM" => array( "ARMENIA", "AM", "ARM", "051" ),
            "AW" => array( "ARUBA", "AW", "ABW", "533" ),
            "AU" => array( "AUSTRALIA", "AU", "AUS", "036" ),
            "AT" => array( "AUSTRIA", "AT", "AUT", "040" ),
            "AZ" => array( "AZERBAIJAN", "AZ", "AZE", "031" ),
            "BS" => array( "BAHAMAS", "BS", "BHS", "044" ),
            "BH" => array( "BAHRAIN", "BH", "BHR", "048" ),
            "BD" => array( "BANGLADESH", "BD", "BGD", "050" ),
            "BB" => array( "BARBADOS", "BB", "BRB", "052" ),
            "BY" => array( "BELARUS", "BY", "BLR", "112" ),
            "BE" => array( "BELGIUM", "BE", "BEL", "056" ),
            "BZ" => array( "BELIZE", "BZ", "BLZ", "084" ),
            "BJ" => array( "BENIN", "BJ", "BEN", "204" ),
            "BM" => array( "BERMUDA", "BM", "BMU", "060" ),
            "BT" => array( "BHUTAN", "BT", "BTN", "064" ),
            "BO" => array( "BOLIVIA", "BO", "BOL", "068" ),
            "BA" => array( "BOSNIA AND HERZEGOVINA", "BA", "BIH", "070" ),
            "BW" => array( "BOTSWANA", "BW", "BWA", "072" ),
            "BV" => array( "BOUVET ISLAND", "BV", "BVT", "074" ),
            "BR" => array( "BRAZIL", "BR", "BRA", "076" ),
            "IO" => array( "BRITISH INDIAN OCEAN TERRITORY", "IO", "IOT", "086" ),
            "BN" => array( "BRUNEI DARUSSALAM", "BN", "BRN", "096" ),
            "BG" => array( "BULGARIA", "BG", "BGR", "100" ),
            "BF" => array( "BURKINA FASO", "BF", "BFA", "854" ),
            "BI" => array( "BURUNDI", "BI", "BDI", "108" ),
            "KH" => array( "CAMBODIA", "KH", "KHM", "116" ),
            "CM" => array( "CAMEROON", "CM", "CMR", "120" ),
            "CA" => array( "CANADA", "CA", "CAN", "124" ),
            "CV" => array( "CAPE VERDE", "CV", "CPV", "132" ),
            "KY" => array( "CAYMAN ISLANDS", "KY", "CYM", "136" ),
            "CF" => array( "CENTRAL AFRICAN REPUBLIC", "CF", "CAF", "140" ),
            "TD" => array( "CHAD", "TD", "TCD", "148" ),
            "CL" => array( "CHILE", "CL", "CHL", "152" ),
            "CN" => array( "CHINA", "CN", "CHN", "156" ),
            "CX" => array( "CHRISTMAS ISLAND", "CX", "CXR", "162" ),
            "CC" => array( "COCOS (KEELING) ISLANDS", "CC", "CCK", "166" ),
            "CO" => array( "COLOMBIA", "CO", "COL", "170" ),
            "KM" => array( "COMOROS", "KM", "COM", "174" ),
            "CG" => array( "CONGO", "CG", "COG", "178" ),
            "CK" => array( "COOK ISLANDS", "CK", "COK", "184" ),
            "CR" => array( "COSTA RICA", "CR", "CRI", "188" ),
            "CI" => array( "COTE D'IVOIRE", "CI", "CIV", "384" ),
            "HR" => array( "CROATIA (local name: Hrvatska)", "HR", "HRV", "191" ),
            "CU" => array( "CUBA", "CU", "CUB", "192" ),
            "CY" => array( "CYPRUS", "CY", "CYP", "196" ),
            "CZ" => array( "CZECH REPUBLIC", "CZ", "CZE", "203" ),
            "DK" => array( "DENMARK", "DK", "DNK", "208" ),
            "DJ" => array( "DJIBOUTI", "DJ", "DJI", "262" ),
            "DM" => array( "DOMINICA", "DM", "DMA", "212" ),
            "DO" => array( "DOMINICAN REPUBLIC", "DO", "DOM", "214" ),
            "TL" => array( "EAST TIMOR", "TL", "TLS", "626" ),
            "EC" => array( "ECUADOR", "EC", "ECU", "218" ),
            "EG" => array( "EGYPT", "EG", "EGY", "818" ),
            "SV" => array( "EL SALVADOR", "SV", "SLV", "222" ),
            "GQ" => array( "EQUATORIAL GUINEA", "GQ", "GNQ", "226" ),
            "ER" => array( "ERITREA", "ER", "ERI", "232" ),
            "EE" => array( "ESTONIA", "EE", "EST", "233" ),
            "ET" => array( "ETHIOPIA", "ET", "ETH", "210" ),
            "FK" => array( "FALKLAND ISLANDS (MALVINAS)", "FK", "FLK", "238" ),
            "FO" => array( "FAROE ISLANDS", "FO", "FRO", "234" ),
            "FJ" => array( "FIJI", "FJ", "FJI", "242" ),
            "FI" => array( "FINLAND", "FI", "FIN", "246" ),
            "FR" => array( "FRANCE", "FR", "FRA", "250" ),
            "FX" => array( "FRANCE, METROPOLITAN", "FX", "FXX", "249" ),
            "GF" => array( "FRENCH GUIANA", "GF", "GUF", "254" ),
            "PF" => array( "FRENCH POLYNESIA", "PF", "PYF", "258" ),
            "TF" => array( "FRENCH SOUTHERN TERRITORIES", "TF", "ATF", "260" ),
            "GA" => array( "GABON", "GA", "GAB", "266" ),
            "GM" => array( "GAMBIA", "GM", "GMB", "270" ),
            "GE" => array( "GEORGIA", "GE", "GEO", "268" ),
            "DE" => array( "GERMANY", "DE", "DEU", "276" ),
            "GH" => array( "GHANA", "GH", "GHA", "288" ),
            "GI" => array( "GIBRALTAR", "GI", "GIB", "292" ),
            "GR" => array( "GREECE", "GR", "GRC", "300" ),
            "GL" => array( "GREENLAND", "GL", "GRL", "304" ),
            "GD" => array( "GRENADA", "GD", "GRD", "308" ),
            "GP" => array( "GUADELOUPE", "GP", "GLP", "312" ),
            "GU" => array( "GUAM", "GU", "GUM", "316" ),
            "GT" => array( "GUATEMALA", "GT", "GTM", "320" ),
            "GN" => array( "GUINEA", "GN", "GIN", "324" ),
            "GW" => array( "GUINEA-BISSAU", "GW", "GNB", "624" ),
            "GY" => array( "GUYANA", "GY", "GUY", "328" ),
            "HT" => array( "HAITI", "HT", "HTI", "332" ),
            "HM" => array( "HEARD ISLAND & MCDONALD ISLANDS", "HM", "HMD", "334" ),
            "HN" => array( "HONDURAS", "HN", "HND", "340" ),
            "HK" => array( "HONG KONG", "HK", "HKG", "344" ),
            "HU" => array( "HUNGARY", "HU", "HUN", "348" ),
            "IS" => array( "ICELAND", "IS", "ISL", "352" ),
            "IN" => array( "INDIA", "IN", "IND", "356" ),
            "ID" => array( "INDONESIA", "ID", "IDN", "360" ),
            "IR" => array( "IRAN, ISLAMIC REPUBLIC OF", "IR", "IRN", "364" ),
            "IQ" => array( "IRAQ", "IQ", "IRQ", "368" ),
            "IE" => array( "IRELAND", "IE", "IRL", "372" ),
            "IL" => array( "ISRAEL", "IL", "ISR", "376" ),
            "IT" => array( "ITALY", "IT", "ITA", "380" ),
            "JM" => array( "JAMAICA", "JM", "JAM", "388" ),
            "JP" => array( "JAPAN", "JP", "JPN", "392" ),
            "JO" => array( "JORDAN", "JO", "JOR", "400" ),
            "KZ" => array( "KAZAKHSTAN", "KZ", "KAZ", "398" ),
            "KE" => array( "KENYA", "KE", "KEN", "404" ),
            "KI" => array( "KIRIBATI", "KI", "KIR", "296" ),
            "KP" => array( "KOREA, DEMOCRATIC PEOPLE'S REPUBLIC OF", "KP", "PRK", "408" ),
            "KR" => array( "KOREA, REPUBLIC OF", "KR", "KOR", "410" ),
            "KW" => array( "KUWAIT", "KW", "KWT", "414" ),
            "KG" => array( "KYRGYZSTAN", "KG", "KGZ", "417" ),
            "LA" => array( "LAO PEOPLE'S DEMOCRATIC REPUBLIC", "LA", "LAO", "418" ),
            "LV" => array( "LATVIA", "LV", "LVA", "428" ),
            "LB" => array( "LEBANON", "LB", "LBN", "422" ),
            "LS" => array( "LESOTHO", "LS", "LSO", "426" ),
            "LR" => array( "LIBERIA", "LR", "LBR", "430" ),
            "LY" => array( "LIBYAN ARAB JAMAHIRIYA", "LY", "LBY", "434" ),
            "LI" => array( "LIECHTENSTEIN", "LI", "LIE", "438" ),
            "LT" => array( "LITHUANIA", "LT", "LTU", "440" ),
            "LU" => array( "LUXEMBOURG", "LU", "LUX", "442" ),
            "MO" => array( "MACAU", "MO", "MAC", "446" ),
            "MK" => array( "MACEDONIA, THE FORMER YUGOSLAV REPUBLIC OF", "MK", "MKD", "807" ),
            "MG" => array( "MADAGASCAR", "MG", "MDG", "450" ),
            "MW" => array( "MALAWI", "MW", "MWI", "454" ),
            "MY" => array( "MALAYSIA", "MY", "MYS", "458" ),
            "MV" => array( "MALDIVES", "MV", "MDV", "462" ),
            "ML" => array( "MALI", "ML", "MLI", "466" ),
            "MT" => array( "MALTA", "MT", "MLT", "470" ),
            "MH" => array( "MARSHALL ISLANDS", "MH", "MHL", "584" ),
            "MQ" => array( "MARTINIQUE", "MQ", "MTQ", "474" ),
            "MR" => array( "MAURITANIA", "MR", "MRT", "478" ),
            "MU" => array( "MAURITIUS", "MU", "MUS", "480" ),
            "YT" => array( "MAYOTTE", "YT", "MYT", "175" ),
            "MX" => array( "MEXICO", "MX", "MEX", "484" ),
            "FM" => array( "MICRONESIA, FEDERATED STATES OF", "FM", "FSM", "583" ),
            "MD" => array( "MOLDOVA, REPUBLIC OF", "MD", "MDA", "498" ),
            "MC" => array( "MONACO", "MC", "MCO", "492" ),
            "MN" => array( "MONGOLIA", "MN", "MNG", "496" ),
            "MS" => array( "MONTSERRAT", "MS", "MSR", "500" ),
            "MA" => array( "MOROCCO", "MA", "MAR", "504" ),
            "MZ" => array( "MOZAMBIQUE", "MZ", "MOZ", "508" ),
            "MM" => array( "MYANMAR", "MM", "MMR", "104" ),
            "NA" => array( "NAMIBIA", "NA", "NAM", "516" ),
            "NR" => array( "NAURU", "NR", "NRU", "520" ),
            "NP" => array( "NEPAL", "NP", "NPL", "524" ),
            "NL" => array( "NETHERLANDS", "NL", "NLD", "528" ),
            "AN" => array( "NETHERLANDS ANTILLES", "AN", "ANT", "530" ),
            "NC" => array( "NEW CALEDONIA", "NC", "NCL", "540" ),
            "NZ" => array( "NEW ZEALAND", "NZ", "NZL", "554" ),
            "NI" => array( "NICARAGUA", "NI", "NIC", "558" ),
            "NE" => array( "NIGER", "NE", "NER", "562" ),
            "NG" => array( "NIGERIA", "NG", "NGA", "566" ),
            "NU" => array( "NIUE", "NU", "NIU", "570" ),
            "NF" => array( "NORFOLK ISLAND", "NF", "NFK", "574" ),
            "MP" => array( "NORTHERN MARIANA ISLANDS", "MP", "MNP", "580" ),
            "NO" => array( "NORWAY", "NO", "NOR", "578" ),
            "OM" => array( "OMAN", "OM", "OMN", "512" ),
            "PK" => array( "PAKISTAN", "PK", "PAK", "586" ),
            "PW" => array( "PALAU", "PW", "PLW", "585" ),
            "PA" => array( "PANAMA", "PA", "PAN", "591" ),
            "PG" => array( "PAPUA NEW GUINEA", "PG", "PNG", "598" ),
            "PY" => array( "PARAGUAY", "PY", "PRY", "600" ),
            "PE" => array( "PERU", "PE", "PER", "604" ),
            "PH" => array( "PHILIPPINES", "PH", "PHL", "608" ),
            "PN" => array( "PITCAIRN", "PN", "PCN", "612" ),
            "PL" => array( "POLAND", "PL", "POL", "616" ),
            "PT" => array( "PORTUGAL", "PT", "PRT", "620" ),
            "PR" => array( "PUERTO RICO", "PR", "PRI", "630" ),
            "QA" => array( "QATAR", "QA", "QAT", "634" ),
            "RE" => array( "REUNION", "RE", "REU", "638" ),
            "RO" => array( "ROMANIA", "RO", "ROU", "642" ),
            "RU" => array( "RUSSIAN FEDERATION", "RU", "RUS", "643" ),
            "RW" => array( "RWANDA", "RW", "RWA", "646" ),
            "KN" => array( "SAINT KITTS AND NEVIS", "KN", "KNA", "659" ),
            "LC" => array( "SAINT LUCIA", "LC", "LCA", "662" ),
            "VC" => array( "SAINT VINCENT AND THE GRENADINES", "VC", "VCT", "670" ),
            "WS" => array( "SAMOA", "WS", "WSM", "882" ),
            "SM" => array( "SAN MARINO", "SM", "SMR", "674" ),
            "ST" => array( "SAO TOME AND PRINCIPE", "ST", "STP", "678" ),
            "SA" => array( "SAUDI ARABIA", "SA", "SAU", "682" ),
            "SN" => array( "SENEGAL", "SN", "SEN", "686" ),
            "RS" => array( "SERBIA", "RS", "SRB", "688" ),
            "SC" => array( "SEYCHELLES", "SC", "SYC", "690" ),
            "SL" => array( "SIERRA LEONE", "SL", "SLE", "694" ),
            "SG" => array( "SINGAPORE", "SG", "SGP", "702" ),
            "SK" => array( "SLOVAKIA (Slovak Republic)", "SK", "SVK", "703" ),
            "SI" => array( "SLOVENIA", "SI", "SVN", "705" ),
            "SB" => array( "SOLOMON ISLANDS", "SB", "SLB", "90" ),
            "SO" => array( "SOMALIA", "SO", "SOM", "706" ),
            "ZA" => array( "SOUTH AFRICA", "ZA", "ZAF", "710" ),
            "ES" => array( "SPAIN", "ES", "ESP", "724" ),
            "LK" => array( "SRI LANKA", "LK", "LKA", "144" ),
            "SH" => array( "SAINT HELENA", "SH", "SHN", "654" ),
            "PM" => array( "SAINT PIERRE AND MIQUELON", "PM", "SPM", "666" ),
            "SD" => array( "SUDAN", "SD", "SDN", "736" ),
            "SR" => array( "SURINAME", "SR", "SUR", "740" ),
            "SJ" => array( "SVALBARD AND JAN MAYEN ISLANDS", "SJ", "SJM", "744" ),
            "SZ" => array( "SWAZILAND", "SZ", "SWZ", "748" ),
            "SE" => array( "SWEDEN", "SE", "SWE", "752" ),
            "CH" => array( "SWITZERLAND", "CH", "CHE", "756" ),
            "SY" => array( "SYRIAN ARAB REPUBLIC", "SY", "SYR", "760" ),
            "TW" => array( "TAIWAN, PROVINCE OF CHINA", "TW", "TWN", "158" ),
            "TJ" => array( "TAJIKISTAN", "TJ", "TJK", "762" ),
            "TZ" => array( "TANZANIA, UNITED REPUBLIC OF", "TZ", "TZA", "834" ),
            "TH" => array( "THAILAND", "TH", "THA", "764" ),
            "TG" => array( "TOGO", "TG", "TGO", "768" ),
            "TK" => array( "TOKELAU", "TK", "TKL", "772" ),
            "TO" => array( "TONGA", "TO", "TON", "776" ),
            "TT" => array( "TRINIDAD AND TOBAGO", "TT", "TTO", "780" ),
            "TN" => array( "TUNISIA", "TN", "TUN", "788" ),
            "TR" => array( "TURKEY", "TR", "TUR", "792" ),
            "TM" => array( "TURKMENISTAN", "TM", "TKM", "795" ),
            "TC" => array( "TURKS AND CAICOS ISLANDS", "TC", "TCA", "796" ),
            "TV" => array( "TUVALU", "TV", "TUV", "798" ),
            "UG" => array( "UGANDA", "UG", "UGA", "800" ),
            "UA" => array( "UKRAINE", "UA", "UKR", "804" ),
            "AE" => array( "UNITED ARAB EMIRATES", "AE", "ARE", "784" ),
            "GB" => array( "UNITED KINGDOM", "GB", "GBR", "826" ),
            "US" => array( "UNITED STATES", "US", "USA", "840" ),
            "UM" => array( "UNITED STATES MINOR OUTLYING ISLANDS", "UM", "UMI", "581" ),
            "UY" => array( "URUGUAY", "UY", "URY", "858" ),
            "UZ" => array( "UZBEKISTAN", "UZ", "UZB", "860" ),
            "VU" => array( "VANUATU", "VU", "VUT", "548" ),
            "VA" => array( "VATICAN CITY STATE (HOLY SEE)", "VA", "VAT", "336" ),
            "VE" => array( "VENEZUELA", "VE", "VEN", "862" ),
            "VN" => array( "VIET NAM", "VN", "VNM", "704" ),
            "VG" => array( "VIRGIN ISLANDS (BRITISH)", "VG", "VGB", "92" ),
            "VI" => array( "VIRGIN ISLANDS (U.S.)", "VI", "VIR", "850" ),
            "WF" => array( "WALLIS AND FUTUNA ISLANDS", "WF", "WLF", "876" ),
            "EH" => array( "WESTERN SAHARA", "EH", "ESH", "732" ),
            "YE" => array( "YEMEN", "YE", "YEM", "887" ),
            "YU" => array( "YUGOSLAVIA", "YU", "YUG", "891" ),
            "ZR" => array( "ZAIRE", "ZR", "ZAR", "180" ),
            "ZM" => array( "ZAMBIA", "ZM", "ZMB", "894" ),
            "ZW" => array( "ZIMBABWE", "ZW", "ZWE", "716" ),
        );

        foreach ( $countries as $key => $val ) {
            if ( $key == $code2 ) {
                return $val[2];
            }
        }

        return false;
    }
}
